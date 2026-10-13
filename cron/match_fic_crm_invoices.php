<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '120');
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

function crm_fic_extract_documents(array $response): array
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

function crm_fic_local_invoices(PDO $pdo, string $dateFrom, int $limit, string $search): array
{
    $sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'";
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

function crm_fic_fetch_remote_docs(string $companyId, int $pages, string $type = 'invoice'): array
{
    $docs = [];
    $seen = [];

    for ($page = 1; $page <= $pages; $page++) {
        $query = http_build_query([
            'type' => $type,
            'per_page' => 100,
            'page' => $page,
        ]);

        $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Lettura documenti FIC fallita (HTTP ' . $status . ').');
        }

        $pageDocs = crm_fic_extract_documents($response);
        if (!$pageDocs) {
            break;
        }

        foreach ($pageDocs as $doc) {
            $docId = trim((string)($doc['id'] ?? ''));
            if ($docId === '' || isset($seen[$docId])) {
                continue;
            }
            $seen[$docId] = true;
            $docs[] = $doc;
        }

        $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
        if ($page >= $lastPage) {
            break;
        }
    }

    return $docs;
}

function crm_fic_match_remote_to_local(array $doc, array $localInvoices): array
{
    $docId = trim((string)($doc['id'] ?? ''));
    foreach ($localInvoices as $fattura) {
        if ($docId !== '' && trim((string)($fattura['fic_document_id'] ?? '')) === $docId) {
            return ['type' => 'linked_doc_id', 'fattura' => $fattura];
        }
    }

    foreach ($localInvoices as $fattura) {
        $localNumber = trim((string)($fattura['numero'] ?? ''));
        $isPureNumeric = preg_match('/^\d+$/', $localNumber) === 1;
        if ($localNumber !== '' && fic_doc_matches_local_number($doc, $localNumber, $isPureNumeric)) {
            return ['type' => 'matched_number', 'fattura' => $fattura];
        }
    }

    foreach ($localInvoices as $fattura) {
        if (fic_doc_matches_fingerprint($doc, $fattura)) {
            return ['type' => 'matched_fingerprint', 'fattura' => $fattura];
        }
    }

    return ['type' => 'no_local_match', 'fattura' => null];
}

function crm_fic_local_reason(string $matchType, array $fattura, ?array $matchedDoc, string $dateFrom): string
{
    if ($matchType === 'linked_doc_id') {
        return 'La fattura CRM ha gia un fic_document_id valido e il documento FIC e stato trovato nel set remoto analizzato.';
    }

    if ($matchType === 'found_by_number') {
        return 'La fattura CRM non era collegata correttamente, ma il documento FIC esiste ed e stato ritrovato per numero/subject.';
    }

    if ($matchType === 'found_by_fingerprint') {
        return 'La fattura CRM non era collegata correttamente, ma il documento FIC esiste ed e stato ritrovato per fingerprint forte (cliente + data + importo).';
    }

    $numero = trim((string)($fattura['numero'] ?? ''));
    if ($numero === '') {
        return 'La fattura CRM non ha un numero valorizzato e non e stato possibile trovare un documento FIC con fingerprint affidabile.';
    }

    if ($matchedDoc === null) {
        return 'Nessun documento FIC trovato con i criteri attuali di match. Le cause tipiche sono: fattura non ancora sincronizzata, numerazione diversa su FIC, oppure documento fuori dallo scope remoto analizzato.';
    }

    return 'Il documento FIC e stato trovato, ma il collegamento locale non era ancora registrato.';
}

