<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/actions/fic_audit.php';
require_once __DIR__ . '/actions/fic_api.php';
require_once __DIR__ . '/actions/fic_alert_helpers.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$autoSendFilter = isset($_GET['auto_send']) ? trim((string)$_GET['auto_send']) : '';
$sdiFlow = isset($_GET['sdi_flow']) ? trim((string)$_GET['sdi_flow']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPageOptions = [10, 20, 30, 50, 100];
$perPage = (isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions, true))
    ? (int)$_GET['per_page']
    : 10;

// Paginazione separata per i log/attivita'
$logsPage = isset($_GET['logs_page']) ? max(1, (int)$_GET['logs_page']) : 1;
$logsPerPageOptions = [10, 20, 30, 50];
$logsPerPage = (isset($_GET['logs_per_page']) && in_array((int)$_GET['logs_per_page'], $logsPerPageOptions, true))
    ? (int)$_GET['logs_per_page']
    : 20;

function fatture_index_extract_documents(array $response): array
{
    if (empty($response['json']) || !is_array($response['json'])) {
        return [];
    }

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

function fatture_index_count_sdi_sent_realtime(string $companyId, bool $forceRefresh = false, int $cacheTtl = 300): array
{
    $cacheDir = __DIR__ . '/../../tmp';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $safeCompanyId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $companyId);
    $cacheFile = rtrim($cacheDir, '/\\') . '/fic_sdi_sent_count_' . $safeCompanyId . '.json';
    $now = time();

    if (!$forceRefresh && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cached = json_decode((string)$raw, true);
        if (is_array($cached)) {
            $cachedAt = (int)($cached['cached_at'] ?? 0);
            if ($cachedAt > 0 && ($now - $cachedAt) <= $cacheTtl) {
                return [
                    'count' => (int)($cached['count'] ?? 0),
                    'cached_at' => $cachedAt,
                    'from_cache' => true,
                ];
            }
        }
    }

    $doneStatuses = [
        'sent', 'processing', 'accepted', 'ok',
        'inviata', 'inviato', 'delivered', 'completed',
    ];
    $count = 0;
    $page = 1;
    $lastPage = 1;
    $maxPages = 500;

    do {
        $query = http_build_query([
            'type' => 'invoice',
            'per_page' => 100,
            'page' => $page,
            'e_invoice' => 'true',
        ]);
        $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Conteggio SDI FIC fallito (HTTP ' . $status . ').');
        }

        $documents = fatture_index_extract_documents($response);
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $eiStatus = strtolower(trim((string)($doc['ei_status'] ?? '')));
            if (in_array($eiStatus, $doneStatuses, true)) {
                $count++;
            }
        }

        $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
        if ($lastPage <= 0) {
            $lastPage = 1;
        }
        $page++;
    } while ($page <= $lastPage && $page <= $maxPages);

    $payload = [
        'count' => $count,
        'cached_at' => $now,
    ];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return [
        'count' => $count,
        'cached_at' => $now,
        'from_cache' => false,
    ];
}

