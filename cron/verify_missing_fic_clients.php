<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '180');
@ini_set('memory_limit', '512M');

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

function verify_missing_clients_fetch(PDO $pdo, ?int $clienteId = null, int $limitClients = 50): array
{
    $missingPredicate = "f.document_type = 'invoice'
          AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
          AND (
                LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'
                OR LOWER(COALESCE(f.ei_status, '')) = 'duplicate_unresolved'
                OR LOWER(COALESCE(f.ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                OR LOWER(COALESCE(f.mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
          )";

    $sql = "
        SELECT
            c.id AS cliente_id,
            TRIM(COALESCE(NULLIF(c.azienda, ''), NULLIF(c.nome, ''), CONCAT('Cliente #', c.id))) AS cliente,
            f.id AS fattura_id,
            f.numero,
            f.data_emissione,
            f.totale,
            f.fic_document_id,
            f.fic_status,
            f.ei_status,
            c.nome,
            c.email,
            c.indirizzo,
            c.piva,
            c.azienda,
            c.codice_sdi,
            c.pec
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
                WHERE {$missingPredicate}
    ";

    $params = [];
    if ($clienteId !== null && $clienteId > 0) {
        $sql .= ' AND c.id = :cliente_id';
        $params[':cliente_id'] = $clienteId;
    }

    $sql .= ' ORDER BY cliente ASC, f.data_emissione DESC, f.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($clienteId !== null && $clienteId > 0) {
        return $rows;
    }

    $selectedClients = [];
    $filtered = [];
    foreach ($rows as $row) {
        $cid = (int)($row['cliente_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        if (!isset($selectedClients[$cid]) && count($selectedClients) >= $limitClients) {
            continue;
        }
        $selectedClients[$cid] = true;
        $filtered[] = $row;
    }

    return $filtered;
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

    $clienteId = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : null;
    $limitClients = max(1, min(100, (int)($_GET['limit_clients'] ?? 50)));
    $rows = verify_missing_clients_fetch($pdo, $clienteId, $limitClients);

    $byClient = [];
    foreach ($rows as $row) {
        $cid = (int)($row['cliente_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }

        if (!isset($byClient[$cid])) {
            $byClient[$cid] = [
                'cliente_id' => $cid,
                'cliente' => (string)($row['cliente'] ?? ''),
                'invoice_count' => 0,
                'matched_in_fic_count' => 0,
                'unmatched_count' => 0,
                'status' => 'not_found_in_fic',
                'invoices' => [],
            ];
        }

        $byClient[$cid]['invoice_count']++;

        $fattura = $row;
        [$foundDocId, $foundStatus] = fic_find_existing_document_by_number($companyId, 'invoice', $fattura, true);
        $matched = $foundDocId !== null;

        if ($matched) {
            $byClient[$cid]['matched_in_fic_count']++;
        } else {
            $byClient[$cid]['unmatched_count']++;
        }

        $byClient[$cid]['invoices'][] = [
            'fattura_id' => (int)($row['fattura_id'] ?? 0),
            'numero' => (string)($row['numero'] ?? ''),
            'data_emissione' => (string)($row['data_emissione'] ?? ''),
            'totale' => $row['totale'] ?? null,
            'found_in_fic' => $matched,
            'found_doc_id' => $foundDocId,
            'found_doc_status' => $foundStatus,
        ];
    }

    foreach ($byClient as &$client) {
        if ($client['matched_in_fic_count'] > 0 && $client['unmatched_count'] === 0) {
            $client['status'] = 'already_in_fic';
        } elseif ($client['matched_in_fic_count'] > 0) {
            $client['status'] = 'partial_in_fic';
        } else {
            $client['status'] = 'not_found_in_fic';
        }
    }
    unset($client);

    $clients = array_values($byClient);
    usort($clients, static function (array $a, array $b): int {
        return strcmp((string)$a['cliente'], (string)$b['cliente']);
    });

    $summary = [
        'clients_checked' => count($clients),
        'already_in_fic' => count(array_filter($clients, static fn($row) => $row['status'] === 'already_in_fic')),
        'partial_in_fic' => count(array_filter($clients, static fn($row) => $row['status'] === 'partial_in_fic')),
        'not_found_in_fic' => count(array_filter($clients, static fn($row) => $row['status'] === 'not_found_in_fic')),
        'missing_invoice_rows' => count($rows),
    ];

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'summary' => $summary,
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}