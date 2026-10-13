<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');

function fic_send_einvoice_bulk_is_usage_limit_error(string $msg): bool
{
    return fic_is_usage_limit_error_message($msg);
}

function fic_send_einvoice_bulk_is_duplicate_not_recoverable(string $msg): bool
{
    return stripos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') !== false;
}

function fic_send_einvoice_bulk_is_db_gone(string $msg): bool
{
    $m = strtolower($msg);
    return strpos($m, 'server has gone away') !== false
        || strpos($m, 'lost connection') !== false;
}

function fic_send_einvoice_bulk_refresh_pdo(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        unset($GLOBALS['_tdg_pdo']);
        $pdo = require __DIR__ . '/../../../config/db.php';
    }
}

function fic_send_einvoice_bulk_safe_log(PDO $pdo, ?int $fatturaId, string $azione, string $esito, string $messaggio, array $meta = []): void
{
    try {
        fic_ensure_db_connection($pdo);
        fatture_log($pdo, $fatturaId, $azione, $esito, $messaggio, $meta);
    } catch (Throwable $e) {
        // non bloccare il batch
    }
}

function fic_send_einvoice_bulk_safe_update_tracking(PDO $pdo, int $fatturaId, ?string $status, ?string $error, bool $setSentAt): void
{
    try {
        fic_ensure_db_connection($pdo);
        fic_update_einvoice_tracking($pdo, $fatturaId, $status, $error, $setSentAt);
    } catch (Throwable $e) {
        // non bloccare il batch
    }
}

function fic_send_einvoice_bulk_mark_duplicate_unresolved(PDO $pdo, int $fatturaId): void
{
    if ($fatturaId <= 0) {
        return;
    }
    try {
        fic_ensure_db_connection($pdo);
        $stmt = $pdo->prepare("UPDATE fatture SET fic_status = 'duplicate_unresolved', fic_synced_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $fatturaId]);
    } catch (Throwable $e) {
        // non bloccare il batch
    }
}

function fic_send_einvoice_bulk_collect_ids(): array
{
    $ids = [];
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = $_POST['ids'];
    } elseif (!empty($_GET['ids_csv'])) {
        $ids = explode(',', (string)$_GET['ids_csv']);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
        return $v > 0;
    })));
    return $ids;
}

function fic_send_einvoice_bulk_parse_exclusions(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $out = [];
    $seen = [];

    $addTerm = static function (string $term) use (&$out, &$seen): void {
        $term = trim($term);
        $term = preg_replace('/^mailto:/i', '', $term) ?? $term;
        $term = trim($term, " \t\n\r\0\x0B[]()<>{}\"'");
        if ($term === '') {
            return;
        }

        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $term, $m)) {
            $term = strtolower($m[1]);
        }

        $key = strtolower($term);
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $out[] = $term;
    };

    // Supporta blocchi copiati da liste/Excel/mail: [email], (mailto:email), <email>.
    if (preg_match_all('/(?:mailto:)?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $raw, $matches)) {
        foreach ($matches[1] as $email) {
            $addTerm((string)$email);
            if (count($out) >= 200) {
                return $out;
            }
        }
    }

    $parts = preg_split('/[\n,;|]+/', $raw) ?: [];
    foreach ($parts as $part) {
        $addTerm((string)$part);
        if (count($out) >= 200) {
            break;
        }
    }

    return $out;
}