function fatture_index_count_linked_realtime(PDO $pdo, string $companyId, string $docType = 'invoice', bool $forceRefresh = false, int $cacheTtl = 300): array
{
    $cacheDir = __DIR__ . '/../../tmp';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $safeCompanyId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $companyId);
    $cacheFile = rtrim($cacheDir, '/\\') . '/fic_linked_count_' . $safeCompanyId . '.json';
    $now = time();

    if (!$forceRefresh && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cached = json_decode((string)$raw, true);
        if (is_array($cached)) {
            $cachedAt = (int)($cached['cached_at'] ?? 0);
            if ($cachedAt > 0 && ($now - $cachedAt) <= $cacheTtl) {
                return [
                    'local_count' => (int)($cached['local_count'] ?? 0),
                    'reachable_count' => (int)($cached['reachable_count'] ?? 0),
                    'missing_count' => (int)($cached['missing_count'] ?? 0),
                    'cached_at' => $cachedAt,
                    'from_cache' => true,
                ];
            }
        }
    }

    $localCount = 0;
    $reachableCount = 0;
    $missingCount = 0;

    $stmt = $pdo->prepare("
        SELECT TRIM(fic_document_id) AS doc_id, COUNT(*) AS qty
        FROM fatture
        WHERE document_type = :docType
          AND fic_document_id IS NOT NULL
          AND TRIM(fic_document_id) <> ''
        GROUP BY TRIM(fic_document_id)
    ");
    $stmt->execute([':docType' => $docType]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($groups as $g) {
        $docId = trim((string)($g['doc_id'] ?? ''));
        $qty = (int)($g['qty'] ?? 0);
        if ($docId === '' || $qty <= 0) {
            continue;
        }
        $localCount += $qty;

        try {
            $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
            $status = (int)($resp['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $reachableCount += $qty;
            } else {
                $missingCount += $qty;
            }
        } catch (Throwable $e) {
            $missingCount += $qty;
        }
    }

    $payload = [
        'local_count' => $localCount,
        'reachable_count' => $reachableCount,
        'missing_count' => $missingCount,
        'cached_at' => $now,
    ];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return [
        'local_count' => $localCount,
        'reachable_count' => $reachableCount,
        'missing_count' => $missingCount,
        'cached_at' => $now,
        'from_cache' => false,
    ];
}

$params = [];
$sdiSentCount = 0;
$sdiSentCountLocal = 0;
$sdiSentCountReal = null;
$sdiCountError = null;
$sdiCountSource = 'local';
$sdiCountCachedAt = null;
$ficLinkedCount = 0;
$ficLinkedCountLocal = 0;
$ficLinkedCountReal = null;
$ficLinkedMissingCount = 0;
$ficLinkedCountSource = 'local';
$ficLinkedCountError = null;
$ficLinkedCountCachedAt = null;
$sdiIssueAlertCount = 0;
$sdiIssueAlertBuckets = [];
$sdiIssueAlertRows = [];
$sdiInProgressAlertCount = 0;
$sdiInProgressAlertRows = [];

$docType = 'invoice';
$baseSql = "FROM fatture f JOIN clienti c ON c.id = f.cliente_id";
$where = " WHERE f.document_type = :docType";
$params[':docType'] = $docType;
if ($q !== '') {
    $where .= " AND (f.numero LIKE :q_num OR c.nome LIKE :q_name OR c.email LIKE :q_email)";
    $params[':q_num'] = '%' . $q . '%';
    $params[':q_name'] = '%' . $q . '%';
    $params[':q_email'] = '%' . $q . '%';
}
if ($autoSendFilter === 'stripe_skipped') {
    $where .= " AND f.stato = 'pagata' AND f.metodo_pagamento = 'Stripe' AND f.mail_status = 'pending' AND f.mail_error LIKE :mail_error";
    $params[':mail_error'] = 'Pagamento Stripe registrato: invio automatico non eseguito%';
}
if ($sdiFlow === 'ready') {
    $where .= " AND f.fic_document_id IS NOT NULL AND TRIM(f.fic_document_id) <> ''";
    $where .= " AND (f.fic_status IS NULL OR f.fic_status <> 'duplicate_unresolved')";
    $where .= " AND (f.ei_status IS NULL OR TRIM(f.ei_status) = '' OR LOWER(f.ei_status) NOT IN ('sent','processing','accepted'))";
} elseif ($sdiFlow === 'sent') {
    $where .= " AND LOWER(COALESCE(f.ei_status, '')) IN ('sent','processing','accepted')";
} elseif ($sdiFlow === 'issues') {
    $where .= " AND (LOWER(COALESCE(f.ei_status, '')) = 'error' OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved' OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> ''))";
    $where .= " AND NOT " . fatture_index_sdi_in_progress_sql('f');
}

$dbError = null;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql $where");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

        $sql = "SELECT f.*, c.nome, c.email AS cliente_email,
                                     (
                                             SELECT fl.creato_il
                                             FROM fatture_log fl
                                             WHERE fl.fattura_id = f.id
                                                 AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_retry_send')
                                             ORDER BY fl.creato_il DESC, fl.id DESC
                                             LIMIT 1
                                     ) AS auto_sdi_last_event_at,
                                     (
                                             SELECT fl.esito
                                             FROM fatture_log fl
                                             WHERE fl.fattura_id = f.id
                                                 AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_retry_send')
                                             ORDER BY fl.creato_il DESC, fl.id DESC
                                             LIMIT 1
                                     ) AS auto_sdi_last_event_outcome,
                                     (
                                             SELECT fl.messaggio
                                             FROM fatture_log fl
                                             WHERE fl.fattura_id = f.id
                                                 AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_retry_send')
                                             ORDER BY fl.creato_il DESC, fl.id DESC
                                             LIMIT 1
                                     ) AS auto_sdi_last_event_msg
                        $baseSql $where ORDER BY f.data_emissione DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $fatture = $stmt->fetchAll();

    $sdiSentCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM fatture
        WHERE document_type = :docType
          AND LOWER(COALESCE(ei_status, '')) IN ('sent','processing','accepted')
    ");
    $sdiSentCountStmt->execute([':docType' => $docType]);
    $sdiSentCountLocal = (int)$sdiSentCountStmt->fetchColumn();
    $sdiSentCount = $sdiSentCountLocal;

    $ficLinkedStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM fatture
        WHERE document_type = :docType
          AND fic_document_id IS NOT NULL
          AND TRIM(fic_document_id) <> ''
    ");
    $ficLinkedStmt->execute([':docType' => $docType]);
    $ficLinkedCountLocal = (int)$ficLinkedStmt->fetchColumn();
    $ficLinkedCount = $ficLinkedCountLocal;

    $sdiIssueCountStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM fatture f\n        WHERE f.document_type = :docType\n          AND (\n              LOWER(COALESCE(f.ei_status, '')) IN ('error', 'duplicate_unresolved')\n              OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'\n              OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> '')\n              OR (f.mail_error IS NOT NULL AND f.mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')\n          )\n          AND NOT " . fatture_index_sdi_in_progress_sql('f'));
    $sdiIssueCountStmt->execute([':docType' => $docType]);
    $sdiIssueAlertCount = (int)$sdiIssueCountStmt->fetchColumn();

    $sdiInProgressCountStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM fatture f\n        WHERE f.document_type = :docType\n          AND " . fatture_index_sdi_in_progress_sql('f'));
    $sdiInProgressCountStmt->execute([':docType' => $docType]);
    $sdiInProgressAlertCount = (int)$sdiInProgressCountStmt->fetchColumn();

    $sdiIssueBucketStmt = $pdo->prepare("\n        SELECT\n            " . tdg_sdi_issue_bucket_case_sql('f') . " AS reason_bucket,\n            COUNT(*) AS total_rows\n        FROM fatture f\n        WHERE f.document_type = :docType\n          AND (\n              LOWER(COALESCE(f.ei_status, '')) IN ('error', 'duplicate_unresolved')\n              OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'\n              OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> '')\n              OR (f.mail_error IS NOT NULL AND f.mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')\n          )\n          AND NOT " . fatture_index_sdi_in_progress_sql('f') . "\n        GROUP BY reason_bucket\n        ORDER BY total_rows DESC, reason_bucket ASC\n        LIMIT 5\n    ");
    $sdiIssueBucketStmt->execute([':docType' => $docType]);
    $sdiIssueAlertBuckets = $sdiIssueBucketStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sdiIssueRowsStmt = $pdo->prepare("\n        SELECT f.id, f.numero, f.data_emissione, f.ei_status, f.fic_status, f.ei_error, f.mail_error,\n               c.nome,\n               (\n                   SELECT fl.messaggio\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_msg\n        FROM fatture f\n        JOIN clienti c ON c.id = f.cliente_id\n        WHERE f.document_type = :docType\n          AND (\n              LOWER(COALESCE(f.ei_status, '')) IN ('error', 'duplicate_unresolved')\n              OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'\n              OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> '')\n              OR (f.mail_error IS NOT NULL AND f.mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')\n          )\n          AND NOT " . fatture_index_sdi_in_progress_sql('f') . "\n        ORDER BY f.data_emissione DESC, f.id DESC\n        LIMIT 6\n    ");
    $sdiIssueRowsStmt->execute([':docType' => $docType]);
    $sdiIssueAlertRows = $sdiIssueRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($sdiIssueAlertRows as &$sdiIssueAlertRow) {
        $sdiIssueAlertRow['alert_reason'] = fatture_index_sdi_issue_reason($sdiIssueAlertRow);
        $sdiIssueAlertRow['alert_bucket'] = fatture_index_sdi_issue_bucket($sdiIssueAlertRow);
    }
    unset($sdiIssueAlertRow);

    $sdiInProgressRowsStmt = $pdo->prepare("\n        SELECT f.id, f.numero, f.data_emissione, f.ei_status, f.fic_status, f.ei_error, f.mail_error,\n               c.nome,\n               (\n                   SELECT fl.messaggio\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_msg\n        FROM fatture f\n        JOIN clienti c ON c.id = f.cliente_id\n        WHERE f.document_type = :docType\n          AND " . fatture_index_sdi_in_progress_sql('f') . "\n        ORDER BY f.data_emissione DESC, f.id DESC\n        LIMIT 6\n    ");
    $sdiInProgressRowsStmt->execute([':docType' => $docType]);
    $sdiInProgressAlertRows = $sdiInProgressRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Carica log con paginazione
    $totalLogs = fatture_count_logs($pdo);
    $totalLogsPages = max(1, (int)ceil($totalLogs / $logsPerPage));
    if ($logsPage > $totalLogsPages) {
        $logsPage = $totalLogsPages;
    }
    $logsOffset = ($logsPage - 1) * $logsPerPage;
    $recentLogs = fatture_recent_logs($pdo, $logsPerPage, null, $logsOffset);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $totalRows = 0;
    $totalPages = 1;
    $page = 1;
    $fatture = [];
    $recentLogs = [];
    $totalLogs = 0;
    $totalLogsPages = 1;
    $logsPage = 1;
    $sdiSentCountLocal = 0;
    $sdiSentCount = 0;
    $ficLinkedCountLocal = 0;
    $ficLinkedCount = 0;
    $sdiIssueAlertCount = 0;
    $sdiIssueAlertBuckets = [];
    $sdiIssueAlertRows = [];
    $sdiInProgressAlertCount = 0;
    $sdiInProgressAlertRows = [];
}

