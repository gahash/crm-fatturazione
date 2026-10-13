<?php
require __DIR__ . '/../../../auth.php';
require_login();
if (!has_admin_access()) {
    http_response_code(403);
    die('Accesso non autorizzato.');
}

$pdo = require __DIR__ . '/../../../config/db.php';
$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');

require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

// Evita richieste API troppo lunghe durante i batch (riduce 500/503 su hosting condiviso).
$GLOBALS['FIC_API_MAX_ATTEMPTS'] = 3;
$GLOBALS['FIC_API_CONNECT_TIMEOUT'] = 6;
$GLOBALS['FIC_API_TIMEOUT'] = 18;

function fic_sync_all_is_ajax_request(): bool
{
    if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

function fic_sync_all_respond(string $baseUrl, array $params, ?string $error = null, int $statusCode = 200): void
{
    if (fic_sync_all_is_ajax_request()) {
        // In AJAX rispondiamo sempre 200 per evitare errori browser "hard" su chiamate batch.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $error === null,
            'error' => $error,
            'http_status' => $statusCode,
            'total' => (int)($params['fic_sync_all_total'] ?? 0),
            'created' => (int)($params['fic_sync_all_ok'] ?? 0),
            'linked' => (int)($params['fic_sync_all_linked'] ?? 0),
            'failed' => (int)($params['fic_sync_all_failed'] ?? 0),
            'error_sample' => (string)($params['fic_sync_all_error_sample'] ?? ''),
            'candidate_total' => (int)($params['fic_sync_all_candidate_total'] ?? 0),
            'limit' => (int)($params['fic_sync_all_limit'] ?? 0),
            'offset' => (int)($params['fic_sync_all_offset'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($error !== null) {
        header('Location: ' . $baseUrl . '/modules/fatture/index.php?fic_sync_all_error=' . urlencode($error));
        exit;
    }

    header('Location: ' . $baseUrl . '/modules/fatture/index.php?' . http_build_query($params));
    exit;
}

function fic_sync_all_is_db_gone(string $msg): bool
{
    $m = strtolower($msg);
    return strpos($m, 'server has gone away') !== false
        || strpos($m, 'lost connection') !== false
        || strpos($m, 'gone away') !== false;
}

function fic_sync_all_is_usage_limit_error(string $msg): bool
{
    return fic_is_usage_limit_error_message($msg);
}

function fic_sync_all_is_duplicate_not_recoverable(string $msg): bool
{
    return strpos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0;
}

function fic_sync_all_refresh_pdo(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        unset($GLOBALS['_tdg_pdo']);
        $pdo = require __DIR__ . '/../../../config/db.php';
    }
}

$total = 0;
$ok = 0;
$failed = 0;
$linked = 0;
$candidateTotal = 0;
$errorSamples = [];
$usageLimitReached = false;
$usageLimitMessage = '';

// In AJAX intercetta fatal runtime e restituisce sempre JSON, evitando pagine HTML 500.
register_shutdown_function(function () use ($baseUrl, &$total, &$ok, &$linked, &$failed, &$candidateTotal) {
    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$lastError['type'], $fatalTypes, true)) {
        return;
    }

    if (!fic_sync_all_is_ajax_request()) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $msg = substr((string)($lastError['message'] ?? 'Fatal error'), 0, 220);
    $params = [
        'fic_sync_all_total' => (int)$total,
        'fic_sync_all_ok' => (int)$ok,
        'fic_sync_all_linked' => (int)$linked,
        'fic_sync_all_failed' => max((int)$failed, 1),
        'fic_sync_all_candidate_total' => (int)$candidateTotal,
    ];
    fic_sync_all_respond($baseUrl, $params, $msg !== '' ? $msg : 'fatal_runtime_error', 500);
});

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        fic_sync_all_respond($baseUrl, [], 'missing_company', 400);
    }

    $docType = trim((string)($_GET['document_type'] ?? 'invoice'));
    if ($docType === '') {
        $docType = 'invoice';
    }

    $forceMode = (int)($_GET['force'] ?? 0);
    $force = in_array($forceMode, [1, 2], true);
    $repairNoSdi = ($forceMode === 3);

    $limit = (int)($_GET['limit'] ?? 0);
    if ($limit < 0) {
        $limit = 0;
    }

    // Protezioni anti-timeout su hosting condiviso.
    if ($forceMode === 1) {
        if ($limit <= 0 || $limit > 1) {
            $limit = 1;
        }
    }
    if ($repairNoSdi) {
        if ($limit <= 0 || $limit > 5) {
            $limit = 5;
        }
    }

    $offset = (int)($_GET['offset'] ?? 0);
    if ($offset < 0) {
        $offset = 0;
    }

    $where = "f.document_type = :docType";
    if ($repairNoSdi) {
        $where .= " AND (f.ei_status IS NULL OR TRIM(f.ei_status) = '' OR LOWER(f.ei_status) NOT IN ('sent','processing','accepted','ok'))";
    } elseif (!$force) {
        $where .= " AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')";
        $where .= " AND (f.fic_status IS NULL OR f.fic_status <> 'duplicate_unresolved')";
    }

    fic_sync_all_refresh_pdo($pdo);
    $countStmt = $pdo->prepare("SELECT COUNT(*)
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE {$where}");
    $countStmt->execute([':docType' => $docType]);
    $candidateTotal = (int)$countStmt->fetchColumn();

    $sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE {$where}
            ORDER BY f.id ASC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }
    }

    fic_sync_all_refresh_pdo($pdo);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':docType' => $docType]);

    while (true) {
        $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fattura) {
            break;
        }

        fic_sync_all_refresh_pdo($pdo);
        $total++;

        $fatturaId = (int)($fattura['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }

        try {
            fic_sync_all_refresh_pdo($pdo);
            $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
            $righeStmt->execute([':id' => $fatturaId]);
            $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

            $hadBefore = !empty($fattura['fic_document_id']);

            $syncCfg = $cfg;
            $syncCfg['force_e_invoice'] = false;
            $syncCfg['_skip_post_create_reachability_check'] = true;

            [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $syncCfg, $companyId);
            if (!$docId) {
                throw new RuntimeException('Documento FIC non creato.');
            }

            if ($hadBefore) {
                fic_publish_document_if_draft($companyId, (string)$docId);
            } else {
                fic_publish_document_if_draft_ex($companyId, (string)$docId, $fattura, $docType, true);
            }

            if ($hadBefore) {
                $linked++;
            } else {
                $ok++;
            }

            fatture_log($pdo, $fatturaId, 'fic_sync_all', 'ok', 'Sincronizzazione massiva FIC completata.', [
                'fic_document_id' => $docId,
                'fic_status' => $status,
                'already_linked' => $hadBefore,
            ]);
        } catch (Throwable $e) {
            $failed++;
            $err = substr((string)$e->getMessage(), 0, 1000);
            if (fic_sync_all_is_db_gone($err)) {
                fic_sync_all_refresh_pdo($pdo);
            }

            if (fic_sync_all_is_duplicate_not_recoverable($err)) {
                try {
                    $dup = $pdo->prepare("UPDATE fatture SET fic_status = 'duplicate_unresolved', fic_synced_at = NOW() WHERE id = :id");
                    $dup->execute([':id' => $fatturaId]);
                    $err = 'FIC_DUPLICATE_NOT_RECOVERABLE marcato come duplicate_unresolved';
                } catch (Throwable $ignoreDup) {
                    // no-op
                }
            }

            if (fic_sync_all_is_usage_limit_error($err)) {
                $usageLimitReached = true;
                $usageLimitMessage = $err;
            }

            if (count($errorSamples) < 5) {
                $errorSamples[] = '#' . $fatturaId . ': ' . $err;
            }

            try {
                fatture_log($pdo, $fatturaId, 'fic_sync_all', 'error', 'Errore sincronizzazione massiva FIC: ' . substr($err, 0, 180), [
                    'numero' => $fattura['numero'] ?? null,
                ]);
            } catch (Throwable $ignoreLog) {
            }

            if (strpos($err, 'HTTP 429') !== false) {
                usleep(1500000);
            }
        }

        if ($usageLimitReached) {
            break;
        }

        usleep(300000);
    }

    try {
        fatture_log($pdo, null, 'fic_sync_all', $failed > 0 ? 'error' : 'ok', 'Sincronizzazione massiva FIC terminata.', [
            'total' => $total,
            'created' => $ok,
            'linked' => $linked,
            'failed' => $failed,
            'force' => $force,
            'force_mode' => $forceMode,
            'repair_no_sdi' => $repairNoSdi,
            'limit' => $limit > 0 ? $limit : null,
            'offset' => $offset > 0 ? $offset : null,
        ]);
    } catch (Throwable $ignoreLog) {
    }

    $params = [
        'fic_sync_all_total' => $total,
        'fic_sync_all_ok' => $ok,
        'fic_sync_all_linked' => $linked,
        'fic_sync_all_failed' => $failed,
        'fic_sync_all_candidate_total' => $candidateTotal,
        'fic_sync_all_limit' => $limit,
        'fic_sync_all_offset' => $offset,
    ];
    if ($errorSamples) {
        $params['fic_sync_all_error_sample'] = implode(' | ', $errorSamples);
    }

    if ($usageLimitReached) {
        $params['fic_sync_all_error_sample'] = trim(($params['fic_sync_all_error_sample'] ?? '') . ' | STOP: limite API FIC raggiunto, batch interrotto.');
    }

    fic_sync_all_respond($baseUrl, $params, null, 200);
} catch (Throwable $fatalSyncError) {
    $fatal = substr((string)$fatalSyncError->getMessage(), 0, 220);

    try {
        fatture_log($pdo, null, 'fic_sync_all', 'error', 'Sync massiva interrotta: ' . substr($fatal, 0, 180));
    } catch (Throwable $ignoreLog) {
    }

    $params = [
        'fic_sync_all_total' => $total,
        'fic_sync_all_ok' => $ok,
        'fic_sync_all_linked' => $linked,
        'fic_sync_all_failed' => max($failed, 1),
        'fic_sync_all_candidate_total' => $candidateTotal,
        'fic_sync_all_limit' => isset($limit) ? (int)$limit : 0,
        'fic_sync_all_offset' => isset($offset) ? (int)$offset : 0,
    ];
    fic_sync_all_respond($baseUrl, $params, ($fatal !== '' ? $fatal : 'sync_runtime_error'), 500);
}