function fic_send_einvoice_bulk_apply_exclusions(array $terms, array &$params, string &$where): void
{
    if (!$terms) {
        return;
    }

    $groups = [];
    foreach ($terms as $idx => $term) {
        $checks = [];

        if (ctype_digit($term)) {
            $key = ':exclude_client_id_' . $idx;
            $checks[] = 'c.id = ' . $key;
            $params[$key] = (int)$term;
        }

        if (strpos($term, '@') !== false) {
            $emailKey = ':exclude_email_' . $idx;
            $pecKey = ':exclude_pec_' . $idx;
            $checks[] = '(LOWER(COALESCE(c.email, \'\')) = ' . $emailKey . ' OR LOWER(COALESCE(c.pec, \'\')) = ' . $pecKey . ')';
            $params[$emailKey] = strtolower($term);
            $params[$pecKey] = strtolower($term);
        }

        $piva = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $term) ?: '');
        if ($piva !== '' && preg_match('/\d/', $piva)) {
            $key = ':exclude_piva_' . $idx;
            $checks[] = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.piva, ''), ' ', ''), '-', ''), '.', ''), '/', '')) = " . $key;
            $params[$key] = $piva;
        }

        if (!ctype_digit($term) && strpos($term, '@') === false) {
            $nameKey = ':exclude_nome_' . $idx;
            $companyKey = ':exclude_azienda_' . $idx;
            $like = '%' . strtolower($term) . '%';
            $checks[] = 'LOWER(COALESCE(c.nome, \'\')) LIKE ' . $nameKey;
            $checks[] = 'LOWER(COALESCE(c.azienda, \'\')) LIKE ' . $companyKey;
            $params[$nameKey] = $like;
            $params[$companyKey] = $like;
        }

        if ($checks) {
            $groups[] = '(' . implode(' OR ', $checks) . ')';
        }
    }

    if ($groups) {
        $where .= ' AND NOT (' . implode(' OR ', $groups) . ')';
    }
}

function fic_send_einvoice_bulk_render_page(
    string $title,
    string $summaryClass,
    string $summaryText,
    array $results,
    int $batchSize,
    int $candidateTotal,
    int $processedBatch,
    int $remainingAfterBatch,
    ?string $nextUrl,
    bool $autoNext = false
): void {
    $okRows = 0;
    $skipRows = 0;
    $errorRows = 0;
    foreach ($results as $r) {
        if (($r['esito'] ?? '') === 'OK') {
            $okRows++;
        } elseif (($r['esito'] ?? '') === 'SKIP') {
            $skipRows++;
        } else {
            $errorRows++;
        }
    }

    $headerPath = __DIR__ . '/../../../layouts/header.php';
    if (is_file($headerPath)) {
        require $headerPath;
    } else {
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Fatture</title></head><body>';
    }
    ?>
    <div class="wrap">
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <div class="alert <?php echo htmlspecialchars($summaryClass); ?>" style="margin-bottom:12px;">
            <strong><?php echo htmlspecialchars($summaryText); ?></strong>
            <div style="margin-top:6px;">
                Elaborati in questo batch: <?php echo (int)$processedBatch; ?> |
                OK: <?php echo (int)$okRows; ?> |
                Saltati: <?php echo (int)$skipRows; ?> |
                Errori: <?php echo (int)$errorRows; ?>
            </div>
            <div style="margin-top:4px;">
                Candidati iniziali: <?php echo (int)$candidateTotal; ?> |
                Batch size: <?php echo (int)$batchSize; ?> |
                Restanti stimati: <?php echo (int)$remainingAfterBatch; ?>
            </div>
        </div>

        <?php if (!empty($results)): ?>
            <table class="clienti-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Importo</th>
                        <th>Esito</th>
                        <th>Messaggio</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($row['cliente'] ?? '-')); ?></td>
                        <td>&euro;<?php echo number_format(((int)($row['importo'] ?? 0)) / 100, 2, ',', '.'); ?></td>
                        <td>
                            <?php
                            if (($row['esito'] ?? '') === 'OK') {
                                echo '<span style="color:green">OK</span>';
                            } elseif (($row['esito'] ?? '') === 'SKIP') {
                                echo '<span style="color:#b45309">SKIP</span>';
                            } else {
                                echo '<span style="color:red">ERRORE</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['msg'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['id'] ?? '-')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($nextUrl)): ?>
            <div style="margin-top:12px;">
                <a href="<?php echo htmlspecialchars($nextUrl); ?>" class="btn accent-green">Elabora prossimo batch (<?php echo (int)$batchSize; ?>)</a>
                <?php if ($autoNext): ?>
                    <span class="muted" style="margin-left:8px;">Modalita automatica attiva: prossimo batch in 2 secondi.</span>
                    <a href="<?php echo htmlspecialchars(preg_replace('/([&?])autonext=1(&|$)/', '$1', (string)$nextUrl) ?? (string)$nextUrl); ?>" class="btn secondary" style="margin-left:8px;">Stop auto</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($autoNext && !empty($nextUrl)): ?>
            <script>
                setTimeout(function () {
                    window.location.href = <?php echo json_encode($nextUrl); ?>;
                }, 2000);
            </script>
        <?php endif; ?>
        <a href="../index.php" class="btn secondary">&larr; Torna alle fatture</a>
    </div>
    <?php
    $footerPath = __DIR__ . '/../../../layouts/footer.php';
    if (is_file($footerPath)) {
        require $footerPath;
    } else {
        echo '</body></html>';
    }
}