if (empty($dbError)) {
    try {
        $companyId = (string)fic_get_company_id();
        $token = (string)fic_get_access_token();
        $forceSdiRefresh = isset($_GET['refresh_sdi']) && (string)$_GET['refresh_sdi'] === '1';
        $forceLinksRefresh = isset($_GET['refresh_links']) && (string)$_GET['refresh_links'] === '1';
        if ($companyId !== '' && $token !== '' && ($forceSdiRefresh || $forceLinksRefresh)) {
            $sdiReal = fatture_index_count_sdi_sent_realtime($companyId, $forceSdiRefresh, 300);
            $sdiSentCountReal = (int)($sdiReal['count'] ?? 0);
            $sdiSentCount = $sdiSentCountReal;
            $sdiCountSource = 'fic';
            $sdiCountCachedAt = isset($sdiReal['cached_at']) ? (int)$sdiReal['cached_at'] : null;

            $linkedReal = fatture_index_count_linked_realtime($pdo, $companyId, $docType, $forceLinksRefresh, 300);
            $ficLinkedCountReal = (int)($linkedReal['reachable_count'] ?? 0);
            $ficLinkedMissingCount = (int)($linkedReal['missing_count'] ?? 0);
            $ficLinkedCount = $ficLinkedCountReal;
            $ficLinkedCountSource = 'fic';
            $ficLinkedCountCachedAt = isset($linkedReal['cached_at']) ? (int)$linkedReal['cached_at'] : null;
        }
    } catch (Throwable $e) {
        $err = substr((string)$e->getMessage(), 0, 180);
        if ($sdiCountError === null) {
            $sdiCountError = $err;
        }
        if ($ficLinkedCountError === null) {
            $ficLinkedCountError = $err;
        }
        $sdiCountSource = 'local';
        $ficLinkedCountSource = 'local';
    }
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <?php if (!empty($dbError)): ?>
        <div class="alert error">&#9888;&#65039; Errore database: <?php echo htmlspecialchars((string)$dbError); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_oauth_error'])): ?>
        <div class="alert error">Connessione Fatture in Cloud non riuscita: <?php echo htmlspecialchars((string)($_GET['fic_oauth_error_desc'] ?? (string)$_GET['fic_oauth_error'])); ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_connected']) && (string)$_GET['fic_connected'] === '1'): ?>
        <div class="alert success">Connessione Fatture in Cloud aggiornata<?php echo !empty($_GET['fic_company']) ? ' (company_id ' . htmlspecialchars((string)$_GET['fic_company']) . ')' : ''; ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['bulk_delete_ok'])): ?>
        <div class="alert success">&#9989; Fatture eliminate: <?php echo htmlspecialchars((string)($_GET['bulk_delete_ok'] ?? '0')); ?>. Saltate (pagate): <?php echo htmlspecialchars((string)($_GET['bulk_delete_skipped'] ?? '0')); ?>.</div>
    <?php elseif (isset($_GET['bulk_delete_error'])): ?>
        <div class="alert error">&#9888;&#65039; Eliminazione multipla non eseguita: <?php echo htmlspecialchars((string)($_GET['bulk_delete_error'] ?? 'selezione non valida')); ?>.</div>
    <?php elseif (isset($_GET['del_error']) && $_GET['del_error'] === 'paid'): ?>
        <div class="alert error">&#9888;&#65039; Non puoi eliminare una fattura gi&agrave; pagata.</div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_delete_all_done'])): ?>
        <div class="alert success">
            Cancellazione totale FIC completata.
            Eliminate: <b><?php echo htmlspecialchars((string)($_GET['fic_delete_all_deleted'] ?? '0')); ?></b>,
            assenti: <b><?php echo htmlspecialchars((string)($_GET['fic_delete_all_missing'] ?? '0')); ?></b>,
            errori: <b><?php echo htmlspecialchars((string)($_GET['fic_delete_all_failed'] ?? '0')); ?></b>,
            link azzerati: <b><?php echo htmlspecialchars((string)($_GET['fic_delete_all_local_cleared'] ?? '0')); ?></b>.
        </div>
        <?php if (!empty($_GET['fic_delete_all_warnings'])): ?>
            <div class="alert error">Errori: <?php echo htmlspecialchars((string)$_GET['fic_delete_all_warnings']); ?>.</div>
        <?php endif; ?>
    <?php elseif (isset($_GET['fic_delete_all_error'])): ?>
        <div class="alert error">Cancellazione totale FIC non eseguita: <?php echo htmlspecialchars((string)($_GET['fic_delete_all_error'] ?? 'errore sconosciuto')); ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['mail_bulk_ok'])): ?>
        <?php $mailBulkMode = trim((string)($_GET['mail_bulk_mode'] ?? '')); ?>
        <div class="alert success">Email inviate: <?php echo htmlspecialchars((string)($_GET['mail_bulk_ok'] ?? '0')); ?>. Errori: <?php echo htmlspecialchars((string)($_GET['mail_bulk_failed'] ?? '0')); ?>. Saltate: <?php echo htmlspecialchars((string)($_GET['mail_bulk_skipped'] ?? '0')); ?>.</div>
        <?php if (($mailBulkMode ?? '') === 'public_link'): ?>
            <div class="alert success">Le email includono il link pubblico PDF.</div>
        <?php endif; ?>
    <?php elseif (isset($_GET['fic_sync_all_total'])): ?>
        <div class="alert success">
            Sync FIC completata.
            Processate: <b><?php echo htmlspecialchars((string)($_GET['fic_sync_all_total'] ?? '0')); ?></b>,
            create: <b><?php echo htmlspecialchars((string)($_GET['fic_sync_all_ok'] ?? '0')); ?></b>,
            collegate: <b><?php echo htmlspecialchars((string)($_GET['fic_sync_all_linked'] ?? '0')); ?></b>,
            errori: <b><?php echo htmlspecialchars((string)($_GET['fic_sync_all_failed'] ?? '0')); ?></b>.
        </div>
        <?php if (!empty($_GET['fic_sync_all_error_sample'])): ?>
            <div class="alert error">Primo errore: <?php echo htmlspecialchars((string)$_GET['fic_sync_all_error_sample']); ?></div>
        <?php endif; ?>
    <?php elseif (isset($_GET['fic_sync_all_error'])): ?>
        <div class="alert error">Sync FIC non avviata: configurazione non valida (company_id/token).</div>
    <?php elseif (isset($_GET['ei_bulk_ok'])): ?>
        <div class="alert success">E-fatture inviate allo SDI: <?php echo htmlspecialchars((string)($_GET['ei_bulk_ok'] ?? '0')); ?>. Errori: <?php echo htmlspecialchars((string)($_GET['ei_bulk_failed'] ?? '0')); ?>. Saltate: <?php echo htmlspecialchars((string)($_GET['ei_bulk_skipped'] ?? '0')); ?>.</div>
    <?php elseif (isset($_GET['ei_bulk_error'])): ?>
        <div class="alert error">Invio SDI non avviato: configurazione Fatture in Cloud non valida.</div>
    <?php elseif (isset($_GET['ei_cancel_bulk_done'])): ?>
        <div class="alert success">
            Annullamento massivo e-fatture completato.
            Eliminate: <b><?php echo htmlspecialchars((string)($_GET['ei_cancel_bulk_deleted'] ?? '0')); ?></b>,
            assenti: <b><?php echo htmlspecialchars((string)($_GET['ei_cancel_bulk_missing'] ?? '0')); ?></b>,
            errori: <b><?php echo htmlspecialchars((string)($_GET['ei_cancel_bulk_failed'] ?? '0')); ?></b>,
            saltate: <b><?php echo htmlspecialchars((string)($_GET['ei_cancel_bulk_skipped'] ?? '0')); ?></b>.
        </div>
        <?php if (!empty($_GET['ei_cancel_bulk_errors'])): ?>
            <div class="alert error">Errori: <?php echo htmlspecialchars((string)$_GET['ei_cancel_bulk_errors']); ?>.</div>
        <?php endif; ?>
    <?php elseif (isset($_GET['ei_cancel_bulk_error'])): ?>
        <div class="alert error">Annullamento massivo e-fatture non eseguito: <?php echo htmlspecialchars((string)($_GET['ei_cancel_bulk_error'] ?? 'errore sconosciuto')); ?>.</div>
    <?php elseif (isset($_GET['mail_bulk_error'])): ?>
        <div class="alert error">Invio massivo fallito.</div>
    <?php elseif (isset($_GET['ei_cancel']) && $_GET['ei_cancel'] === 'ok'): ?>
        <div class="alert success">E-fattura annullata<?php echo !empty($_GET['num']) ? ' (' . htmlspecialchars((string)$_GET['num']) . ')' : ''; ?>. Ora puoi correggere i dati e reinviare.</div>
    <?php elseif (isset($_GET['ei_cancel']) && $_GET['ei_cancel'] === 'error'): ?>
        <div class="alert error">Annullamento e-fattura non riuscito<?php echo !empty($_GET['ei_cancel_msg']) ? ': ' . htmlspecialchars((string)$_GET['ei_cancel_msg']) : ''; ?>.</div>
    <?php elseif (isset($_GET['ei']) && $_GET['ei'] === 'ok'): ?>
        <div class="alert success">Fattura inviata allo SDI<?php echo !empty($_GET['num']) ? ' (' . htmlspecialchars((string)$_GET['num']) . ')' : ''; ?>.</div>
    <?php elseif (isset($_GET['ei']) && $_GET['ei'] === 'error'): ?>
        <?php
        $eiRawMsg = trim((string)($_GET['ei_msg'] ?? ''));
        $eiFriendlyMsg = '';
        if ($eiRawMsg !== '') {
            if (strpos($eiRawMsg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0) {
                $numDup = trim((string)substr($eiRawMsg, strlen('FIC_DUPLICATE_NOT_RECOVERABLE:')));
                $eiFriendlyMsg = 'Duplicato FIC non recuperabile automaticamente'
                    . ($numDup !== '' ? ' (numero ' . $numDup . ')' : '')
                    . '. Ho marcato la fattura come duplicate_unresolved.';
            } else {
                $eiFriendlyMsg = $eiRawMsg;
            }
        }
        ?>
        <div class="alert error">
            Invio SDI non riuscito.
            <?php if ($eiFriendlyMsg !== ''): ?>
                <br><span style="font-size:0.95em;"><?php echo htmlspecialchars($eiFriendlyMsg); ?></span>
            <?php endif; ?>
        </div>
    <?php elseif (isset($_GET['fic']) && $_GET['fic'] === 'error'): ?>
        <div class="alert error">Sincronizzazione con Fatture in Cloud non riuscita.</div>
    <?php elseif (isset($_GET['fic']) && $_GET['fic'] === 'duplicate'): ?>
        <div class="alert error">Documento duplicato su Fatture in Cloud: numero <?php echo htmlspecialchars($_GET['num'] ?? 'N/A'); ?>.</div>
    <?php elseif (isset($_GET['fic']) && $_GET['fic'] === 'ok'): ?>
        <?php if (isset($_GET['mail']) && $_GET['mail'] === 'ok'): ?>
            <div class="alert success">Fattura sincronizzata su FIC e inviata via email.</div>
        <?php elseif (isset($_GET['mail']) && $_GET['mail'] === 'error'): ?>
            <div class="alert error">Fattura sincronizzata su FIC, ma email non inviata.</div>
        <?php elseif (isset($_GET['mail']) && $_GET['mail'] === 'missing_config'): ?>
            <div class="alert error">Fattura sincronizzata su FIC, ma configurazione email mancante.</div>
        <?php else: ?>
            <div class="alert success">Fattura sincronizzata su Fatture in Cloud.</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php /* Gli alert SDI dettagliati sono gestiti in top bar: in Fatture evitiamo di duplicare pannelli lunghi sopra filtri e tabella. */ ?>
    <?php require __DIR__ . '/partials/filtri.php'; ?>

    <?php
    $ficLimit = isset($_GET['fic_limit']) ? max(1, (int)$_GET['fic_limit']) : 20;
    $massiveSdiParams = [
        'document_type' => 'invoice',
        'batch' => 5,
        'offset' => 0,
        'autonext' => 1,
        'only_existing_fic' => 1,
        'sdi_flow' => 'ready',
    ];
    $massiveSdiUrl = 'actions/fic_send_einvoice_bulk.php?' . http_build_query($massiveSdiParams);
    $deliveryReportParams = [];
    if ($q !== '') {
        $deliveryReportParams['q'] = $q;
    }
    if ($autoSendFilter !== '') {
        $deliveryReportParams['auto_send'] = $autoSendFilter;
    }
    if ($sdiFlow !== '') {
        $deliveryReportParams['sdi_flow'] = $sdiFlow;
    }
    $deliveryReportUrl = 'actions/fic_export_delivery_report.php';
    if (!empty($deliveryReportParams)) {
        $deliveryReportUrl .= '?' . http_build_query($deliveryReportParams);
    }
    ?>

    <div class="card fatture-operations-card">
        <div class="fatture-operations-header">
            <div>
                <h3>Azioni massive fatture</h3>
                <p class="muted">Ordine operativo: FIC senza SDI, invio SDI, esclusioni, riparazione, eliminazione.</p>
            </div>
        </div>

        <div class="fatture-operations-grid">
            <section class="fatture-action-card">
                <span class="fatture-action-step">1</span>
                <h4>Invia senza SDI</h4>
                <p class="muted">Crea o riallinea le fatture su Fatture in Cloud senza invio elettronico.</p>
                <button class="btn" id="fic-sync-all-batch" type="button">Invia a FIC</button>
                <div class="fatture-progress" id="fic-sync-progress" aria-live="polite">
                    <div class="fatture-progress-bar">
                        <div class="fatture-progress-fill" id="fic-sync-progress-fill"></div>
                    </div>
                    <div class="fatture-progress-meta">
                        <span id="fic-sync-progress-label">In attesa.</span>
                        <span id="fic-sync-progress-counts">Create 0 - Collegate 0 - Errori 0</span>
                    </div>
                    <div class="fatture-progress-note" id="fic-sync-progress-note"></div>
                </div>
            </section>

            <section class="fatture-action-card">
                <span class="fatture-action-step">2</span>
                <h4>Invia fatture elettroniche</h4>
                <p class="muted">Invia allo SDI le fatture gia caricate su Fatture in Cloud.</p>
                <a class="btn success" href="<?php echo htmlspecialchars($massiveSdiUrl); ?>" onclick="return confirm('Inviare tutte le fatture pronte allo SDI?');">Invia SDI</a>
            </section>

            <form class="fatture-action-card fatture-action-card--wide" method="get" action="actions/fic_send_einvoice_bulk.php" onsubmit="return confirm('Inviare allo SDI escludendo i clienti indicati?');">
                <span class="fatture-action-step">3</span>
                <h4>Invia SDI con esclusioni</h4>
                <input type="hidden" name="document_type" value="invoice">
                <input type="hidden" name="batch" value="5">
                <input type="hidden" name="offset" value="0">
                <input type="hidden" name="autonext" value="1">
                <input type="hidden" name="only_existing_fic" value="1">
                <input type="hidden" name="sdi_flow" value="ready">
                <label for="exclude_clients">Clienti da escludere</label>
                <textarea id="exclude_clients" name="exclude_clients" rows="3" placeholder="ID cliente, email, P.IVA, nome o azienda"></textarea>
                <button class="btn success" type="submit">Invia SDI escludendo</button>
            </form>

            <section class="fatture-action-card">
                <span class="fatture-action-step">4</span>
                <h4>Verifica e ripara senza SDI</h4>
                <p class="muted">Controlla le fatture non inviate allo SDI e ricrea i collegamenti FIC mancanti.</p>
                <button class="btn secondary" id="fic-repair-no-sdi-batch" type="button">Verifica e ripara</button>
            </section>

            <section class="fatture-action-card fatture-action-card--danger">
                <span class="fatture-action-step">5</span>
                <h4>Elimina fatture su FIC</h4>
                <p class="muted">Cancella tutte le fatture presenti su Fatture in Cloud e azzera i link locali.</p>
                <form id="fic-delete-all-form" method="post" action="actions/fic_delete_all_invoices.php" onsubmit="startDeleteAllFicInvoices(this); return false;">
                    <input type="hidden" name="confirm_text" value="">
                    <button class="btn danger" type="submit">Elimina su FIC</button>
                </form>
            </section>
        </div>

        <details class="fatture-maintenance">
            <summary>Manutenzione e report</summary>
            <div class="fatture-maintenance-actions">
                <a class="btn secondary btn-sm" href="<?php echo htmlspecialchars($deliveryReportUrl); ?>">Report consegna</a>
                <a class="btn secondary btn-sm" href="actions/fic_restore.php">Ripristino collegamenti FIC</a>
                <a class="btn secondary btn-sm" href="actions/fic_bulk_push.php">Strumenti avanzati FIC</a>
                <button class="btn secondary btn-sm" id="rinominaNumeriFattureBtn" title="Rinomina i numeri fattura non ancora sincronizzate su FIC (formato STR-ANNO-ID) per evitare duplicati">&#9998; Rinomina numeri fatture</button>
                <button class="btn secondary btn-sm" id="rigeneraDuplicatiBtn" style="border-color:#f59e0b;color:#92400e;" title="Rigenera le fatture bloccate per duplicato FIC, incluse quelle finite in sync_error ma con errore duplicate: assegna un nuovo numero e riprova l'invio SDI">&#128260; Rigenera duplicati FIC</button>
                <button class="btn secondary btn-sm" id="rebuildCompletoBtn" style="border-color:#dc2626;color:#991b1b;" title="Rebuild completo: elimina i documenti FIC collegati, resetta i link locali e ricrea tutto">&#9888; Rebuild completo sicuro</button>
            </div>
            <div id="rinominaNumeriResult" style="display:none;margin-top:10px;"></div>
            <div id="rigeneraDuplicatiResult" style="display:none;margin-top:10px;"></div>
            <div id="rebuildCompletoResult" style="display:none;margin-top:10px;"></div>
        </details>
    </div>

    <div class="card fatture-create-card fatture-create-compact">
        <div class="fatture-create-header">
            <div>
                <h3>Nuova fattura</h3>
                <p class="muted">Salvataggio con sincronizzazione automatica su Fatture in Cloud.</p>
            </div>
            <button class="btn" type="button" data-modal-open="modal-create-fattura">Crea fattura</button>
        </div>
    </div>

    <?php require __DIR__ . '/partials/tabella.php'; ?>

    <?php require __DIR__ . '/partials/paginazione.php'; ?>

    <div class="card">
        <details>
            <summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px;list-style:none;">
                <h3 style="margin:0;">Ultime attivit&agrave;</h3>
                <span class="muted" style="font-size:0.9rem;">
                    <?php echo number_format($totalLogs, 0, ',', '.'); ?> attivit&agrave;
                </span>
            </summary>
            <div style="margin-top:12px;">
                <?php if ($totalLogs > 0): ?>
                    <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <div class="muted"><strong><?php echo number_format($totalLogs, 0, ',', '.'); ?></strong> attivit&agrave; totali &bull; Pagina <?php echo $logsPage; ?> di <?php echo $totalLogsPages; ?></div>
                        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <?php foreach ($_GET as $k => $v): ?>
                                <?php if (!in_array($k, ['logs_page', 'logs_per_page'], true)): ?>
                                    <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <label class="muted" style="font-size:0.9rem;">Righe per pagina:</label>
                            <select name="logs_per_page" onchange="this.form.submit()" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;">
                                <?php foreach ($logsPerPageOptions as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $logsPerPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!$recentLogs): ?>
                    <p class="muted">Nessuna attivit&agrave; registrata.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Quando</th>
                                <th>Azione</th>
                                <th>Esito</th>
                                <th>Messaggio</th>
                                <th>Utente</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$log['creato_il']); ?></td>
                                <td><?php echo htmlspecialchars((string)$log['azione']); ?></td>
                                <td><span class="badge <?php echo (($log['esito'] ?? '') === 'ok') ? 'ok' : ((($log['esito'] ?? '') === 'error') ? 'ko' : 'bozza'); ?>"><?php echo htmlspecialchars((string)$log['esito']); ?></span></td>
                                <td>
                                    <?php if (!empty($log['fattura_id'])): ?>
                                        <a href="dettaglio_fattura.php?id=<?php echo (int)$log['fattura_id']; ?>">#<?php echo (int)$log['fattura_id']; ?></a> -
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars((string)$log['messaggio']); ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($log['utente_nome'] ?: $log['utente_email'] ?: '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($totalLogsPages > 1): ?>
                        <div style="margin-top:12px;display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;">
                            <?php if ($logsPage > 1): ?>
                                <a class="btn secondary" style="padding:6px 10px;font-size:0.9rem;" href="index.php?<?php echo http_build_query(array_merge($_GET, ['logs_page' => 1])); ?>">&laquo;</a>
                                <a class="btn secondary" style="padding:6px 10px;font-size:0.9rem;" href="index.php?<?php echo http_build_query(array_merge($_GET, ['logs_page' => $logsPage - 1])); ?>">&lsaquo;</a>
                            <?php endif; ?>
                            <span class="muted" style="font-size:0.9rem;"> <?php echo $logsPage; ?> / <?php echo $totalLogsPages; ?> </span>
                            <?php if ($logsPage < $totalLogsPages): ?>
                                <a class="btn secondary" style="padding:6px 10px;font-size:0.9rem;" href="index.php?<?php echo http_build_query(array_merge($_GET, ['logs_page' => $logsPage + 1])); ?>">&rsaquo;</a>
                                <a class="btn secondary" style="padding:6px 10px;font-size:0.9rem;" href="index.php?<?php echo http_build_query(array_merge($_GET, ['logs_page' => $totalLogsPages])); ?>">&raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
    </div>

    <div class="modal" id="modal-create-fattura" aria-hidden="true" style="display:none;">
        <div class="modal-card fattura-modal-card">
            <div class="modal-header">
                <h3 style="margin:0;">Crea fattura</h3>
                <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
            </div>
            <form id="fattura-form" method="post" action="salva_fattura.php">
                <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($docType); ?>">
                <input type="hidden" name="sync_fic" value="1">
                <div class="fattura-modal-section">
                    <h4 style="margin:0 0 8px 0;">Dati cliente</h4>
                    <div class="grid fattura-modal-grid">
                        <div>
                            <label>Nome cliente</label>
                            <input type="text" name="cliente_nome" required>
                        </div>
                        <div>
                            <label>Email cliente</label>
                            <input type="email" name="cliente_email" placeholder="cliente@example.com">
                        </div>
                        <div>
                            <label>Ragione sociale</label>
                            <input type="text" name="cliente_azienda">
                        </div>
                        <div>
                            <label>Telefono</label>
                            <input type="text" name="cliente_telefono">
                        </div>
                        <div>
                            <label>Indirizzo</label>
                            <input type="text" name="cliente_indirizzo">
                        </div>
                        <div>
                            <label>P.IVA</label>
                            <input type="text" name="cliente_piva">
                        </div>
                        <div>
                            <label>Codice SDI</label>
                            <input type="text" name="cliente_codice_sdi" maxlength="20">
                        </div>
                        <div>
                            <label>PEC</label>
                            <input type="email" name="cliente_pec">
                        </div>
                    </div>
                </div>

                <div class="fattura-modal-section">
                    <h4 style="margin:0 0 8px 0;">Dati fattura</h4>
                    <div class="grid fattura-modal-grid">
                        <div>
                            <label>Numero</label>
                            <input type="text" name="numero" required>
                        </div>
                        <div>
                            <label>Data emissione</label>
                            <input type="date" name="data_emissione" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div>
                            <label>Data scadenza</label>
                            <input type="date" name="data_scadenza" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        <div>
                            <label>Stato</label>
                            <select name="stato">
                                <option value="bozza">Bozza</option>
                                <option value="emessa" selected>Emessa</option>
                                <option value="pagata">Pagata</option>
                                <option value="scaduta">Scaduta</option>
                            </select>
                        </div>
                        <div>
                            <label>Totale</label>
                            <input type="number" step="0.01" name="totale" value="0.00" required>
                        </div>
                        <div style="grid-column:1/-1;">
                            <label>Note</label>
                            <textarea name="note" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="fattura-modal-actions">
                    <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                    <button class="btn" type="submit">Salva e sincronizza</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="js/fatture.js"></script>
<script>
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function buildSyncErrorMessage(prefix, status, payload) {
    if (status === 403) {
        return prefix + ': sessione non autorizzata (HTTP 403). Ricarica la pagina e rifai login.';
    }
    if (status === 503) {
        return prefix + ': server temporaneamente occupato (HTTP 503).';
    }
    if (payload && payload.error) {
        return prefix + ': ' + payload.error;
    }
    return prefix + ': HTTP ' + status;
}

function startDeleteAllFicInvoices(form) {
    const warning = 'ATTENZIONE: verranno eliminate TUTTE le fatture presenti su Fatture in Cloud per questa azienda.\nOperazione irreversibile.';
    const answer = window.prompt(warning + '\n\nPer confermare, scrivi esattamente:\nELIMINA TUTTE LE FATTURE FIC');
    if (!answer) return;
    if (answer.trim() !== 'ELIMINA TUTTE LE FATTURE FIC') {
        alert('Conferma non valida. Operazione annullata.');
        return;
    }

    const btn = form.querySelector('button[type="submit"]');
    const input = form.querySelector('input[name="confirm_text"]');
    if (!btn || !input) {
        alert('Impossibile avviare la cancellazione: form non valido.');
        return;
    }

    input.value = answer.trim();
    const originalText = btn.textContent;
    btn.disabled = true;

    let deletedTot = 0;
    let missingTot = 0;
    let failedTot = 0;
    let localClearedTot = 0;
    let loops = 0;
    const maxLoops = 250;
    const maxRetries = 3;
    const chunkSize = 20;
    const collectedErrors = [];

    const run = async () => {
        try {
            while (true) {
                loops++;
                if (loops > maxLoops) {
                    throw new Error('Interruzione di sicurezza: troppi blocchi consecutivi.');
                }

                btn.textContent = `Eliminazione FIC... blocco ${loops}`;
                let data = null;
                let lastErr = null;

                for (let attempt = 1; attempt <= maxRetries; attempt++) {
                    try {
                        const fd = new FormData();
                        fd.append('confirm_text', input.value);
                        fd.append('ajax', '1');
                        fd.append('chunk', String(chunkSize));

                        const res = await fetch('actions/fic_delete_all_invoices.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' },
                            body: fd,
                        });

                        const raw = await res.text();
                        let payload = null;
                        try {
                            payload = JSON.parse(raw);
                        } catch (e) {
                            payload = null;
                        }

                        if (!res.ok || !payload || payload.ok !== true) {
                            const message = (payload && payload.error) ? payload.error : (raw || ('HTTP ' + res.status));
                            throw new Error(message);
                        }

                        data = payload;
                        break;
                    } catch (err) {
                        lastErr = err;
                        if (attempt < maxRetries) {
                            await sleep(800 * attempt);
                        }
                    }
                }

                if (!data) {
                    throw lastErr || new Error('Risposta non valida dal server.');
                }

                deletedTot += parseInt(data.deleted || 0, 10);
                missingTot += parseInt(data.missing || 0, 10);
                failedTot += parseInt(data.failed || 0, 10);
                localClearedTot += parseInt((data.local_cleared || 0), 10) + parseInt((data.local_cleared_final || 0), 10);

                if (Array.isArray(data.errors)) {
                    data.errors.forEach((msg) => {
                        if (collectedErrors.length < 8) {
                            collectedErrors.push(String(msg));
                        }
                    });
                }

                if (data.done || parseInt(data.processed || 0, 10) === 0) {
                    break;
                }
            }

            const params = new URLSearchParams();
            params.set('fic_delete_all_done', '1');
            params.set('fic_delete_all_deleted', String(deletedTot));
            params.set('fic_delete_all_missing', String(missingTot));
            params.set('fic_delete_all_failed', String(failedTot));
            params.set('fic_delete_all_local_cleared', String(localClearedTot));
            if (collectedErrors.length) {
                params.set('fic_delete_all_warnings', collectedErrors.join(' | '));
            }
            window.location.href = 'index.php?' + params.toString();
        } catch (err) {
            alert('Errore durante la cancellazione massiva su FIC: ' + (err && err.message ? err.message : err));
            window.location.reload();
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    };

    run();
}

