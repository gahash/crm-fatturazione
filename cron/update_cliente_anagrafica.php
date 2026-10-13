<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clienteId = max(0, (int)($_GET['cliente_id'] ?? 0));
if ($clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cliente_id mancante'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedFields = ['nome', 'email', 'telefono', 'azienda', 'indirizzo', 'piva', 'codice_sdi', 'pec'];
$updates = [];
$params = [':id' => $clienteId];

foreach ($allowedFields as $field) {
    if (!array_key_exists($field, $_GET)) {
        continue;
    }
    $value = trim((string)$_GET[$field]);
    $updates[] = "`{$field}` = :{$field}";
    $params[':' . $field] = $value;
}

if (!$updates) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nessun campo da aggiornare'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare('UPDATE clienti SET ' . implode(', ', $updates) . ' WHERE id = :id');
    $stmt->execute($params);

    $read = $pdo->prepare('SELECT id, nome, azienda, email, telefono, indirizzo, piva, codice_sdi, pec FROM clienti WHERE id = :id LIMIT 1');
    $read->execute([':id' => $clienteId]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Cliente non trovato dopo aggiornamento');
    }

    echo json_encode([
        'ok' => true,
        'cliente_id' => $clienteId,
        'cliente' => trim((string)(($row['azienda'] ?? '') !== '' ? $row['azienda'] : ($row['nome'] ?? ''))),
        'indirizzo' => (string)($row['indirizzo'] ?? ''),
        'piva' => (string)($row['piva'] ?? ''),
        'codice_sdi' => (string)($row['codice_sdi'] ?? ''),
        'pec' => (string)($row['pec'] ?? ''),
        'missing_data_error' => fic_einvoice_missing_data_error($row),
        'address_error' => fic_einvoice_address_error($row),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}