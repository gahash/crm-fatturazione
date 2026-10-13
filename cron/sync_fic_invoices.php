<?php
/**
 * CRON: sincronizza su Fatture in Cloud le fatture locali (NO SDI).
 *
 * Chiamata:
 *   https://crm.example.com/cron/sync_fic_invoices.php?key=CHIAVE
 *
 * Parametri opzionali:
 *   - batch (default 1, max 3)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '120');
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

require_once __DIR__ . '/../modules/fatture/actions/fic_api.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_audit.php';

$pdo = require __DIR__ . '/../config/db.php';

$GLOBALS['FIC_API_MAX_ATTEMPTS'] = 2;
$GLOBALS['FIC_API_CONNECT_TIMEOUT'] = 5;
$GLOBALS['FIC_API_TIMEOUT'] = 14;

function fic_cron_is_usage_limit_error(string $msg): bool
{
    return fic_is_usage_limit_error_message($msg);
}

function fic_cron_is_duplicate_not_recoverable(string $msg): bool
{
    return strpos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0;
}

function fic_cron_is_retryable_sync_error(string $msg): bool
{
    $m = strtolower($msg);
    return strpos($m, 'server has gone away') !== false
        || strpos($m, 'lost connection') !== false;
}

function fic_cron_iso_from_ts(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function fic_cron_usage_limit_file(): string
{
    return __DIR__ . '/../tmp/fic_usage_limit_until.json';
}

function fic_cron_skip_ids_file(): string
{
    return __DIR__ . '/../tmp/fic_sync_skip_ids.json';
}

function fic_cron_load_skip_ids(int $ttlSeconds = 86400): array
{
    $path = fic_cron_skip_ids_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return [];
    }
    $now = time();
    $out = [];
    foreach ($data as $k => $ts) {
        $id = (int)$k;
        $when = (int)$ts;
        if ($id <= 0 || $when <= 0) {
            continue;
        }
        if (($now - $when) <= $ttlSeconds) {
            $out[(string)$id] = $when;
        }
    }
    return $out;
}

function fic_cron_save_skip_ids(array $ids): void
{
    @file_put_contents(fic_cron_skip_ids_file(), json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function fic_cron_set_usage_cooldown(int $minutes, string $reason): void
{
    $untilTs = time() + max(1, $minutes) * 60;
    $payload = [
        'until_ts' => $untilTs,
        'until_iso' => fic_cron_iso_from_ts($untilTs),
        'reason' => substr($reason, 0, 300),
        'updated_at' => fic_cron_iso_from_ts(time()),
    ];
    @file_put_contents(fic_cron_usage_limit_file(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function fic_cron_get_usage_cooldown(): ?array
{
    $path = fic_cron_usage_limit_file();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return null;
    }
    $untilTs = (int)($data['until_ts'] ?? 0);
    if ($untilTs <= 0) {
        return null;
    }
    return [
        'until_ts' => $untilTs,
        'until_iso' => (string)($data['until_iso'] ?? fic_cron_iso_from_ts($untilTs)),
        'reason' => (string)($data['reason'] ?? ''),
    ];
}

function fic_cron_clear_usage_cooldown(): void
{
    $path = fic_cron_usage_limit_file();
    if (is_file($path)) {
        @unlink($path);
    }
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cron_sync_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_name VARCHAR(100) NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        imported INT UNSIGNED NOT NULL DEFAULT 0,
        invoices_created INT UNSIGNED NOT NULL DEFAULT 0,
        clients_created INT UNSIGNED NOT NULL DEFAULT 0,
        errors INT UNSIGNED NOT NULL DEFAULT 0,
        details JSON NULL,
        INDEX(job_name, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}

try {
    $lockStmt = $pdo->prepare("SELECT started_at FROM cron_sync_log WHERE job_name = 'fic_sync_no_sdi' AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
    $lockStmt->execute();
    if ($lockStmt->fetch()) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Sync FIC gia in corso']);
        exit;
    }
} catch (Throwable $e) {
}

$runId = 0;
try {
    $runStmt = $pdo->prepare("INSERT INTO cron_sync_log (job_name, started_at, status) VALUES ('fic_sync_no_sdi', NOW(), 'running')");
    $runStmt->execute();
    $runId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
}

$cooldown = fic_cron_get_usage_cooldown();
$nowTs = time();
if (is_array($cooldown) && $nowTs >= (int)$cooldown['until_ts']) {
    fic_cron_clear_usage_cooldown();
    $cooldown = null;
}
if (is_array($cooldown) && $nowTs < (int)$cooldown['until_ts']) {
    $details = [
        'skipped' => true,
        'reason' => 'usage_limit_cooldown',
        'now_ts' => $nowTs,
        'now_iso' => fic_cron_iso_from_ts($nowTs),
        'cooldown_until_ts' => (int)$cooldown['until_ts'],
        'retry_after' => $cooldown['until_iso'],
        'source' => 'local_cooldown_file',
    ];
    if ($runId > 0) {
        try {
            $pdo->prepare("UPDATE cron_sync_log SET finished_at = NOW(), status = 'ok', imported = 0, invoices_created = 0, errors = 0, details = ? WHERE id = ?")
                ->execute([json_encode($details, JSON_UNESCAPED_UNICODE), $runId]);
        } catch (Throwable $e) {
        }
    }
    echo json_encode(array_merge(['ok' => true], $details), JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = [
    'processed' => 0,
    'created' => 0,
    'linked' => 0,
    'duplicates_marked' => 0,
    'skipped_local' => 0,
    'failed' => 0,
    'usage_limited' => false,
    'retry_after' => null,
    'error_samples' => [],
];

$clienteIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['cliente_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));
$fatturaIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['fattura_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));
$includeSyncErrors = isset($_GET['include_sync_errors']) && (string)$_GET['include_sync_errors'] === '1';

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id mancante');
    }
    if (fic_get_access_token() === '') {
        throw new RuntimeException('access_token mancante');
    }

    $batchSize = (int)($_GET['batch'] ?? 1);
    if ($batchSize < 1) {
        $batchSize = 1;
    }
    if ($batchSize > 3) {
        $batchSize = 3;
    }

    $skipIds = fic_cron_load_skip_ids();
    $scanLimit = max(10, $batchSize * 30);

    $clienteFilterSql = '';
    if ($clienteIds) {
        $clienteFilterSql = ' AND f.cliente_id IN (' . implode(',', array_fill(0, count($clienteIds), '?')) . ')';
    }
    $fatturaFilterSql = '';
    if ($fatturaIds) {
        $fatturaFilterSql = ' AND f.id IN (' . implode(',', array_fill(0, count($fatturaIds), '?')) . ')';
    }

    $syncErrorPredicate = $includeSyncErrors
        ? "1=1"
        : "(
                        f.fic_status <> 'sync_error'
                        OR LOWER(COALESCE(f.ei_error, '')) LIKE '%server has gone away%'
                        OR LOWER(COALESCE(f.ei_error, '')) LIKE '%lost connection%'
                        OR LOWER(COALESCE(f.mail_error, '')) LIKE '%server has gone away%'
                        OR LOWER(COALESCE(f.mail_error, '')) LIKE '%lost connection%'
                    )";

    $sql = "
        SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
        WHERE f.document_type = 'invoice'
          AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
          {$clienteFilterSql}
                    {$fatturaFilterSql}
            AND (
                 f.fic_status IS NULL
                 OR f.fic_status <> 'duplicate_unresolved'
                    AND (
                        {$syncErrorPredicate}
                    )
                )
        ORDER BY f.id ASC
        LIMIT " . (int)$scanLimit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($clienteIds, $fatturaIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if ($stats['processed'] >= $batchSize) {
            break;
        }
        $fatturaId = (int)($row['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }

        $rowRetryableSyncError = fic_cron_is_retryable_sync_error((string)($row['ei_error'] ?? ''))
            || fic_cron_is_retryable_sync_error((string)($row['mail_error'] ?? ''));

        if (!$includeSyncErrors && !$rowRetryableSyncError && isset($skipIds[(string)$fatturaId])) {
            $stats['skipped_local']++;
            continue;
        }

        $stats['processed']++;

        try {
            $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
            $righeStmt->execute([':id' => $fatturaId]);
            $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $syncCfg = $cfg;
            $syncCfg['force_e_invoice'] = false;

            [$docId, $status] = fic_create_if_missing($pdo, $row, $righe, $syncCfg, $companyId);
            if (!$docId) {
                throw new RuntimeException('Documento FIC non creato');
            }
            fic_publish_document_if_draft($companyId, (string)$docId);

            if (!empty($row['fic_document_id'])) {
                $stats['linked']++;
            } else {
                $stats['created']++;
            }

            fatture_log($pdo, $fatturaId, 'cron_fic_sync_no_sdi', 'ok', 'Sync cron FIC (no SDI) completata.', [
                'fic_document_id' => $docId,
                'fic_status' => $status,
            ]);
        } catch (Throwable $e) {
            $err = substr((string)$e->getMessage(), 0, 600);
            $stats['failed']++;
            $isDuplicateUnresolved = false;
            if (count($stats['error_samples']) < 5) {
                $stats['error_samples'][] = '#' . $fatturaId . ': ' . $err;
            }

            if (fic_cron_is_duplicate_not_recoverable($err)) {
                try {
                    $pdo->prepare("UPDATE fatture SET fic_status = 'duplicate_unresolved', fic_synced_at = NOW() WHERE id = :id")
                        ->execute([':id' => $fatturaId]);
                    $stats['duplicates_marked']++;
                    unset($skipIds[(string)$fatturaId]);
                    $isDuplicateUnresolved = true;
                } catch (Throwable $ignoreDup) {
                }
            }

            if (fic_cron_is_usage_limit_error($err)) {
                $cooldownMinutes = 60;
                fic_cron_set_usage_cooldown($cooldownMinutes, $err);
                $stats['usage_limited'] = true;
                $stats['retry_after'] = fic_cron_iso_from_ts(time() + ($cooldownMinutes * 60));
                try {
                    fatture_log($pdo, $fatturaId, 'cron_fic_sync_no_sdi', 'warning', 'Stop cron sync FIC: quota API raggiunta, pausa 60 minuti.');
                } catch (Throwable $ignoreLog) {
                }
                break;
            }

            if ($isDuplicateUnresolved) {
                try {
                    fatture_log($pdo, $fatturaId, 'cron_fic_sync_no_sdi', 'warning', 'Duplicato FIC non recuperabile: marcata duplicate_unresolved e esclusa dai retry automatici.');
                } catch (Throwable $ignoreLog) {
                }
                continue;
            }

            $isRetryableSyncError = fic_cron_is_retryable_sync_error($err);

            // Evita che una fattura con errore strutturale blocchi i batch successivi.
            if (!$isRetryableSyncError) {
                $skipIds[(string)$fatturaId] = time();
            } else {
                unset($skipIds[(string)$fatturaId]);
            }
            try {
                $pdo->prepare("UPDATE fatture SET fic_status = 'sync_error', fic_synced_at = NOW() WHERE id = :id")
                    ->execute([':id' => $fatturaId]);
            } catch (Throwable $ignoreSyncErr) {
            }

            try {
                fatture_log(
                    $pdo,
                    $fatturaId,
                    'cron_fic_sync_no_sdi',
                    $isRetryableSyncError ? 'warning' : 'error',
                    ($isRetryableSyncError ? 'Errore transitorio sync cron FIC, verra ritentato: ' : 'Errore sync cron FIC: ') . substr($err, 0, 180)
                );
            } catch (Throwable $ignoreLog) {
            }
        }

        usleep(300000);
    }

    fic_cron_save_skip_ids($skipIds);

    if (!$stats['usage_limited']) {
        fic_cron_clear_usage_cooldown();
    }

    if ($runId > 0) {
        try {
            $details = json_encode($stats, JSON_UNESCAPED_UNICODE);
            $status = $stats['failed'] > 0 ? 'error' : 'ok';
            $pdo->prepare("UPDATE cron_sync_log SET finished_at = NOW(), status = ?, imported = ?, invoices_created = ?, errors = ?, details = ? WHERE id = ?")
                ->execute([
                    $status,
                    (int)$stats['processed'],
                    (int)$stats['created'],
                    (int)$stats['failed'],
                    $details,
                    $runId,
                ]);
        } catch (Throwable $e) {
        }
    }

    echo json_encode(['ok' => true, 'job' => 'fic_sync_no_sdi', 'cliente_ids' => $clienteIds, 'fattura_ids' => $fatturaIds, 'include_sync_errors' => $includeSyncErrors] + $stats, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $msg = substr((string)$e->getMessage(), 0, 260);
    if ($runId > 0) {
        try {
            $pdo->prepare("UPDATE cron_sync_log SET finished_at = NOW(), status = 'error', imported = 0, invoices_created = 0, errors = 1, details = ? WHERE id = ?")
                ->execute([json_encode(['fatal' => $msg], JSON_UNESCAPED_UNICODE), $runId]);
        } catch (Throwable $ignore) {
        }
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
