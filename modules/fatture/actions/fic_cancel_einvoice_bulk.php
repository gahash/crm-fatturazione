<?php
require __DIR__ . '/../../../auth.php';
require_login();
if (!has_admin_access()) {
    http_response_code(403);
    die('Accesso non autorizzato.');
}

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_audit.php';

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim((string)($appConfig['app']['base_url'] ?? 'http://localhost:8000'), '/');

function fic_cancel_bulk_return_url(string $baseUrl, array $params = []): string
{
    $url = $baseUrl . '/modules/fatture/index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

function fic_cancel_bulk_error_snippet(array $response, int $maxLen = 220): string
{
    $jsonMsg = trim((string)($response['json']['error']['message'] ?? ''));
    if ($jsonMsg !== '') {
        return substr($jsonMsg, 0, $maxLen);
    }
    $body = trim((string)($response['body'] ?? ''));
    if ($body === '') {
        return 'Errore API FIC senza dettagli';
    }
    $body = preg_replace('/\s+/', ' ', $body);
    return substr((string)$body, 0, $maxLen);
}

function fic_cancel_bulk_collect_ids(PDO $pdo): array
{
    $scope = trim((string)($_REQUEST['scope'] ?? 'selected'));
    if ($scope === 'filtered') {
        $q = trim((string)($_REQUEST['q'] ?? ''));
        $sdiFlow = trim((string)($_REQUEST['sdi_flow'] ?? ''));

        $sql = "
            SELECT DISTINCT f.id
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
              AND f.fic_document_id IS NOT NULL
              AND TRIM(f.fic_document_id) <> ''
        ";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (f.numero LIKE :q_num OR c.nome LIKE :q_name OR c.email LIKE :q_email)";
            $params[':q_num'] = '%' . $q . '%';
            $params[':q_name'] = '%' . $q . '%';
            $params[':q_email'] = '%' . $q . '%';
        }

        if ($sdiFlow === 'ready') {
            $sql .= " AND (f.ei_status IS NULL OR TRIM(f.ei_status) = '' OR LOWER(f.ei_status) NOT IN ('sent','processing','accepted'))";
        } elseif ($sdiFlow === 'sent') {
            $sql .= " AND LOWER(COALESCE(f.ei_status, '')) IN ('sent','processing','accepted')";
        } elseif ($sdiFlow === 'issues') {
            $sql .= " AND (LOWER(COALESCE(f.ei_status, '')) = 'error' OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved' OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> ''))";
        }

        $sql .= " ORDER BY f.id ASC";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): int {
            return (int)($r['id'] ?? 0);
        }, $rows);
    }

    $ids = $_POST['ids'] ?? $_REQUEST['ids'] ?? [];
    if (!is_array($ids)) {
        return [];
    }
    $out = [];
    foreach ($ids as $id) {
        $intId = (int)$id;
        if ($intId > 0) {
            $out[] = $intId;
        }
    }
    return array_values(array_unique($out));
}

try {
    $companyId = (string)fic_get_company_id();
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $ids = fic_cancel_bulk_collect_ids($pdo);
    if (!$ids) {
        header('Location: ' . fic_cancel_bulk_return_url($baseUrl, [
            'ei_cancel_bulk_error' => 'Nessuna fattura selezionata o nessuna fattura nel filtro',
        ]));
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sel = $pdo->prepare("
        SELECT id, numero, fic_document_id, ei_status
        FROM fatture
        WHERE id IN ({$placeholders})
          AND document_type = 'invoice'
    ");
    $sel->execute($ids);
    $fatture = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deleted = 0;
    $missing = 0;
    $failed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($fatture as $fattura) {
        $id = (int)($fattura['id'] ?? 0);
        $docId = trim((string)($fattura['fic_document_id'] ?? ''));
        if ($id <= 0 || $docId === '') {
            $skipped++;
            continue;
        }

        try {
            $deleteResponse = fic_request('DELETE', "/c/{$companyId}/issued_documents/{$docId}");
            $deleteStatus = (int)($deleteResponse['status'] ?? 0);
            $alreadyMissing = ($deleteStatus === 404);
            if (!$alreadyMissing && ($deleteStatus < 200 || $deleteStatus >= 300)) {
                throw new RuntimeException('HTTP ' . $deleteStatus . ': ' . fic_cancel_bulk_error_snippet($deleteResponse));
            }

            $upd = $pdo->prepare("
                UPDATE fatture
                SET fic_document_id = NULL,
                    fic_status = NULL,
                    fic_pdf_url = NULL,
                    fic_synced_at = NULL,
                    ei_status = NULL,
                    ei_error = NULL,
                    ei_sent_at = NULL
                WHERE id = :id
            ");
            $upd->execute([':id' => $id]);

            if ($alreadyMissing) {
                $missing++;
            } else {
                $deleted++;
            }

            fatture_log($pdo, $id, 'cancel_einvoice_bulk', 'ok', $alreadyMissing
                ? 'Bulk annullamento e-fattura: documento gia assente su FIC, collegamento locale azzerato.'
                : 'Bulk annullamento e-fattura: documento cancellato da FIC e tracking SDI azzerato.', [
                'fic_document_id' => $docId,
                'ei_status_before' => (string)($fattura['ei_status'] ?? ''),
            ]);
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 6) {
                $errors[] = '#' . $id . ' ' . substr((string)$e->getMessage(), 0, 120);
            }
            fatture_log($pdo, $id, 'cancel_einvoice_bulk', 'error', 'Errore annullamento bulk e-fattura: ' . substr((string)$e->getMessage(), 0, 180), [
                'fic_document_id' => $docId,
                'ei_status_before' => (string)($fattura['ei_status'] ?? ''),
            ]);
        }

        usleep(120000);
    }

    $params = [
        'ei_cancel_bulk_done' => '1',
        'ei_cancel_bulk_deleted' => $deleted,
        'ei_cancel_bulk_missing' => $missing,
        'ei_cancel_bulk_failed' => $failed,
        'ei_cancel_bulk_skipped' => $skipped,
    ];
    if ($errors) {
        $params['ei_cancel_bulk_errors'] = implode(' | ', $errors);
    }
    if (isset($_REQUEST['q'])) {
        $params['q'] = (string)$_REQUEST['q'];
    }
    if (isset($_REQUEST['sdi_flow'])) {
        $params['sdi_flow'] = (string)$_REQUEST['sdi_flow'];
    }

    header('Location: ' . fic_cancel_bulk_return_url($baseUrl, $params));
    exit;
} catch (Throwable $e) {
    header('Location: ' . fic_cancel_bulk_return_url($baseUrl, [
        'ei_cancel_bulk_error' => substr((string)$e->getMessage(), 0, 180),
    ]));
    exit;
}

