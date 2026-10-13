<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$clientId = max(0, (int)($_GET['cliente_id'] ?? 0));
$limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));

$pdo = require __DIR__ . '/../config/db.php';

try {
    $sql = "SELECT c.id, c.nome, c.azienda, c.email, c.piva, c.codice_sdi, c.pec, c.indirizzo,
                   COUNT(f.id) AS invoice_count,
                   MAX(f.data_emissione) AS last_invoice_date
            FROM clienti c
            LEFT JOIN fatture f ON f.cliente_id = c.id AND f.document_type = 'invoice'
            WHERE 1=1";
    $params = [];

    if ($clientId > 0) {
        $sql .= ' AND c.id = :client_id';
        $params[':client_id'] = $clientId;
    }

    if ($search !== '') {
        $sql .= ' AND (c.nome LIKE :q_nome OR c.azienda LIKE :q_azienda OR c.email LIKE :q_email)';
        $like = '%' . $search . '%';
        $params[':q_nome'] = $like;
        $params[':q_azienda'] = $like;
        $params[':q_email'] = $like;
    }

    $sql .= ' GROUP BY c.id, c.nome, c.azienda, c.email, c.piva, c.codice_sdi, c.pec, c.indirizzo';
    $sql .= ' ORDER BY last_invoice_date DESC, c.id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $addressParts = fic_parse_address_parts((string)($row['indirizzo'] ?? ''));
        $addressError = fic_einvoice_address_error($row);
        $missingData = fic_einvoice_missing_data_error($row);

        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'cliente' => trim((string)(($row['azienda'] ?? '') !== '' ? $row['azienda'] : ($row['nome'] ?? ''))),
            'email' => (string)($row['email'] ?? ''),
            'piva' => (string)($row['piva'] ?? ''),
            'codice_sdi' => (string)($row['codice_sdi'] ?? ''),
            'pec' => (string)($row['pec'] ?? ''),
            'indirizzo_raw' => (string)($row['indirizzo'] ?? ''),
            'address_parts' => $addressParts,
            'address_error' => $addressError,
            'missing_data_error' => $missingData,
            'invoice_count' => (int)($row['invoice_count'] ?? 0),
            'last_invoice_date' => $row['last_invoice_date'] ?? null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'count' => count($out),
        'rows' => $out,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}