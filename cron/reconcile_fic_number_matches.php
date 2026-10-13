<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '120');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_api.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function reconcile_number_extract_documents(array $response): array
{
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

function reconcile_number_local_invoices(PDO $pdo, string $dateFrom, int $limit, string $search): array
{
    $sql = "SELECT f.*, c.nome, c.email, c.azienda, c.piva
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
              AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')";
    $params = [];

    if ($dateFrom !== '') {
        $sql .= ' AND f.data_emissione >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($search !== '') {
        $sql .= ' AND (f.numero LIKE :q_num OR c.nome LIKE :q_nome OR c.azienda LIKE :q_azienda OR c.email LIKE :q_email)';
        $like = '%' . $search . '%';
        $params[':q_num'] = $like;
        $params[':q_nome'] = $like;
        $params[':q_azienda'] = $like;
        $params[':q_email'] = $like;
    }

    $sql .= ' ORDER BY f.data_emissione DESC, f.id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reconcile_number_unique_doc_match(string $companyId, array $fattura, int $maxPages = 20): array
{
    $numero = trim((string)($fattura['numero'] ?? ''));
    if ($numero === '') {
        return [null, 'missing_number', []];
    }

    $isPureNumeric = preg_match('/^\d+$/', $numero) === 1;
    $terms = [$numero];
    if ($isPureNumeric) {
        $terms[] = (string)((int)$numero);
    }

    $matches = [];
    $needsPageFallback = false;
    foreach (array_values(array_unique($terms)) as $term) {
        for ($page = 1; $page <= $maxPages; $page++) {
            $query = http_build_query([
                'type' => 'invoice',
                'q' => $term,
                'per_page' => 100,
                'page' => $page,
            ]);
            $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
            $status = (int)($response['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                if ($status === 422) {
                    $needsPageFallback = true;
                    break;
                }
                return [null, 'fic_query_error_http_' . $status, array_values($matches)];
            }

            $documents = reconcile_number_extract_documents($response);
            foreach ($documents as $doc) {
                $docId = trim((string)($doc['id'] ?? ''));
                if ($docId === '') {
                    continue;
                }
                if (fic_doc_matches_local_number((array)$doc, $numero, $isPureNumeric)) {
                    $matches[$docId] = $doc;
                }
            }

            $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
            if ($page >= $lastPage) {
                break;
            }
        }
    }

    if ($needsPageFallback || !$matches) {
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = fic_list_documents_page($companyId, 'invoice', $page);
            $status = (int)($response['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                if ($status === 422) {
                    break;
                }
                return [null, 'fic_page_scan_error_http_' . $status, array_values($matches)];
            }

            $documents = reconcile_number_extract_documents($response);
            foreach ($documents as $doc) {
                $docId = trim((string)($doc['id'] ?? ''));
                if ($docId === '') {
                    continue;
                }
                if (fic_doc_matches_local_number((array)$doc, $numero, $isPureNumeric)) {
                    $matches[$docId] = $doc;
                }
            }

            $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
            if ($page >= $lastPage) {
                break;
            }
        }
    }

    if (count($matches) === 1) {
        return [array_values($matches)[0], 'unique_number_match', array_values($matches)];
    }
    if (!$matches) {
        return [null, 'no_number_match', []];
    }
    return [null, 'ambiguous_number_match', array_values($matches)];
}

function reconcile_number_doc_claimed_by_other(PDO $pdo, string $docId, int $fatturaId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE TRIM(COALESCE(fic_document_id, '')) = :doc_id AND id <> :fattura_id");
    $stmt->execute([
        ':doc_id' => $docId,
        ':fattura_id' => $fatturaId,
    ]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function reconcile_number_apply(PDO $pdo, array $fattura, array $doc): void
{
    $update = $pdo->prepare(
        "UPDATE fatture
         SET fic_document_id = :doc_id,
             fic_status = 'linked',
             fic_synced_at = NOW(),
             ei_status = CASE
                 WHEN LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved' THEN NULL
                 ELSE ei_status
             END,
             ei_error = CASE
                 WHEN LOWER(COALESCE(ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                     OR LOWER(COALESCE(ei_error, '')) LIKE '%duplicato fic non risolto%'
                 THEN NULL
                 ELSE ei_error
             END,
             mail_error = CASE
                 WHEN LOWER(COALESCE(mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                     OR LOWER(COALESCE(mail_error, '')) LIKE '%duplicato fic non risolto%'
                 THEN NULL
                 ELSE mail_error
             END
         WHERE id = :id"
    );

    $update->execute([
        ':doc_id' => (string)($doc['id'] ?? ''),
        ':id' => (int)($fattura['id'] ?? 0),
    ]);
}

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
    $dateFrom = trim((string)($_GET['date_from'] ?? '2025-10-01'));
    $search = trim((string)($_GET['search'] ?? ''));
    $apply = (string)($_GET['apply'] ?? '') === '1';
    $maxPages = max(1, min(20, (int)($_GET['pages'] ?? 10)));

    $localInvoices = reconcile_number_local_invoices($pdo, $dateFrom, $limit, $search);
    $results = [];
    $eligible = 0;
    $updated = 0;

    foreach ($localInvoices as $fattura) {
        [$doc, $reason, $candidates] = reconcile_number_unique_doc_match($companyId, $fattura, $maxPages);
        $docId = trim((string)($doc['id'] ?? ''));
        $claimed = $docId !== '' ? reconcile_number_doc_claimed_by_other($pdo, $docId, (int)($fattura['id'] ?? 0)) : false;

        $action = 'skip';
        if ($reason === 'unique_number_match' && !$claimed && $docId !== '') {
            $eligible++;
            $action = $apply ? 'reconciled' : 'eligible';
            if ($apply) {
                reconcile_number_apply($pdo, $fattura, $doc);
                $updated++;
            }
        } elseif ($claimed) {
            $reason = 'doc_already_claimed';
        }

        $results[] = [
            'fattura_id' => (int)($fattura['id'] ?? 0),
            'numero' => (string)($fattura['numero'] ?? ''),
            'data_emissione' => (string)($fattura['data_emissione'] ?? ''),
            'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
            'reason' => $reason,
            'action' => $action,
            'matched_doc' => $doc ? [
                'id' => $doc['id'] ?? null,
                'number' => $doc['number'] ?? null,
                'date' => $doc['date'] ?? null,
                'visible_subject' => $doc['visible_subject'] ?? null,
            ] : null,
            'candidate_count' => count($candidates),
        ];
    }

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'filters' => [
            'date_from' => $dateFrom,
            'limit' => $limit,
            'pages' => $maxPages,
            'search' => $search,
            'apply' => $apply,
        ],
        'summary' => [
            'scanned' => count($results),
            'eligible_unique_number_matches' => $eligible,
            'updated' => $updated,
            'skipped' => count($results) - $updated,
        ],
        'notes' => [
            'Riconcilia solo fatture senza fic_document_id locale.',
            'Richiede esattamente un solo match FIC per numero/subject.',
            'Salta i doc_id gia assegnati ad altre fatture CRM.',
            'Con apply=1 scrive fic_document_id e imposta fic_status=linked.',
        ],
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}