<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_api.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$clienteId = max(0, (int)($_GET['cliente_id'] ?? 0));
if ($clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cliente_id mancante']);
    exit;
}

$piva = trim((string)($_GET['piva'] ?? ''));
$codiceSdi = trim((string)($_GET['codice_sdi'] ?? ''));
$pec = trim((string)($_GET['pec'] ?? ''));
$ficClientId = trim((string)($_GET['fic_client_id'] ?? ''));

if ($piva === '' && $codiceSdi === '' && $pec === '' && $ficClientId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nessun campo fiscale da aggiornare']);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function update_cliente_fiscale_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function update_cliente_fiscale_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function update_cliente_fiscale_ensure_map_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fic_clienti_map (
                cliente_id INT NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                fic_entity_id BIGINT NOT NULL,
                source VARCHAR(20) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (cliente_id, company_id),
                KEY idx_fic_entity_id (fic_entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
    }
}

function update_cliente_fiscale_set_map(PDO $pdo, int $clienteId, string $ficClientId): void
{
    if ($clienteId <= 0 || trim($ficClientId) === '') {
        return;
    }

    $companyId = trim((string)fic_get_company_id());
    if ($companyId === '') {
        return;
    }

    update_cliente_fiscale_ensure_map_table($pdo);
    if (!update_cliente_fiscale_table_exists($pdo, 'fic_clienti_map')) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fic_clienti_map (cliente_id, company_id, fic_entity_id, source, updated_at)
             VALUES (:cliente_id, :company_id, :fic_entity_id, :source, NOW())
             ON DUPLICATE KEY UPDATE
                fic_entity_id = VALUES(fic_entity_id),
                source = VALUES(source),
                updated_at = NOW()'
        );
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':company_id' => $companyId,
            ':fic_entity_id' => (int)$ficClientId,
            ':source' => 'manual-link',
        ]);
    } catch (Throwable $e) {
    }
}

try {
    $fields = [];
    $params = [':id' => $clienteId];

    if ($piva !== '') {
        $fields[] = 'piva = :piva';
        $params[':piva'] = $piva;
    }
    if ($codiceSdi !== '') {
        $fields[] = 'codice_sdi = :codice_sdi';
        $params[':codice_sdi'] = $codiceSdi;
    }
    if ($pec !== '') {
        $fields[] = 'pec = :pec';
        $params[':pec'] = $pec;
    }
    $hasFicClientColumn = update_cliente_fiscale_column_exists($pdo, 'clienti', 'fic_client_id');
    if ($ficClientId !== '' && $hasFicClientColumn) {
        $fields[] = 'fic_client_id = :fic_client_id';
        $params[':fic_client_id'] = $ficClientId;
    }

    if ($fields) {
        $sql = 'UPDATE clienti SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    if ($ficClientId !== '' && !$hasFicClientColumn) {
        update_cliente_fiscale_set_map($pdo, $clienteId, $ficClientId);
    }

    $selectFicClientId = $hasFicClientColumn ? ', fic_client_id' : '';
    $read = $pdo->prepare("SELECT id, nome, azienda, email, piva, codice_sdi, pec, indirizzo{$selectFicClientId} FROM clienti WHERE id = :id LIMIT 1");
    $read->execute([':id' => $clienteId]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Cliente non trovato dopo aggiornamento');
    }

    echo json_encode([
        'ok' => true,
        'cliente_id' => $clienteId,
        'cliente' => trim((string)(($row['azienda'] ?? '') !== '' ? $row['azienda'] : ($row['nome'] ?? ''))),
        'piva' => (string)($row['piva'] ?? ''),
        'codice_sdi' => (string)($row['codice_sdi'] ?? ''),
        'pec' => (string)($row['pec'] ?? ''),
        'fic_client_id' => (string)($row['fic_client_id'] ?? ''),
        'missing_data_error' => fic_einvoice_missing_data_error($row),
        'address_error' => fic_einvoice_address_error($row),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}