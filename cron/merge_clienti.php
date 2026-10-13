<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sourceId = max(0, (int)($_GET['source_id'] ?? 0));
$targetId = max(0, (int)($_GET['target_id'] ?? 0));
$deleteSource = !isset($_GET['delete_source']) || (string)$_GET['delete_source'] !== '0';

if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'source_id e target_id non validi'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function merge_clienti_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function merge_clienti_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function merge_clienti_trim($value): string
{
    return trim((string)$value);
}

function merge_clienti_has_complete_address(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    return preg_match('/\b\d{5}\b/', $value) === 1 && preg_match('/\b[A-Z]{2}\b/', strtoupper($value)) === 1;
}

function merge_clienti_has_valid_sdi(string $value): bool
{
    $value = strtoupper(trim($value));
    return preg_match('/^[A-Z0-9]{7}$/', $value) === 1 && $value !== '0000000';
}

function merge_clienti_has_valid_pec(string $value): bool
{
    $value = strtolower(trim($value));
    return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function merge_clienti_apply_best_data(PDO $pdo, array $source, array $target): array
{
    $set = [];
    $params = [':id' => (int)$target['id']];
    $changed = [];

    foreach (['nome', 'azienda', 'email', 'telefono', 'piva', 'note'] as $field) {
        $sourceValue = merge_clienti_trim($source[$field] ?? '');
        $targetValue = merge_clienti_trim($target[$field] ?? '');
        if ($sourceValue !== '' && $targetValue === '') {
            $placeholder = ':' . $field;
            $set[] = "`{$field}` = {$placeholder}";
            $params[$placeholder] = $sourceValue;
            $changed[$field] = $sourceValue;
        }
    }

    $sourceAddress = merge_clienti_trim($source['indirizzo'] ?? '');
    $targetAddress = merge_clienti_trim($target['indirizzo'] ?? '');
    if (merge_clienti_has_complete_address($sourceAddress) && !merge_clienti_has_complete_address($targetAddress)) {
        $set[] = '`indirizzo` = :indirizzo';
        $params[':indirizzo'] = $sourceAddress;
        $changed['indirizzo'] = $sourceAddress;
    }

    $sourceSdi = strtoupper(merge_clienti_trim($source['codice_sdi'] ?? ''));
    $targetSdi = strtoupper(merge_clienti_trim($target['codice_sdi'] ?? ''));
    if (merge_clienti_has_valid_sdi($sourceSdi) && !merge_clienti_has_valid_sdi($targetSdi)) {
        $set[] = '`codice_sdi` = :codice_sdi';
        $params[':codice_sdi'] = $sourceSdi;
        $changed['codice_sdi'] = $sourceSdi;
    }

    $sourcePec = strtolower(merge_clienti_trim($source['pec'] ?? ''));
    $targetPec = strtolower(merge_clienti_trim($target['pec'] ?? ''));
    if (merge_clienti_has_valid_pec($sourcePec) && !merge_clienti_has_valid_pec($targetPec)) {
        $set[] = '`pec` = :pec';
        $params[':pec'] = $sourcePec;
        $changed['pec'] = $sourcePec;
    }

    if (merge_clienti_column_exists($pdo, 'clienti', 'fic_client_id')) {
        $sourceFicId = merge_clienti_trim($source['fic_client_id'] ?? '');
        $targetFicId = merge_clienti_trim($target['fic_client_id'] ?? '');
        if ($sourceFicId !== '' && $targetFicId === '') {
            $set[] = '`fic_client_id` = :fic_client_id';
            $params[':fic_client_id'] = $sourceFicId;
            $changed['fic_client_id'] = $sourceFicId;
        }
    }

    if ($set) {
        $stmt = $pdo->prepare('UPDATE clienti SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    return $changed;
}

function merge_clienti_update_fk(PDO $pdo, string $table, int $sourceId, int $targetId): int
{
    if (!merge_clienti_table_exists($pdo, $table) || !merge_clienti_column_exists($pdo, $table, 'cliente_id')) {
        return 0;
    }
    $stmt = $pdo->prepare("UPDATE `{$table}` SET cliente_id = :target_id WHERE cliente_id = :source_id");
    $stmt->execute([':target_id' => $targetId, ':source_id' => $sourceId]);
    return $stmt->rowCount();
}

function merge_clienti_merge_fic_map(PDO $pdo, int $sourceId, int $targetId): int
{
    if (!merge_clienti_table_exists($pdo, 'fic_clienti_map')) {
        return 0;
    }

    $inserted = 0;
    $rows = $pdo->prepare('SELECT company_id, fic_entity_id, source FROM fic_clienti_map WHERE cliente_id = :source_id');
    $rows->execute([':source_id' => $sourceId]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stmt = $pdo->prepare('INSERT INTO fic_clienti_map (cliente_id, company_id, fic_entity_id, source, updated_at)
            VALUES (:target_id, :company_id, :fic_entity_id, :source, NOW())
            ON DUPLICATE KEY UPDATE
                fic_entity_id = COALESCE(NULLIF(fic_clienti_map.fic_entity_id, 0), VALUES(fic_entity_id)),
                source = COALESCE(fic_clienti_map.source, VALUES(source)),
                updated_at = NOW()');
        $stmt->execute([
            ':target_id' => $targetId,
            ':company_id' => $row['company_id'],
            ':fic_entity_id' => $row['fic_entity_id'],
            ':source' => $row['source'],
        ]);
        $inserted++;
    }

    $delete = $pdo->prepare('DELETE FROM fic_clienti_map WHERE cliente_id = :source_id');
    $delete->execute([':source_id' => $sourceId]);
    return $inserted;
}

try {
    $sourceStmt = $pdo->prepare('SELECT * FROM clienti WHERE id = :id LIMIT 1');
    $sourceStmt->execute([':id' => $sourceId]);
    $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);

    $targetStmt = $pdo->prepare('SELECT * FROM clienti WHERE id = :id LIMIT 1');
    $targetStmt->execute([':id' => $targetId]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$source || !$target) {
        throw new RuntimeException('Cliente sorgente o destinazione non trovato');
    }

    $pdo->beginTransaction();

    $updatedFields = merge_clienti_apply_best_data($pdo, $source, $target);

    $moved = [
        'clienti_note' => merge_clienti_update_fk($pdo, 'clienti_note', $sourceId, $targetId),
        'clienti_interazioni' => merge_clienti_update_fk($pdo, 'clienti_interazioni', $sourceId, $targetId),
        'fatture' => merge_clienti_update_fk($pdo, 'fatture', $sourceId, $targetId),
        'pagamenti' => merge_clienti_update_fk($pdo, 'pagamenti', $sourceId, $targetId),
        'stripe_connected_accounts' => merge_clienti_update_fk($pdo, 'stripe_connected_accounts', $sourceId, $targetId),
        'stripe_customer_links' => merge_clienti_update_fk($pdo, 'stripe_customer_links', $sourceId, $targetId),
        'stripe_sync' => merge_clienti_update_fk($pdo, 'stripe_sync', $sourceId, $targetId),
        'fic_clienti_map' => merge_clienti_merge_fic_map($pdo, $sourceId, $targetId),
    ];

    $deleted = false;
    $deleteError = null;
    if ($deleteSource) {
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM clienti WHERE id = :id');
            $deleteStmt->execute([':id' => $sourceId]);
            $deleted = $deleteStmt->rowCount() > 0;
        } catch (Throwable $e) {
            $deleteError = substr((string)$e->getMessage(), 0, 300);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'source_id' => $sourceId,
        'target_id' => $targetId,
        'updated_fields' => $updatedFields,
        'moved_counts' => $moved,
        'deleted_source' => $deleted,
        'delete_error' => $deleteError,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}