$results = [];
$candidateTotal = 0;
$processedBatch = 0;
$remainingAfterBatch = 0;

$summaryClass = 'warning';
$summaryText = 'Elaborazione completata con elementi saltati.';
$title = 'Risultato invio massivo fatture elettroniche (SDI)';

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $sdiFlow = isset($_GET['sdi_flow']) ? trim((string)$_GET['sdi_flow']) : '';
    $docType = isset($_GET['document_type']) ? trim((string)$_GET['document_type']) : 'invoice';
    $excludeRaw = trim((string)($_REQUEST['exclude_clients'] ?? ''));
    $excludeTerms = fic_send_einvoice_bulk_parse_exclusions($excludeRaw);
    $autoNext = ((string)($_GET['autonext'] ?? '') === '1');
    // Default sicuro: lavora sui documenti gia presenti su FIC.
    // only_existing_fic vuoto o assente deve restare "true";
    // solo il valore esplicito "0" attiva il vecchio flusso da CRM.
    $onlyExistingRaw = trim((string)($_GET['only_existing_fic'] ?? '1'));
    $onlyExistingFic = ($onlyExistingRaw !== '0');
    if ($docType === '') {
        $docType = 'invoice';
    }
    $ids = fic_send_einvoice_bulk_collect_ids();
    $idsCsv = !empty($ids) ? implode(',', $ids) : '';

    $batchSize = (int)($_GET['batch'] ?? 5);
    if ($batchSize < 1) {
        $batchSize = 1;
    }
    if ($batchSize > 20) {
        $batchSize = 20;
    }
    $offset = (int)($_GET['offset'] ?? 0);
    if ($offset < 0) {
        $offset = 0;
    }

    $params = [':docType' => $docType];
    $where = ' WHERE f.document_type = :docType';
    if (!empty($ids)) {
        $placeholders = [];
        foreach ($ids as $idx => $id) {
            $key = ':id' . $idx;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $where .= ' AND f.id IN (' . implode(', ', $placeholders) . ')';
    } elseif ($q !== '') {
        $where .= ' AND ('
            . 'f.numero LIKE :q_num OR '
            . 'c.nome LIKE :q_name OR '
            . 'c.email LIKE :q_email OR '
            . 'c.piva LIKE :q_piva OR '
            . 'c.codice_sdi LIKE :q_sdi OR '
            . 'c.pec LIKE :q_pec'
            . ')';
        $params[':q_num'] = '%' . $q . '%';
        $params[':q_name'] = '%' . $q . '%';
        $params[':q_email'] = '%' . $q . '%';
        $params[':q_piva'] = '%' . $q . '%';
        $params[':q_sdi'] = '%' . $q . '%';
        $params[':q_pec'] = '%' . $q . '%';
    }
    fic_send_einvoice_bulk_apply_exclusions($excludeTerms, $params, $where);
    if ($onlyExistingFic) {
        $where .= " AND f.fic_document_id IS NOT NULL AND TRIM(f.fic_document_id) <> ''";
    }
    if ($sdiFlow === 'ready') {
        $where .= " AND (f.fic_status IS NULL OR f.fic_status <> 'duplicate_unresolved')";
        $where .= " AND (f.ei_status IS NULL OR TRIM(f.ei_status) = '' OR LOWER(f.ei_status) NOT IN ('sent','processing','accepted'))";
    } elseif ($sdiFlow === 'sent') {
        $where .= " AND LOWER(COALESCE(f.ei_status, '')) IN ('sent','processing','accepted')";
    } elseif ($sdiFlow === 'issues') {
        $where .= " AND (LOWER(COALESCE(f.ei_status, '')) = 'error' OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved' OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> ''))";
    }

    fic_send_einvoice_bulk_refresh_pdo($pdo);
    $countSql = "SELECT COUNT(*) FROM fatture f JOIN clienti c ON c.id = f.cliente_id {$where}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $candidateTotal = (int)$countStmt->fetchColumn();

    $sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            {$where}
            ORDER BY f.data_emissione DESC, f.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    fic_send_einvoice_bulk_refresh_pdo($pdo);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cfg = fic_config();
    if ($onlyExistingFic) {
        $cfg['prefer_existing_fic_only'] = true;
    }
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $ok = 0;
    $failed = 0;
    $skipped = 0;
    $usageLimitReached = false;
    $hardStop = false;

    foreach ($rows as $idx => $fattura) {
        fic_send_einvoice_bulk_refresh_pdo($pdo);
        $processedBatch++;
        $fatturaId = (int)($fattura['id'] ?? 0);
        $cliente = trim((string)($fattura['nome'] ?? ''));
        if ($cliente === '') {
            $cliente = 'Cliente #' . (string)$fatturaId;
        }
        $importo = (int)round(((float)($fattura['totale'] ?? 0)) * 100);

        if ($fatturaId <= 0) {
            $results[] = [
                'cliente' => $cliente,
                'importo' => $importo,
                'esito' => 'SKIP',
                'msg' => 'ID fattura non valido.',
                'id' => '-',
            ];
            $skipped++;
            continue;
        }

        $missingDataError = fic_einvoice_missing_data_error($fattura);
        if ($missingDataError !== null) {
            // Non saltare: prova comunque e lascia la validazione finale a FIC.
            fic_send_einvoice_bulk_safe_log($pdo, $fatturaId, 'send_einvoice_bulk', 'warning', 'Pre-check incompleto: tentativo invio con validazione FIC. ' . $missingDataError);
        }

        try {
            fic_send_einvoice_bulk_refresh_pdo($pdo);
            $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
            $righeStmt->execute([':id' => $fatturaId]);
            $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'manuale_bulk');
            fic_send_einvoice_bulk_safe_log($pdo, $fatturaId, 'send_einvoice_bulk', 'ok', 'Invio SDI massivo completato.', [
                'numero' => $fattura['numero'] ?? null,
            ]);
            $results[] = [
                'cliente' => $cliente,
                'importo' => $importo,
                'esito' => 'OK',
                'msg' => 'Inviata allo SDI con successo.',
                'id' => (string)$fatturaId,
            ];
            $ok++;
        } catch (Throwable $e) {
            $err = substr((string)$e->getMessage(), 0, 1000);
            if (!$onlyExistingFic && fic_send_einvoice_bulk_is_duplicate_not_recoverable($err)) {
                fic_send_einvoice_bulk_mark_duplicate_unresolved($pdo, $fatturaId);
                fic_send_einvoice_bulk_safe_update_tracking($pdo, $fatturaId, 'duplicate_unresolved', $err, false);
                fic_send_einvoice_bulk_safe_log($pdo, $fatturaId, 'send_einvoice_bulk', 'warning', 'Duplicato FIC non recuperabile: marcata duplicate_unresolved e saltata.');
                $results[] = [
                    'cliente' => $cliente,
                    'importo' => $importo,
                    'esito' => 'SKIP',
                    'msg' => 'Duplicato non convertibile automaticamente. Contrassegnata come duplicate_unresolved (saltata nei prossimi batch).',
                    'id' => (string)$fatturaId,
                ];
                $skipped++;
                continue;
            }

            if (fic_send_einvoice_bulk_is_db_gone($err)) {
                fic_send_einvoice_bulk_refresh_pdo($pdo);
            }

            fic_send_einvoice_bulk_safe_update_tracking($pdo, $fatturaId, 'error', $err, false);
            fic_send_einvoice_bulk_safe_log($pdo, $fatturaId, 'send_einvoice_bulk', 'error', 'Errore invio SDI massivo: ' . substr($err, 0, 180));
            $results[] = [
                'cliente' => $cliente,
                'importo' => $importo,
                'esito' => 'ERRORE',
                'msg' => $err,
                'id' => (string)$fatturaId,
            ];
            $failed++;

            if (fic_send_einvoice_bulk_is_usage_limit_error($err)) {
                $usageLimitReached = true;
            }
            if ($usageLimitReached) {
                $remainingHardStop = count($rows) - ($idx + 1);
                if ($remainingHardStop > 0) {
                    $results[] = [
                        'cliente' => '-',
                        'importo' => 0,
                        'esito' => 'ERRORE',
                        'msg' => 'Elaborazione interrotta: limite API Fatture in Cloud raggiunto (' . $remainingHardStop . ' elementi non elaborati in questo batch).',
                        'id' => '-',
                    ];
                }
                $hardStop = true;
                break;
            }
        }
    }

    $remainingAfterBatch = max(0, $candidateTotal - ($offset + $processedBatch));

    if ($processedBatch === 0) {
        $summaryClass = 'info';
        $summaryText = 'Nessuna fattura da elaborare.';
    } else {
        if ($failed === 0 && $skipped === 0) {
            $summaryClass = 'success';
            $summaryText = 'Elaborazione completata con successo.';
        } elseif ($failed === 0 && $skipped > 0) {
            $summaryClass = 'warning';
            $summaryText = 'Elaborazione completata con elementi saltati.';
        } elseif ($ok === 0) {
            $summaryClass = 'error';
            $summaryText = 'Elaborazione completata: nessuna e-fattura inviata.';
        } else {
            $summaryClass = 'warning';
            $summaryText = 'Elaborazione completata con errori.';
        }
    }

    fic_send_einvoice_bulk_safe_log($pdo, null, 'send_einvoice_bulk', $failed > 0 ? 'error' : 'ok', 'Invio massivo SDI completato (batch).', [
        'ok' => $ok,
        'failed' => $failed,
        'skipped' => $skipped,
        'filtro' => $q,
        'sdi_flow' => $sdiFlow,
        'document_type' => $docType,
        'exclude_clients_count' => count($excludeTerms),
        'batch' => $batchSize,
        'offset' => $offset,
        'processed' => $processedBatch,
        'candidate_total' => $candidateTotal,
    ]);

    $nextUrl = null;
    if ($remainingAfterBatch > 0 && !$hardStop) {
        $nextParams = [
            'document_type' => $docType,
            'batch' => $batchSize,
            'offset' => $offset + $processedBatch,
        ];
        if ($q !== '') {
            $nextParams['q'] = $q;
        }
        if ($idsCsv !== '') {
            $nextParams['ids_csv'] = $idsCsv;
        }
        if ($autoNext) {
            $nextParams['autonext'] = 1;
        }
        if ($onlyExistingFic) {
            $nextParams['only_existing_fic'] = 1;
        }
        if ($sdiFlow !== '') {
            $nextParams['sdi_flow'] = $sdiFlow;
        }
        if ($excludeRaw !== '') {
            $nextParams['exclude_clients'] = $excludeRaw;
        }
        $nextUrl = 'fic_send_einvoice_bulk.php?' . http_build_query($nextParams);
    }

    fic_send_einvoice_bulk_render_page(
        $title,
        $summaryClass,
        $summaryText,
        $results,
        $batchSize,
        $candidateTotal,
        $processedBatch,
        $remainingAfterBatch,
        $nextUrl,
        $autoNext
    );
} catch (Throwable $e) {
    $results[] = [
        'cliente' => '-',
        'importo' => 0,
        'esito' => 'ERRORE',
        'msg' => substr((string)$e->getMessage(), 0, 1000),
        'id' => '-',
    ];
    fic_send_einvoice_bulk_render_page(
        $title,
        'error',
        'Elaborazione interrotta per errore.',
        $results,
        (int)($_GET['batch'] ?? 5),
        $candidateTotal,
        $processedBatch,
        $remainingAfterBatch,
        null,
        false
    );
}
exit;