document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('fic-sync-all-batch');
    if (!btn) return;

    const progressBox = document.getElementById('fic-sync-progress');
    const progressFill = document.getElementById('fic-sync-progress-fill');
    const progressLabel = document.getElementById('fic-sync-progress-label');
    const progressCounts = document.getElementById('fic-sync-progress-counts');
    const progressNote = document.getElementById('fic-sync-progress-note');

    function updateFicSyncProgress(options) {
        if (!progressBox || !progressFill || !progressLabel || !progressCounts || !progressNote) {
            return;
        }

        const state = options.state || '';
        const percent = Math.max(0, Math.min(100, parseInt(options.percent || 0, 10)));
        progressBox.classList.add('is-active');
        progressBox.classList.toggle('is-indeterminate', !!options.indeterminate);
        progressBox.classList.toggle('is-ok', state === 'ok');
        progressBox.classList.toggle('is-error', state === 'error');
        progressFill.style.width = percent + '%';
        progressLabel.textContent = options.label || 'Elaborazione in corso...';
        progressCounts.textContent = `Create ${options.created || 0} - Collegate ${options.linked || 0} - Errori ${options.failed || 0}`;
        progressNote.textContent = options.note || '';
    }

    function isFicUsageLimitMessage(message) {
        const text = String(message || '').toLowerCase();
        return text.includes('usage_limit_reached')
            || text.includes('api usage limit')
            || text.includes('limite api fic')
            || text.includes('you have reached your api usage limit');
    }

    btn.addEventListener('click', async function() {
        const confirmed = window.confirm('Sincronizzare ORA tutte le fatture CRM su Fatture in Cloud (senza invio SDI)?');
        if (!confirmed) return;

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Invio a FIC in corso...';

        const limit = 1;
        let offset = 0;
        let ok = 0;
        let failed = 0;
        let linked = 0;
        let blocks = 0;
        let candidateTotal = 0;
        let lastSample = '';
        let stoppedByUsageLimit = false;
        const maxRetries = 4;

        updateFicSyncProgress({
            percent: 0,
            indeterminate: true,
            label: 'Preparazione invio a FIC...',
            created: ok,
            linked,
            failed,
            note: 'La pagina deve restare aperta fino al completamento.'
        });

        try {
            while (true) {
                const blockNo = Math.floor(offset / limit) + 1;
                btn.textContent = `Invio blocco ${blockNo}...`;
                updateFicSyncProgress({
                    percent: candidateTotal > 0 ? Math.round((offset / candidateTotal) * 100) : 0,
                    indeterminate: candidateTotal <= 0,
                    label: candidateTotal > 0 ? `Elaborate ${offset} di ${candidateTotal} fatture` : `Blocco ${blockNo} in corso...`,
                    created: ok,
                    linked,
                    failed,
                    note: lastSample ? `Ultimo errore: ${lastSample}` : 'Connessione a Fatture in Cloud in corso...'
                });

                let data = null;
                let lastError = null;
                for (let attempt = 1; attempt <= maxRetries; attempt++) {
                    try {
                        const res = await fetch(`actions/fic_sync_all.php?force=1&limit=${limit}&offset=${offset}&ajax=1`, {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        let payload = null;
                        try {
                            payload = await res.json();
                        } catch (parseErr) {
                            payload = null;
                        }
                        if (!res.ok || !payload || payload.ok !== true) {
                            throw new Error(buildSyncErrorMessage('Errore durante invio a FIC', res.status, payload));
                        }
                        data = payload;
                        lastError = null;
                        break;
                    } catch (err) {
                        lastError = err;
                        if (attempt < maxRetries) {
                            updateFicSyncProgress({
                                percent: candidateTotal > 0 ? Math.round((offset / candidateTotal) * 100) : 0,
                                indeterminate: candidateTotal <= 0,
                                label: `Riprovo il blocco ${blockNo} (${attempt + 1}/${maxRetries})...`,
                                created: ok,
                                linked,
                                failed,
                                note: err && err.message ? err.message : String(err)
                            });
                            await sleep(900 * attempt);
                        }
                    }
                }
                if (!data) {
                    throw lastError || new Error('Errore durante invio a FIC: risposta non valida dal server.');
                }

                const blockTotal = parseInt(data.total || '0', 10);
                ok += parseInt(data.created || '0', 10);
                failed += parseInt(data.failed || '0', 10);
                linked += parseInt(data.linked || '0', 10);
                candidateTotal = Math.max(candidateTotal, parseInt(data.candidate_total || '0', 10));
                lastSample = String(data.error_sample || '').trim();
                stoppedByUsageLimit = isFicUsageLimitMessage(lastSample);
                const attempted = offset + blockTotal;

                if (blockTotal <= 0 || stoppedByUsageLimit) {
                    break;
                }

                offset += blockTotal;
                blocks++;
                const processed = candidateTotal > 0 ? Math.min(offset, candidateTotal) : offset;
                updateFicSyncProgress({
                    percent: candidateTotal > 0 ? Math.round((processed / candidateTotal) * 100) : 0,
                    indeterminate: candidateTotal <= 0,
                    label: candidateTotal > 0 ? `Elaborate ${processed} di ${candidateTotal} fatture` : `Elaborati ${processed} blocchi`,
                    created: ok,
                    linked,
                    failed,
                    note: lastSample ? `Ultimo errore: ${lastSample}` : 'Invio a FIC in corso...'
                });

                if (blocks > 1000) {
                    throw new Error('Interruzione di sicurezza: troppi blocchi consecutivi.');
                }
            }

            const processedTotal = candidateTotal > 0 ? Math.min(offset + (failed > 0 || ok > 0 || linked > 0 ? 1 : 0), candidateTotal) : offset;
            const completedLabel = candidateTotal > 0
                ? (stoppedByUsageLimit
                    ? `Interrotto a ${processedTotal} di ${candidateTotal} fatture`
                    : `Completate ${processedTotal} di ${candidateTotal} fatture`)
                : 'Invio a FIC completato';
            updateFicSyncProgress({
                percent: stoppedByUsageLimit && candidateTotal > 0 ? Math.round((processedTotal / candidateTotal) * 100) : 100,
                indeterminate: false,
                state: (failed > 0 || stoppedByUsageLimit) ? 'error' : 'ok',
                label: completedLabel,
                created: ok,
                linked,
                failed,
                note: stoppedByUsageLimit
                    ? 'Limite API Fatture in Cloud raggiunto: attendi il reset del limite e poi riprendi l invio.'
                    : (failed > 0
                    ? (lastSample ? `Completato con errori. Ultimo errore: ${lastSample}` : 'Completato con errori. Controlla le ultime attivita per il dettaglio.')
                    : 'Completato correttamente. Aggiorno i conteggi tra pochi secondi.')
            });
            btn.disabled = false;
            btn.textContent = (failed > 0 || stoppedByUsageLimit) ? 'Riprova invio a FIC' : originalText;
            if (failed <= 0 && !stoppedByUsageLimit) {
                window.setTimeout(function() {
                    window.location.reload();
                }, 2500);
            }
        } catch (e) {
            updateFicSyncProgress({
                percent: candidateTotal > 0 ? Math.round((offset / candidateTotal) * 100) : 0,
                indeterminate: false,
                state: 'error',
                label: 'Invio a FIC interrotto',
                created: ok,
                linked,
                failed,
                note: (e && e.message) ? e.message : ('Errore di rete o server durante invio a FIC: ' + e)
            });
            btn.disabled = false;
            btn.textContent = originalText;
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('fic-repair-no-sdi-batch');
    if (!btn) return;

    btn.addEventListener('click', async function() {
        const confirmed = window.confirm('Verificare e riparare le fatture non inviate allo SDI? Verranno riallineati i collegamenti FIC mancanti o non validi.');
        if (!confirmed) return;

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Verifica in corso...';

        const limit = Math.max(1, <?php echo (int)$ficLimit; ?>);
        let offset = 0;
        let ok = 0;
        let failed = 0;
        let linked = 0;
        let blocks = 0;
        const maxRetries = 4;

        try {
            while (true) {
                btn.textContent = `Ripara blocco ${Math.floor(offset / limit) + 1}...`;
                let data = null;
                let lastError = null;
                for (let attempt = 1; attempt <= maxRetries; attempt++) {
                    try {
                        const res = await fetch(`actions/fic_sync_all.php?force=3&limit=${limit}&offset=${offset}&ajax=1`, {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        let payload = null;
                        try {
                            payload = await res.json();
                        } catch (parseErr) {
                            payload = null;
                        }
                        if (!res.ok || !payload || payload.ok !== true) {
                            throw new Error(buildSyncErrorMessage('Errore durante Verifica e ripara senza SDI', res.status, payload));
                        }
                        data = payload;
                        lastError = null;
                        break;
                    } catch (err) {
                        lastError = err;
                        if (attempt < maxRetries) {
                            await sleep(900 * attempt);
                        }
                    }
                }
                if (!data) {
                    throw lastError || new Error('Errore durante Verifica e ripara senza SDI: risposta non valida dal server.');
                }

                const blockTotal = parseInt(data.total || '0', 10);
                ok += parseInt(data.created || '0', 10);
                failed += parseInt(data.failed || '0', 10);
                linked += parseInt(data.linked || '0', 10);

                if (blockTotal <= 0) {
                    break;
                }

                offset += blockTotal;
                blocks++;
                if (blocks > 500) {
                    throw new Error('Interruzione di sicurezza: troppi blocchi consecutivi.');
                }
            }

            alert(`Verifica e ripara completata. Create: ${ok}, Collegate: ${linked}, Errori: ${failed}`);
            window.location.reload();
        } catch (e) {
            alert('Errore durante Verifica e ripara senza SDI: ' + (e && e.message ? e.message : e));
            btn.disabled = false;
            btn.textContent = originalText;
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('rinominaNumeriFattureBtn');
    var resultBox = document.getElementById('rinominaNumeriResult');
    if (!btn || !resultBox) return;

    var btnRebuild = document.getElementById('rebuildCompletoBtn');
    var resultRebuild = document.getElementById('rebuildCompletoResult');
    if (btnRebuild && resultRebuild) {
        function parseRebuildJsonResponse(res) {
            if (!res) {
                throw new Error('Nessuna risposta dal server');
            }
            var finalUrl = (res.url || '').toLowerCase();
            if (res.redirected || finalUrl.indexOf('/login.php') !== -1) {
                throw new Error('Sessione scaduta: effettua di nuovo il login e riprova.');
            }
            return res.text().then(function(txt) {
                var contentType = (res.headers.get('content-type') || '').toLowerCase();
                if (contentType.indexOf('application/json') === -1) {
                    throw new Error('Risposta non JSON (HTTP ' + res.status + '): ' + txt.slice(0, 120));
                }
                try {
                    return JSON.parse(txt);
                } catch (e) {
                    throw new Error('JSON non valido: ' + txt.slice(0, 120));
                }
            });
        }

        btnRebuild.addEventListener('click', function() {
            var originalLabel = btnRebuild.textContent;
            btnRebuild.disabled = true;
            btnRebuild.textContent = 'Anteprima in corso...';
            resultRebuild.style.display = 'none';
            resultRebuild.innerHTML = '';

            fetch('actions/fic_rebuild_full.php?mode=preview', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(parseRebuildJsonResponse)
            .then(function(data) {
                if (!data.ok || !data.summary) {
                    throw new Error(data.error || 'Anteprima non disponibile');
                }

                var s = data.summary;
                var previewMsg =
                    'ANTEPRIMA REBUILD COMPLETO\\n\\n'
                    + 'Fatture locali invoice: ' + (s.total_invoices || 0) + '\\n'
                    + 'Documenti FIC collegati da eliminare: ' + (s.linked_docs || 0) + '\\n'
                    + 'Duplicate unresolved: ' + (s.duplicate_unresolved || 0) + '\\n'
                    + 'Fatture pagate: ' + (s.paid_invoices || 0) + '\\n'
                    + 'Pagate con SDI ancora da inviare: ' + (s.pending_sdi_paid || 0) + '\\n\\n'
                    + 'Verranno cancellati i documenti FIC collegati localmente, poi ricreati da zero.\\n'
                    + 'Digitare la conferma nel prossimo passaggio.';

                alert(previewMsg);
                var confirmText = prompt('Conferma operazione. Scrivi esattamente: RIGENERA COMPLETO', '');
                if (confirmText !== 'RIGENERA COMPLETO') {
                    throw new Error('Conferma annullata o non valida');
                }

                btnRebuild.textContent = 'Rebuild in corso...';
                var totals = {
                    processed: 0,
                    deleted_fic: 0,
                    missing_fic: 0,
                    delete_failed: 0,
                    recreated_fic: 0,
                    recreate_failed: 0,
                    sdi_sent: 0,
                    sdi_failed: 0,
                    errors: []
                };

                function addChunkStats(st) {
                    totals.processed += (st.processed || 0);
                    totals.deleted_fic += (st.deleted_fic || 0);
                    totals.missing_fic += (st.missing_fic || 0);
                    totals.delete_failed += (st.delete_failed || 0);
                    totals.recreated_fic += (st.recreated_fic || 0);
                    totals.recreate_failed += (st.recreate_failed || 0);
                    totals.sdi_sent += (st.sdi_sent || 0);
                    totals.sdi_failed += (st.sdi_failed || 0);
                }

                function runChunk(afterId) {
                    var body = new URLSearchParams();
                    body.append('mode', 'execute_chunk');
                    body.append('confirm_text', confirmText);
                    body.append('after_id', String(afterId || 0));
                    body.append('chunk', '4');

                    function fetchChunkWithRetry(attempt) {
                        return fetch('actions/fic_rebuild_full.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                            body: body.toString()
                        })
                        .then(parseRebuildJsonResponse)
                        .catch(function(err) {
                            var msg = (err && err.message ? err.message : String(err));
                            if (attempt < 2 && msg.indexOf('HTTP 503') !== -1) {
                                return new Promise(function(resolve) {
                                    setTimeout(function() {
                                        resolve(fetchChunkWithRetry(attempt + 1));
                                    }, 1200 * (attempt + 1));
                                });
                            }
                            throw err;
                        });
                    }

                    return fetchChunkWithRetry(0)
                    .then(function(data) {
                        if (!data.ok) {
                            throw new Error(data.error || 'Errore chunk rebuild');
                        }
                        addChunkStats(data.stats || {});
                        if (Array.isArray(data.errors) && data.errors.length > 0) {
                            totals.errors = totals.errors.concat(data.errors).slice(0, 25);
                        }

                        resultRebuild.className = 'alert warning';
                        resultRebuild.innerHTML =
                            '&#9203; Rebuild in corso... '
                            + 'Processate: ' + totals.processed
                            + ' | Ricreate: ' + totals.recreated_fic
                            + ' | SDI inviate: ' + totals.sdi_sent
                            + ' | Rimanenti: ' + (data.remaining || 0);
                        resultRebuild.style.display = 'block';

                        if (data.done) {
                            return totals;
                        }
                        return runChunk(data.next_after_id || afterId || 0);
                    });
                }

                return runChunk(0);
            })
            .then(function(totals) {

                btnRebuild.disabled = false;
                btnRebuild.textContent = originalLabel;

                resultRebuild.className = totals.recreate_failed > 0 || totals.sdi_failed > 0 || totals.delete_failed > 0 ? 'alert warning' : 'alert success';
                resultRebuild.innerHTML =
                    '&#9888; Rebuild completato. '
                    + 'Processate: ' + totals.processed
                    + ' | FIC eliminate: ' + totals.deleted_fic
                    + ' | FIC mancanti: ' + totals.missing_fic
                    + ' | Errori delete: ' + totals.delete_failed
                    + ' | Ricreate: ' + totals.recreated_fic
                    + ' | Errori ricreazione: ' + totals.recreate_failed
                    + ' | SDI inviate: ' + totals.sdi_sent
                    + ' | SDI errori: ' + totals.sdi_failed;

                if (totals.errors && totals.errors.length > 0) {
                    resultRebuild.innerHTML += '<br><small style="color:#7f1d1d">' + totals.errors.map(function(e){ return '&#9888; ' + e; }).join('<br>') + '</small>';
                }
                resultRebuild.style.display = 'block';
                setTimeout(function() { window.location.reload(); }, 4500);
            })
            .catch(function(err) {
                btnRebuild.disabled = false;
                btnRebuild.textContent = originalLabel;
                resultRebuild.className = 'alert error';
                resultRebuild.innerHTML = '&#9888;&#65039; Errore rebuild: ' + (err && err.message ? err.message : err);
                resultRebuild.style.display = 'block';
            });
        });
    }

    // Rigenera duplicati FIC
    var btnDup = document.getElementById('rigeneraDuplicatiBtn');
    var resultDup = document.getElementById('rigeneraDuplicatiResult');
    if (btnDup && resultDup) {
        btnDup.addEventListener('click', function() {
            if (!confirm('Rigenera tutte le fatture bloccate per duplicato FIC?\n\nSaranno incluse anche quelle finite in sync_error ma con errore duplicate.\nVerrà assegnato un nuovo numero univoco e si riproverà la creazione su FIC e l\'invio SDI.\n\nLe fatture già su FIC NON vengono modificate.')) return;
            var orig = btnDup.textContent;
            btnDup.disabled = true;
            btnDup.textContent = 'Rigenerazione in corso...';
            resultDup.style.display = 'none';
            resultDup.innerHTML = '';
            fetch('actions/fic_rigenera_duplicati.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btnDup.disabled = false;
                btnDup.textContent = orig;
                if (data.ok) {
                    resultDup.className = data.failed > 0 ? 'alert warning' : 'alert success';
                    resultDup.innerHTML = '&#128260; ' + (data.message || 'Completato');
                    if (data.errors && data.errors.length > 0) {
                        resultDup.innerHTML += '<br><small style="color:#7f1d1d">' + data.errors.map(function(e){ return '&#9888; ' + e; }).join('<br>') + '</small>';
                    }
                } else {
                    resultDup.className = 'alert error';
                    resultDup.innerHTML = '&#9888;&#65039; Errore: ' + (data.error || 'risposta non valida');
                }
                resultDup.style.display = 'block';
                if (data.ok && data.fixed > 0) {
                    setTimeout(function() { window.location.reload(); }, 4000);
                }
            })
            .catch(function(err) {
                btnDup.disabled = false;
                btnDup.textContent = orig;
                resultDup.className = 'alert error';
                resultDup.innerHTML = '&#9888;&#65039; Errore di rete: ' + err.message;
                resultDup.style.display = 'block';
            });
        });
    }

    btn.addEventListener('click', function() {
        if (!confirm('Rinominare i numeri di tutte le fatture non ancora sincronizzate su FIC?\n\nI numeri verranno impostati nel formato STR-ANNO-ID (es. STR-2026-42) per garantire unicità ed evitare duplicati.\n\nQuesta operazione NON modifica le fatture già su FIC.')) return;

        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Rinomina in corso...';
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';

        fetch('actions/rinomina_numeri_fatture.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = orig;
            if (data.ok) {
                var msg = data.message || ('Rinominate: ' + (data.renamed || 0));
                resultBox.className = 'alert success';
                resultBox.innerHTML = '&#9989; ' + msg + (data.renamed > 0 ? '. Ora puoi sincronizzare su FIC senza errori di duplicato.' : '');
                if (data.errors && data.errors.length > 0) {
                    resultBox.innerHTML += '<br><small>' + data.errors.join(', ') + '</small>';
                }
            } else {
                resultBox.className = 'alert error';
                resultBox.innerHTML = '&#9888;&#65039; Errore: ' + (data.error || 'risposta non valida');
            }
            resultBox.style.display = 'block';
            if (data.ok && (data.renamed || 0) > 0) {
                setTimeout(function() { window.location.reload(); }, 3000);
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = orig;
            resultBox.className = 'alert error';
            resultBox.innerHTML = '&#9888;&#65039; Errore di rete: ' + err.message;
            resultBox.style.display = 'block';
        });
    });
});
</script>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