function crm_fic_remote_reason(string $matchType, ?array $fattura, string $dateFrom): string
{
    if ($matchType === 'linked_doc_id') {
        return 'Il documento FIC e gia collegato alla fattura CRM tramite fic_document_id.';
    }

    if ($matchType === 'matched_number') {
        return 'Il documento FIC corrisponde a una fattura CRM per numero/subject, ma il collegamento locale punta altrove o manca.';
    }

    if ($matchType === 'matched_fingerprint') {
        return 'Il documento FIC corrisponde a una fattura CRM per fingerprint forte, ma non per numero.';
    }

    if ($fattura === null) {
        return 'Nel set locale filtrato non esiste una fattura CRM che corrisponda per doc_id, numero o fingerprint. Potrebbe essere una fattura FIC orfana, una duplicazione, o una fattura CRM fuori dal filtro date_from=' . $dateFrom . '.';
    }

    return 'Documento FIC senza collegamento locale deterministico.';
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

    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $pages = max(1, min(20, (int)($_GET['pages'] ?? 5)));
    $dateFrom = trim((string)($_GET['date_from'] ?? '2025-10-01'));
    $search = trim((string)($_GET['search'] ?? ''));

    $localInvoices = crm_fic_local_invoices($pdo, $dateFrom, $limit, $search);
    $remoteDocs = crm_fic_fetch_remote_docs($companyId, $pages, 'invoice');

    $remoteById = [];
    foreach ($remoteDocs as $doc) {
        $docId = trim((string)($doc['id'] ?? ''));
        if ($docId !== '') {
            $remoteById[$docId] = $doc;
        }
    }

    $localReport = [];
    foreach ($localInvoices as $fattura) {
        $linkedDocId = trim((string)($fattura['fic_document_id'] ?? ''));
        $matchType = 'no_match';
        $matchedDoc = null;

        if ($linkedDocId !== '' && isset($remoteById[$linkedDocId])) {
            $matchType = 'linked_doc_id';
            $matchedDoc = $remoteById[$linkedDocId];
        } else {
            [$foundDocId, $foundStatus] = fic_find_existing_document_by_number($companyId, 'invoice', $fattura, true);
            if ($foundDocId !== null) {
                $matchedDoc = $remoteById[$foundDocId] ?? [
                    'id' => $foundDocId,
                    'status' => $foundStatus,
                ];

                $numero = trim((string)($fattura['numero'] ?? ''));
                $isPureNumeric = preg_match('/^\d+$/', $numero) === 1;
                if ($numero !== '' && fic_doc_matches_local_number($matchedDoc, $numero, $isPureNumeric)) {
                    $matchType = 'found_by_number';
                } elseif (fic_doc_matches_fingerprint($matchedDoc, $fattura)) {
                    $matchType = 'found_by_fingerprint';
                } else {
                    $matchType = 'found_by_search';
                }
            }
        }

        $localReport[] = [
            'fattura_id' => (int)($fattura['id'] ?? 0),
            'numero' => (string)($fattura['numero'] ?? ''),
            'data_emissione' => (string)($fattura['data_emissione'] ?? ''),
            'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
            'totale' => $fattura['totale'] ?? null,
            'crm_fic_document_id' => $linkedDocId !== '' ? $linkedDocId : null,
            'crm_fic_status' => (string)($fattura['fic_status'] ?? ''),
            'ei_status' => (string)($fattura['ei_status'] ?? ''),
            'match_type' => $matchType,
            'match_reason' => crm_fic_local_reason($matchType, $fattura, $matchedDoc, $dateFrom),
            'matched_doc' => $matchedDoc ? [
                'id' => $matchedDoc['id'] ?? null,
                'number' => $matchedDoc['number'] ?? null,
                'status' => $matchedDoc['status'] ?? null,
                'visible_subject' => $matchedDoc['visible_subject'] ?? null,
                'date' => $matchedDoc['date'] ?? null,
            ] : null,
        ];
    }

    $remoteReport = [];
    foreach ($remoteDocs as $doc) {
        $match = crm_fic_match_remote_to_local($doc, $localInvoices);
        $fattura = $match['fattura'];
        $remoteReport[] = [
            'doc_id' => (string)($doc['id'] ?? ''),
            'number' => (string)($doc['number'] ?? ''),
            'status' => (string)($doc['status'] ?? ''),
            'date' => (string)($doc['date'] ?? ''),
            'visible_subject' => (string)($doc['visible_subject'] ?? ''),
            'match_type' => $match['type'],
            'match_reason' => crm_fic_remote_reason($match['type'], $fattura, $dateFrom),
            'matched_fattura' => $fattura ? [
                'id' => (int)($fattura['id'] ?? 0),
                'numero' => (string)($fattura['numero'] ?? ''),
                'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
                'fic_document_id' => (string)($fattura['fic_document_id'] ?? ''),
            ] : null,
        ];
    }

    $summary = [
        'local_total' => count($localReport),
        'local_linked_doc_id' => count(array_filter($localReport, static fn($row) => $row['match_type'] === 'linked_doc_id')),
        'local_found_by_number' => count(array_filter($localReport, static fn($row) => $row['match_type'] === 'found_by_number')),
        'local_found_by_fingerprint' => count(array_filter($localReport, static fn($row) => $row['match_type'] === 'found_by_fingerprint')),
        'local_found_by_search' => count(array_filter($localReport, static fn($row) => $row['match_type'] === 'found_by_search')),
        'local_no_match' => count(array_filter($localReport, static fn($row) => $row['match_type'] === 'no_match')),
        'remote_total' => count($remoteReport),
        'remote_linked_doc_id' => count(array_filter($remoteReport, static fn($row) => $row['match_type'] === 'linked_doc_id')),
        'remote_matched_number' => count(array_filter($remoteReport, static fn($row) => $row['match_type'] === 'matched_number')),
        'remote_matched_fingerprint' => count(array_filter($remoteReport, static fn($row) => $row['match_type'] === 'matched_fingerprint')),
        'remote_no_local_match' => count(array_filter($remoteReport, static fn($row) => $row['match_type'] === 'no_local_match')),
    ];

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'filters' => [
            'date_from' => $dateFrom,
            'limit' => $limit,
            'pages' => $pages,
            'search' => $search,
        ],
        'summary' => $summary,
        'notes' => [
            'linked_doc_id = collegamento CRM gia coerente con FIC',
            'found_by_number / matched_number = il documento esiste ma il link locale manca o punta altrove',
            'found_by_fingerprint / matched_fingerprint = esiste un match forte per cliente+data+importo ma non per numero',
            'no_match / no_local_match = nessun collegamento deterministico trovato nello scope analizzato',
        ],
        'local_report' => $localReport,
        'remote_report' => $remoteReport,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}