<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_audit.php';

$pdo = require __DIR__ . '/../config/db.php';

$GLOBALS['FIC_API_MAX_ATTEMPTS'] = 2;
$GLOBALS['FIC_API_CONNECT_TIMEOUT'] = 5;
$GLOBALS['FIC_API_TIMEOUT'] = 14;

function retry_transient_is_limit_error(string $msg): bool
{
    return fic_is_usage_limit_error_message($msg);
}

function retry_transient_is_db_gone(string $msg): bool
{
    $m = strtolower($msg);
    return strpos($m, 'server has gone away') !== false
        || strpos($m, 'lost connection') !== false;
}

function retry_transient_refresh_pdo(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        unset($GLOBALS['_tdg_pdo']);
        if (function_exists('get_db')) {
            $pdo = get_db(true);
            return;
        }
    }

    $pdo = require __DIR__ . '/../config/db.php';
}

function retry_transient_usage_limit_file(): string
{
    return __DIR__ . '/../tmp/fic_usage_limit_until.json';
}

function retry_transient_iso_from_ts(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function retry_transient_set_cooldown(int $minutes, string $reason): void
{
    $untilTs = time() + max(1, $minutes) * 60;
    $payload = [
        'until_ts' => $untilTs,
        'until_iso' => retry_transient_iso_from_ts($untilTs),
        'reason' => substr($reason, 0, 300),
        'updated_at' => retry_transient_iso_from_ts(time()),
        'source' => 'retry_transient_sync_errors',
    ];
    @file_put_contents(retry_transient_usage_limit_file(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function retry_transient_get_cooldown(): ?array
{
    $path = retry_transient_usage_limit_file();
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
        'until_iso' => (string)($data['until_iso'] ?? retry_transient_iso_from_ts($untilTs)),
        'reason' => (string)($data['reason'] ?? ''),
        'source' => (string)($data['source'] ?? ''),
    ];
}

function retry_transient_clear_cooldown(): void
{
    $path = retry_transient_usage_limit_file();
    if (is_file($path)) {
        @unlink($path);
    }
}

function retry_transient_log(PDO $pdo, ?int $fatturaId, string $azione, string $esito, string $messaggio, array $meta = []): void
{
    try {
        fic_ensure_db_connection($pdo);
        fatture_log($pdo, $fatturaId, $azione, $esito, $messaggio, $meta);
    } catch (Throwable $e) {
    }
}

try {
    $cooldown = retry_transient_get_cooldown();
    $nowTs = time();
    if (is_array($cooldown) && $nowTs >= (int)$cooldown['until_ts']) {
        retry_transient_clear_cooldown();
        $cooldown = null;
    }
    if (is_array($cooldown) && $nowTs < (int)$cooldown['until_ts']) {
        echo json_encode([
            'ok' => true,
            'skipped' => true,
            'reason' => 'usage_limit_cooldown',
            'now_ts' => $nowTs,
            'now_iso' => retry_transient_iso_from_ts($nowTs),
            'cooldown_until_ts' => (int)$cooldown['until_ts'],
            'retry_after' => $cooldown['until_iso'],
            'source' => $cooldown['source'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT started_at FROM cron_sync_log WHERE job_name = 'fic_sync_no_sdi' AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
        $lockStmt->execute();
        if ($lockStmt->fetch()) {
            echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Sync FIC gia in corso'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    } catch (Throwable $e) {
    }

    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $limit = max(1, min(10, (int)($_GET['limit'] ?? 5)));

    $sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
              AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
              AND LOWER(COALESCE(f.fic_status, '')) = 'sync_error'
              AND (
                    LOWER(COALESCE(f.ei_error, '')) LIKE '%server has gone away%'
                    OR LOWER(COALESCE(f.ei_error, '')) LIKE '%lost connection%'
                    OR LOWER(COALESCE(f.mail_error, '')) LIKE '%server has gone away%'
                    OR LOWER(COALESCE(f.mail_error, '')) LIKE '%lost connection%'
                  )
            ORDER BY f.data_emissione ASC, f.id ASC
            LIMIT " . $limit;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [
        'ok' => true,
        'selected' => count($rows),
        'retried' => 0,
        'created' => 0,
        'failed' => 0,
        'skipped_missing_data' => 0,
        'usage_limited' => false,
        'retry_after' => null,
        'items' => [],
    ];

    foreach ($rows as $fattura) {
        $fatturaId = (int)($fattura['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }

        $stats['retried']++;

        $missingDataError = fic_einvoice_missing_data_error($fattura);
        if ($missingDataError !== null) {
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $missingDataError, false);
            $stats['skipped_missing_data']++;
            $stats['items'][] = [
                'id' => $fatturaId,
                'numero' => (string)($fattura['numero'] ?? ''),
                'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
                'status' => 'skipped_missing_data',
                'message' => $missingDataError,
            ];
            continue;
        }

        $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
        $righeStmt->execute([':id' => $fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        try {
            retry_transient_refresh_pdo($pdo);

            $syncCfg = $cfg;
            $syncCfg['force_e_invoice'] = false;

            [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $syncCfg, $companyId);
            if (!$docId) {
                throw new RuntimeException('Documento FIC non creato');
            }

            fic_publish_document_if_draft($companyId, (string)$docId);

            $stateStmt = $pdo->prepare('SELECT fic_document_id, fic_status, ei_status, ei_error FROM fatture WHERE id = :id LIMIT 1');
            $stateStmt->execute([':id' => $fatturaId]);
            $state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            retry_transient_log($pdo, $fatturaId, 'cron_retry_transient_sync', 'ok', 'Retry automatico sync_error transitorio completato.', [
                'fic_document_id' => $state['fic_document_id'] ?? null,
                'fic_status' => $state['fic_status'] ?? null,
            ]);

            $stats['created']++;
            $stats['items'][] = [
                'id' => $fatturaId,
                'numero' => (string)($state['numero'] ?? $fattura['numero'] ?? ''),
                'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
                'status' => 'ok',
                'fic_document_id' => $state['fic_document_id'] ?? $docId,
                'fic_status' => $state['fic_status'] ?? $status,
            ];
        } catch (Throwable $e) {
            $message = substr((string)$e->getMessage(), 0, 500);

            if (retry_transient_is_db_gone($message)) {
                retry_transient_refresh_pdo($pdo);
            }

            try {
                $pdo->prepare("UPDATE fatture SET fic_status = 'sync_error', fic_synced_at = NOW() WHERE id = :id")
                    ->execute([':id' => $fatturaId]);
            } catch (Throwable $ignored) {
            }

            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $message, false);
            retry_transient_log($pdo, $fatturaId, 'cron_retry_transient_sync', 'error', 'Retry automatico sync_error transitorio fallito: ' . substr($message, 0, 180));

            $stats['failed']++;
            $stats['items'][] = [
                'id' => $fatturaId,
                'numero' => (string)($fattura['numero'] ?? ''),
                'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
                'status' => 'error',
                'message' => $message,
            ];

            if (retry_transient_is_limit_error($message)) {
                $cooldownMinutes = 60;
                retry_transient_set_cooldown($cooldownMinutes, $message);
                $stats['usage_limited'] = true;
                $stats['retry_after'] = retry_transient_iso_from_ts(time() + ($cooldownMinutes * 60));
                break;
            }
        }
    }

    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}