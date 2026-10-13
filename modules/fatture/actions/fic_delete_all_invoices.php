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

function fic_delete_all_build_return_url(string $baseUrl, array $params = []): string
{
    $url = $baseUrl . '/modules/fatture/index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

function fic_delete_all_error_snippet(array $response, int $maxLen = 220): string
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

function fic_delete_all_extract_documents(array $response): array
{
    if (empty($response['json']) || !is_array($response['json'])) {
        return [];
    }

    $data = $response['json']['data'] ?? null;
    if (is_array($data)) {
        if (isset($data['items_list']) && is_array($data['items_list'])) {
            return $data['items_list'];
        }
        if (array_keys($data) === range(0, count($data) - 1)) {
            return $data;
        }
    }

    return [];
}

function fic_delete_all_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fic_delete_all_clear_local_links_for_doc_ids(PDO $pdo, array $docIds): int
{
    if (!$docIds) {
        return 0;
    }

    $docIds = array_values(array_unique(array_filter(array_map(static function ($v): string {
        return trim((string)$v);
    }, $docIds), static function (string $v): bool {
        return $v !== '';
    })));

    if (!$docIds) {
        return 0;
    }

    $cleared = 0;
    $chunks = array_chunk($docIds, 150);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
            UPDATE fatture
            SET fic_document_id = NULL,
                fic_status = NULL,
                fic_pdf_url = NULL,
                fic_synced_at = NULL,
                ei_status = NULL,
                ei_error = NULL,
                ei_sent_at = NULL
            WHERE document_type = 'invoice'
              AND fic_document_id IN ({$placeholders})
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);
        $cleared += (int)$stmt->rowCount();
    }

    return $cleared;
}

function fic_delete_all_clear_all_local_links(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        UPDATE fatture
        SET fic_document_id = NULL,
            fic_status = NULL,
            fic_pdf_url = NULL,
            fic_synced_at = NULL,
            ei_status = NULL,
            ei_error = NULL,
            ei_sent_at = NULL
        WHERE document_type = 'invoice'
          AND fic_document_id IS NOT NULL
          AND TRIM(fic_document_id) <> ''
    ");
    $stmt->execute();
    return (int)$stmt->rowCount();
}

function fic_delete_all_invalidate_sdi_cache(string $companyId): void
{
    $safeCompanyId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $companyId);
    $cacheFile = __DIR__ . '/../../../tmp/fic_sdi_sent_count_' . $safeCompanyId . '.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

$isAjax = ((string)($_REQUEST['ajax'] ?? '')) === '1';
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod !== 'POST') {
    if ($isAjax) {
        fic_delete_all_json_response(405, ['ok' => false, 'error' => 'Metodo non valido']);
    }
    header('Location: ' . fic_delete_all_build_return_url($baseUrl, [
        'fic_delete_all_error' => 'Metodo non valido',
    ]));
    exit;
}

$confirmText = trim((string)($_POST['confirm_text'] ?? ''));
if ($confirmText !== 'ELIMINA TUTTE LE FATTURE FIC') {
    if ($isAjax) {
        fic_delete_all_json_response(422, ['ok' => false, 'error' => 'Conferma testuale non valida']);
    }
    header('Location: ' . fic_delete_all_build_return_url($baseUrl, [
        'fic_delete_all_error' => 'Conferma testuale non valida',
    ]));
    exit;
}

try {
    $companyId = (string)fic_get_company_id();
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $chunk = (int)($_POST['chunk'] ?? 20);
    if ($chunk < 1) {
        $chunk = 1;
    }
    if ($chunk > 40) {
        $chunk = 40;
    }

    $query = http_build_query([
        'type' => 'invoice',
        'per_page' => $chunk,
        'page' => 1,
    ]);
    $listResp = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
    $listStatus = (int)($listResp['status'] ?? 0);
    if ($listStatus < 200 || $listStatus >= 300) {
        throw new RuntimeException('Elenco fatture FIC non disponibile (HTTP ' . $listStatus . ').');
    }

    $documents = fic_delete_all_extract_documents($listResp);
    $pagination = is_array($listResp['json']['pagination'] ?? null) ? $listResp['json']['pagination'] : [];
    $totalBefore = (int)($pagination['total'] ?? count($documents));

    $deleted = 0;
    $missing = 0;
    $failed = 0;
    $processed = 0;
    $deletedDocIds = [];
    $errors = [];

    foreach ($documents as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $docId = trim((string)($doc['id'] ?? ''));
        if ($docId === '') {
            continue;
        }
        $processed++;

        try {
            $delResp = fic_request('DELETE', "/c/{$companyId}/issued_documents/{$docId}");
            $delStatus = (int)($delResp['status'] ?? 0);
            if ($delStatus >= 200 && $delStatus < 300) {
                $deleted++;
                $deletedDocIds[] = $docId;
            } elseif ($delStatus === 404) {
                $missing++;
                $deletedDocIds[] = $docId;
            } else {
                $failed++;
                if (count($errors) < 6) {
                    $errors[] = 'Doc ' . $docId . ': HTTP ' . $delStatus . ' ' . fic_delete_all_error_snippet($delResp);
                }
            }
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 6) {
                $errors[] = 'Doc ' . $docId . ': ' . substr((string)$e->getMessage(), 0, 160);
            }
        }

        usleep(80000);
    }

    $localCleared = fic_delete_all_clear_local_links_for_doc_ids($pdo, $deletedDocIds);

    $remaining = $totalBefore - $processed;
    if ($remaining < 0) {
        $remaining = 0;
    }
    $done = ($processed === 0 || $remaining === 0);
    $finalLocalCleared = 0;
    if ($done) {
        $finalLocalCleared = fic_delete_all_clear_all_local_links($pdo);
        fic_delete_all_invalidate_sdi_cache($companyId);
    }

    fatture_log($pdo, null, 'fic_delete_all_chunk', $failed > 0 ? 'error' : 'ok', 'Cancellazione fatture FIC a blocchi eseguita.', [
        'processed' => $processed,
        'deleted' => $deleted,
        'missing' => $missing,
        'failed' => $failed,
        'remaining' => $remaining,
        'local_cleared' => $localCleared,
        'local_cleared_final' => $finalLocalCleared,
        'done' => $done,
        'errors' => $errors,
    ]);

    if ($isAjax) {
        fic_delete_all_json_response(200, [
            'ok' => true,
            'processed' => $processed,
            'deleted' => $deleted,
            'missing' => $missing,
            'failed' => $failed,
            'local_cleared' => $localCleared,
            'local_cleared_final' => $finalLocalCleared,
            'remaining' => $remaining,
            'done' => $done,
            'errors' => $errors,
        ]);
    }

    $params = [
        'fic_delete_all_done' => $done ? '1' : '0',
        'fic_delete_all_deleted' => $deleted,
        'fic_delete_all_missing' => $missing,
        'fic_delete_all_failed' => $failed,
        'fic_delete_all_local_cleared' => ($localCleared + $finalLocalCleared),
    ];
    if ($errors) {
        $params['fic_delete_all_warnings'] = implode(' | ', $errors);
    }
    if (!$done) {
        $params['fic_delete_all_error'] = 'Operazione parziale: restano ancora fatture da eliminare (' . $remaining . ').';
    }
    header('Location: ' . fic_delete_all_build_return_url($baseUrl, $params));
    exit;
} catch (Throwable $e) {
    $msg = substr((string)$e->getMessage(), 0, 220);
    fatture_log($pdo, null, 'fic_delete_all_chunk', 'error', 'Errore cancellazione fatture FIC a blocchi: ' . $msg);
    if ($isAjax) {
        fic_delete_all_json_response(500, ['ok' => false, 'error' => $msg]);
    }
    header('Location: ' . fic_delete_all_build_return_url($baseUrl, [
        'fic_delete_all_error' => $msg,
    ]));
    exit;
}

