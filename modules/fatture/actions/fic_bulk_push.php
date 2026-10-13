<?php
/**
 * Invio massivo fatture pronte a Fatture in Cloud (solo sync, NO email).
 * Pagina provvisoria - cancellare dopo l'uso.
 */
ini_set('display_errors', '1');
ini_set('max_execution_time', '600');
error_reporting(E_ALL);

$action = (string)($_GET['action'] ?? '');
if ($action !== '') {
    // Per endpoint AJAX restituiamo solo JSON, mai HTML di warning/notices.
    ini_set('display_errors', '0');
}

require __DIR__ . '/../../../auth.php';
require_login();
if (!has_admin_access()) { http_response_code(403); die('Accesso non autorizzato.'); }

$pdo    = require __DIR__ . '/../../../config/db.php';
$config = require __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$cfg       = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') $companyId = (string)fic_get_company_id();
if ($companyId === '') die('Errore: company_id FIC mancante.');

$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

// ─── AJAX: forza collegamento manuale fattura <-> FIC doc ───────────────
if ($action === 'force_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $fatturaId = (int)($_POST['id'] ?? 0);
    $ficDocId = trim((string)($_POST['fic_document_id'] ?? ''));
    if ($fatturaId <= 0 || $ficDocId === '') {
        echo json_encode(['ok' => false, 'error' => 'ID fattura o FIC doc ID mancante']); exit;
    }
    // Carica dati fattura locale
    $stmt = $pdo->prepare("SELECT * FROM fatture WHERE id = ? LIMIT 1");
    $stmt->execute([$fatturaId]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        echo json_encode(['ok' => false, 'error' => 'Fattura non trovata']); exit;
    }
    // Recupera dati documento FIC
    try {
        $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$ficDocId}");
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            echo json_encode(['ok' => false, 'error' => 'Documento FIC non trovato o non accessibile']); exit;
        }
        $fic = $resp['json']['data'] ?? [];
        // Confronta numero fattura
        $localNum = $fattura['numero'] ?? '';
        $ficNum = $fic['number'] ?? '';
        if ((string)$localNum !== (string)$ficNum) {
            echo json_encode(['ok' => false, 'error' => 'Numero fattura locale e FIC diversi']); exit;
        }
        // (Opzionale: confronta altri dati, es. importo)
        $localTot = round((float)$fattura['totale'], 2);
        $ficTot = isset($fic['amount_net']) ? round((float)$fic['amount_net'], 2) : null;
        if ($ficTot !== null && abs($localTot - $ficTot) > 0.01) {
            echo json_encode(['ok' => false, 'error' => 'Totale locale e FIC diversi']); exit;
        }
        // Forza collegamento
        if (!fic_force_local_link($pdo, $fatturaId, $ficDocId, $fic['status'] ?? 'linked')) {
            echo json_encode(['ok' => false, 'error' => 'Impossibile aggiornare DB locale']); exit;
        }
        fatture_log($pdo, $fatturaId, 'fic_force_link', 'ok', 'Collegamento forzato a FIC doc ' . $ficDocId, [
            'fic_document_id' => $ficDocId,
            'fic_status' => $fic['status'] ?? 'linked',
        ]);
        echo json_encode(['ok' => true, 'message' => 'Collegamento forzato eseguito', 'fic_document_id' => $ficDocId]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function fic_force_local_link(PDO $pdo, int $fatturaId, $docId, $status): bool
{
    $docId = trim((string)$docId);
    if ($fatturaId <= 0 || $docId === '') {
        return false;
    }
    // Protezione anti-collisione: stesso doc FIC non puo' essere collegato a fatture diverse.
    try {
        $dup = $pdo->prepare("SELECT id FROM fatture WHERE fic_document_id = :doc_id AND id <> :id AND document_type = 'invoice' LIMIT 1");
        $dup->execute([
            ':doc_id' => $docId,
            ':id' => $fatturaId,
        ]);
        if ($dup->fetchColumn()) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    try {
        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
        $upd->execute([
            ':doc_id' => $docId,
            ':status' => $status,
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
        return false;
    }

    try {
        $chk = $pdo->prepare("SELECT fic_document_id FROM fatture WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $fatturaId]);
        $linked = (string)($chk->fetchColumn() ?: '');
        return $linked !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function fic_get_ei_counts(PDO $pdo): array
{
    $sql = "
        SELECT
            SUM(CASE WHEN document_type='invoice' AND fic_document_id IS NOT NULL THEN 1 ELSE 0 END) AS linked,
            SUM(CASE WHEN document_type='invoice' AND fic_document_id IS NOT NULL
                AND LOWER(COALESCE(NULLIF(ei_status,''),'pending')) IN ('sent','processing','accepted','ok') THEN 1 ELSE 0 END) AS ei_done,
            SUM(CASE WHEN document_type='invoice' AND fic_document_id IS NOT NULL
                AND LOWER(COALESCE(NULLIF(ei_status,''),'pending')) IN ('error','failed','rejected','ko','discarded','scartata') THEN 1 ELSE 0 END) AS ei_error,
            SUM(CASE WHEN document_type='invoice' AND fic_document_id IS NOT NULL
                AND LOWER(COALESCE(NULLIF(ei_status,''),'pending')) NOT IN ('sent','processing','accepted','ok') THEN 1 ELSE 0 END) AS ei_todo
        FROM fatture
    ";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'linked' => (int)($row['linked'] ?? 0),
        'ei_done' => (int)($row['ei_done'] ?? 0),
        'ei_error' => (int)($row['ei_error'] ?? 0),
        'ei_todo' => (int)($row['ei_todo'] ?? 0),
    ];
}

// AJAX: pulizia fic_document_id falsi/duplicati
if ($action === 'cleanup') {
    header('Content-Type: application/json');
    $cleaned = 0;
    $details = [];

    // 1. Trova fic_document_id usati da piu fatture (impossibile: 1 FIC doc = 1 fattura)
    $dupStmt = $pdo->query("
        SELECT fic_document_id, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id) as ids
        FROM fatture
        WHERE fic_document_id IS NOT NULL AND document_type = 'invoice'
        GROUP BY fic_document_id
        HAVING COUNT(*) > 1
    ");
    $dups = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dups as $dup) {
        $allIds = explode(',', $dup['ids']);
        // Reset TUTTI (non possiamo sapere quale e quello giusto)
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $resetStmt = $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id IN ($placeholders)");
        $resetStmt->execute($allIds);
        $n = $resetStmt->rowCount();
        $cleaned += $n;
        $details[] = "FIC #{$dup['fic_document_id']}: {$n} fatture resettate (IDs: {$dup['ids']})";
    }

    // 2. Verifica i restanti fic_document_id (1:1) - controlla se il doc su FIC ha numero coerente
    $singlesStmt = $pdo->query("
        SELECT f.id, f.numero, f.fic_document_id
        FROM fatture f
        WHERE f.fic_document_id IS NOT NULL AND f.document_type = 'invoice'
        ORDER BY f.id
        LIMIT 200
    ");
    $singles = $singlesStmt->fetchAll(PDO::FETCH_ASSOC);
    $checkedCount = 0;
    foreach ($singles as $s) {
        try {
            $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$s['fic_document_id']}");
            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $ficSubject = $resp['json']['data']['visible_subject'] ?? '';
                $ficNumero = $resp['json']['data']['number'] ?? '';
                // Se il subject/numero FIC NON contiene il numero locale -> link sbagliato
                $localNum = $s['numero'] ?? '';
                if ($localNum !== '' && strpos($ficSubject, $localNum) === false && (string)$ficNumero !== (string)$localNum) {
                    $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = ?")
                         ->execute([$s['id']]);
                    $cleaned++;
                    $details[] = "Fattura #{$s['id']} ({$localNum}): FIC doc {$s['fic_document_id']} ha numero/oggetto diverso ('{$ficSubject}'), scollegata.";
                }
            } else {
                // Doc non raggiungibile -> reset
                $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = ?")
                     ->execute([$s['id']]);
                $cleaned++;
                $details[] = "Fattura #{$s['id']} ({$s['numero']}): FIC doc {$s['fic_document_id']} non raggiungibile (HTTP {$resp['status']}), scollegata.";
            }
        } catch (Throwable $e) {
            $details[] = "Fattura #{$s['id']}: errore verifica - " . $e->getMessage();
        }
        $checkedCount++;
        usleep(200000); // 200ms tra le richieste API
    }

    // Aggiorna conteggi
    $newReady = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NULL AND document_type = 'invoice'")->fetchColumn();
    $newDone = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NOT NULL AND document_type = 'invoice'")->fetchColumn();

    echo json_encode([
        'ok' => true,
        'cleaned' => $cleaned,
        'checked_singles' => $checkedCount,
        'duplicates_found' => count($dups),
        'new_ready' => $newReady,
        'new_done' => $newDone,
        'details' => $details,
    ]);
    exit;
}

// AJAX: lista fatture pronte
if ($action === 'list') {
    header('Content-Type: application/json');
    $rows = $pdo->query("
        SELECT f.id, f.numero, f.data_emissione, f.stato, f.totale, f.document_type,
               c.nome AS cliente, c.email AS cliente_email, c.piva
        FROM fatture f
        LEFT JOIN clienti c ON c.id = f.cliente_id
        WHERE f.fic_document_id IS NULL AND f.document_type = 'invoice'
        ORDER BY f.data_emissione DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

// AJAX: statistiche live
if ($action === 'stats') {
    header('Content-Type: application/json');
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type='invoice'")->fetchColumn();
    $ready = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NULL AND document_type='invoice'")->fetchColumn();
    $done = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NOT NULL AND document_type='invoice'")->fetchColumn();
    echo json_encode([
        'ok' => true,
        'tot' => $tot,
        'ready' => $ready,
        'done' => $done,
    ]);
    exit;
}

// AJAX: push singola fattura a FIC
// AJAX: statistiche invio SDI (solo fatture collegate a FIC)
if ($action === 'ei_stats') {
    header('Content-Type: application/json');
    $c = fic_get_ei_counts($pdo);
    echo json_encode([
        'ok' => true,
        'linked' => $c['linked'],
        'ei_done' => $c['ei_done'],
        'ei_error' => $c['ei_error'],
        'ei_todo' => $c['ei_todo'],
    ]);
    exit;
}

// AJAX: invio SDI massivo a blocchi (step 2)
if ($action === 'ei_chunk') {
    header('Content-Type: application/json');

    $chunk = (int)($_REQUEST['chunk'] ?? 5);
    if ($chunk < 1) $chunk = 1;
    if ($chunk > 20) $chunk = 20;

    $processed = 0;
    $sentNow = 0;
    $failed = 0;
    $skipped = 0;
    $items = [];
    $startedAt = microtime(true);

    $sel = $pdo->prepare("
        SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
        WHERE f.document_type = 'invoice'
          AND f.fic_document_id IS NOT NULL
          AND LOWER(COALESCE(NULLIF(f.ei_status,''),'pending')) NOT IN ('sent','processing','accepted','ok')
        ORDER BY f.id ASC
        LIMIT :lim
    ");
    $sel->bindValue(':lim', $chunk, PDO::PARAM_INT);
    $sel->execute();
    $batchRows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($batchRows as $fattura) {
        $fatturaId = (int)($fattura['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }
        $processed++;

        $missingDataError = fic_einvoice_missing_data_error($fattura);
        if ($missingDataError !== null) {
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $missingDataError, false);
            fatture_log($pdo, $fatturaId, 'send_einvoice_live', 'info', 'Saltata: ' . $missingDataError, [
                'numero' => $fattura['numero'] ?? null,
            ]);
            $skipped++;
            $items[] = [
                'id' => $fatturaId,
                'numero' => $fattura['numero'] ?? '',
                'esito' => 'skip',
                'message' => $missingDataError,
            ];
            continue;
        }

        $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
        $righeStmt->execute([':id' => $fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'manuale_live');

            $eiStmt = $pdo->prepare("SELECT ei_status FROM fatture WHERE id = :id LIMIT 1");
            $eiStmt->execute([':id' => $fatturaId]);
            $eiStatus = (string)($eiStmt->fetchColumn() ?: 'sent');

            fatture_log($pdo, $fatturaId, 'send_einvoice_live', 'ok', 'Invio SDI live completato.', [
                'numero' => $fattura['numero'] ?? null,
                'ei_status' => $eiStatus,
            ]);
            $sentNow++;
            $items[] = [
                'id' => $fatturaId,
                'numero' => $fattura['numero'] ?? '',
                'esito' => 'ok',
                'message' => 'Inviata allo SDI.',
                'ei_status' => $eiStatus,
            ];
        } catch (Throwable $e) {
            $err = substr((string)$e->getMessage(), 0, 500);
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $err, false);
            fatture_log($pdo, $fatturaId, 'send_einvoice_live', 'error', 'Errore invio SDI live: ' . substr($err, 0, 180), [
                'numero' => $fattura['numero'] ?? null,
            ]);
            $failed++;
            $items[] = [
                'id' => $fatturaId,
                'numero' => $fattura['numero'] ?? '',
                'esito' => 'error',
                'message' => $err,
            ];
        }

        usleep(300000);
    }

    $c = fic_get_ei_counts($pdo);
    echo json_encode([
        'ok' => true,
        'processed' => $processed,
        'sent_now' => $sentNow,
        'failed' => $failed,
        'skipped' => $skipped,
        'linked' => $c['linked'],
        'ei_done' => $c['ei_done'],
        'ei_error' => $c['ei_error'],
        'ei_todo' => $c['ei_todo'],
        'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'items' => $items,
    ]);
    exit;
}

if ($action === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID mancante']); exit; }

    $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                           FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) { echo json_encode(['ok' => false, 'error' => 'Fattura non trovata']); exit; }

    $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC");
    $righeStmt->execute([$id]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $syncCfg = $cfg;
        $syncCfg['force_e_invoice'] = false;
        $syncCfg['allow_duplicate_force_create'] = true;
        $syncCfg['strict_no_link_on_duplicate'] = true;

        [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $syncCfg, $companyId);
        if (!$docId) throw new RuntimeException('Documento FIC non creato.');

        $docCheck = fic_get_document_status($companyId, (string)$docId);
        if ($docCheck !== null && !fic_doc_matches_local_invoice($docCheck, $fattura)) {
            $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = :id")
                ->execute([':id' => $id]);
            throw new RuntimeException('Documento FIC non coerente con la fattura locale (numero/oggetto). Collegamento annullato.');
        }

        fic_publish_document_if_draft($companyId, (string)$docId);
        if (!fic_force_local_link($pdo, $id, $docId, $status)) {
            throw new RuntimeException('Sync FIC completata ma link locale non salvato nel DB.');
        }
        fatture_log($pdo, $id, 'fic_bulk_push', 'ok', 'Fattura inviata a FIC (bulk push).', [
            'fic_document_id' => $docId, 'fic_status' => $status,
        ]);
        echo json_encode(['ok' => true, 'doc_id' => $docId, 'status' => $status]);
    } catch (Throwable $e) {
        // Non lasciare mai collegamenti locali incoerenti in caso di errore.
        try {
            $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = :id")
                ->execute([':id' => $id]);
        } catch (Throwable $ignore) {}

        $msg = $e->getMessage();
        fatture_log($pdo, $id, 'fic_bulk_push', 'error', 'Errore push FIC: ' . $msg);
        $code = 0;
        if (preg_match('/HTTP\s+429/', $msg)) $code = 429;
        if (strpos($msg, 'FIC_DUPLICATE') !== false) $code = 409;
        echo json_encode(['ok' => false, 'error' => $msg, 'code' => $code]);
    }
    exit;
}

// AJAX: verifica documenti su FIC
if ($action === 'verify') {
    header('Content-Type: application/json');

    $stmt = $pdo->query("
        SELECT f.id, f.numero, f.data_emissione, f.totale, f.fic_document_id,
               c.nome AS cliente
        FROM fatture f
        LEFT JOIN clienti c ON c.id = f.cliente_id
        WHERE f.document_type = 'invoice' AND f.fic_document_id IS NOT NULL
        ORDER BY f.id DESC
        LIMIT 50
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $out = [];
    foreach ($rows as $row) {
        $docId = trim((string)($row['fic_document_id'] ?? ''));
        $info = [
            'reachable' => false,
            'fic_number' => null,
            'fic_date' => null,
            'fic_status' => null,
            'fic_subject' => null,
        ];

        if ($docId !== '') {
            try {
                $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
                if (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300) {
                    $doc = $resp['json']['data'] ?? [];
                    $info['reachable'] = true;
                    $info['fic_number'] = $doc['number'] ?? null;
                    $info['fic_date'] = $doc['date'] ?? null;
                    $info['fic_status'] = $doc['status'] ?? null;
                    $info['fic_subject'] = $doc['visible_subject'] ?? null;
                }
            } catch (Throwable $e) {
                // Documento non raggiungibile: lasciamo reachable=false.
            }
        }

        $row['fic_info'] = $info;
        $out[] = $row;

        usleep(150000);
    }

    echo json_encode([
        'ok' => true,
        'rows' => $out,
    ]);
    exit;
}

// AJAX: sync live a chunk (backend reale)
if ($action === 'sync_chunk') {
    header('Content-Type: application/json');

    $chunk = (int)($_REQUEST['chunk'] ?? 10);
    if ($chunk < 1) $chunk = 1;
    if ($chunk > 25) $chunk = 25;

    // Auto-igiene: stesso fic_document_id collegato a piu' fatture e' sempre anomalo.
    $autoCleanedDuplicates = 0;
    try {
        $dupStmt = $pdo->query("
            SELECT fic_document_id
            FROM fatture
            WHERE fic_document_id IS NOT NULL AND document_type='invoice'
            GROUP BY fic_document_id
            HAVING COUNT(*) > 1
        ");
        $dupDocIds = $dupStmt ? $dupStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($dupDocIds as $dupDocId) {
            $rst = $pdo->prepare("
                UPDATE fatture
                SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL
                WHERE fic_document_id = :doc_id AND document_type='invoice'
            ");
            $rst->execute([':doc_id' => (string)$dupDocId]);
            $autoCleanedDuplicates += (int)$rst->rowCount();
        }
    } catch (Throwable $e) {
        // Non bloccare il sync live in caso di problemi durante la pulizia preventiva.
    }

    $processed = 0;
    $created = 0;
    $linked = 0;
    $failed = 0;
    $skipped = 0;
    $items = [];
    $missing_clients = [];

    // Seleziona le fatture pronte da inviare (senza FIC ID)
    $sel = $pdo->prepare("
        SELECT f.*, c.nome AS cliente_nome, c.fic_client_id
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
        WHERE f.fic_document_id IS NULL AND f.document_type = 'invoice'
        ORDER BY f.id ASC
        LIMIT :lim
    ");
    $sel->bindValue(':lim', $chunk, PDO::PARAM_INT);
    $sel->execute();
    $batchRows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($batchRows as $fattura) {
        $id = (int)($fattura['id'] ?? 0);
        if ($id <= 0) continue;
        $processed++;

        // Skip se cliente non collegato a FIC
        if (empty($fattura['fic_client_id'])) {
            $skipped++;
            $missing_clients[] = [
                'fattura_id' => $id,
                'numero' => $fattura['numero'] ?? '',
                'cliente_nome' => $fattura['cliente_nome'] ?? '',
            ];
            $items[] = [
                'id' => $id,
                'numero' => $fattura['numero'] ?? '',
                'esito' => 'skipped',
                'message' => 'Cliente non collegato a Fatture in Cloud',
                'fic_document_id' => null,
                'fic_status' => null,
                'reachable' => false,
            ];
            continue;
        }

        $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC");
        $righeStmt->execute([$id]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $syncCfg = $cfg;
            $syncCfg['force_e_invoice'] = false;
            $syncCfg['allow_duplicate_force_create'] = false;

            [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $syncCfg, $companyId);
            if (!$docId) throw new RuntimeException('Documento FIC non creato.');

            $docCheck = fic_get_document_status($companyId, (string)$docId);
            if ($docCheck !== null && !fic_doc_matches_local_invoice($docCheck, $fattura)) {
                $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = :id")
                    ->execute([':id' => $id]);
                throw new RuntimeException('Documento FIC non coerente con la fattura locale (numero/oggetto). Collegamento annullato.');
            }

            fic_publish_document_if_draft($companyId, (string)$docId);
            if (!fic_force_local_link($pdo, $id, $docId, $status)) {
                throw new RuntimeException('Sync FIC completata ma link locale non salvato nel DB.');
            }

            $reachable = $docCheck !== null;

            if (!empty($fattura['fic_document_id'])) {
                $linked++;
            } else {
                $created++;
            }

            $msg = $reachable
                ? 'Sincronizzata e verificata su FIC.'
                : 'Sincronizzata, verifica immediata non disponibile.';

            fatture_log($pdo, $id, 'fic_sync_live', $reachable ? 'ok' : 'info', $msg, [
                'fic_document_id' => $docId,
                'fic_status' => $status,
                'reachable' => $reachable,
            ]);

            $items[] = [
                'id' => $id,
                'numero' => $fattura['numero'] ?? '',
                'esito' => $reachable ? 'ok' : 'info',
                'message' => $msg,
                'fic_document_id' => (string)$docId,
                'fic_status' => $status,
                'reachable' => $reachable,
            ];
        } catch (Throwable $e) {
            $err = substr((string)$e->getMessage(), 0, 500);
            // Skippa duplicati: se già presente su FIC, considera ok e passa oltre
            if (strpos($err, 'FIC_DUPLICATE') !== false || strpos($err, 'già su FIC') !== false) {
                $linked++;
                $items[] = [
                    'id' => $id,
                    'numero' => $fattura['numero'] ?? '',
                    'esito' => 'skipped',
                    'message' => 'Fattura già presente su FIC, saltata',
                    'fic_document_id' => null,
                    'fic_status' => null,
                    'reachable' => false,
                ];
                continue;
            }
            // Se token scaduto, interrompi tutto e restituisci errore
            if (strpos($err, 'invalid_token') !== false || strpos($err, 'token is expired') !== false) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Token FIC scaduto. Effettua nuovamente il login/collega FIC.',
                    'code' => 401,
                    'processed' => $processed,
                    'created' => $created,
                    'linked' => $linked,
                    'failed' => $failed,
                    'skipped' => $skipped,
                    'auto_cleaned_duplicates' => $autoCleanedDuplicates,
                    'items' => $items,
                    'missing_clients' => $missing_clients,
                ]);
                exit;
            }
            $failed++;
            fatture_log($pdo, $id, 'fic_sync_live', 'error', 'Errore sync live FIC: ' . substr($err, 0, 180), [
                'numero' => $fattura['numero'] ?? null,
            ]);
            $items[] = [
                'id' => $id,
                'numero' => $fattura['numero'] ?? '',
                'esito' => 'error',
                'message' => $err,
                'fic_document_id' => null,
                'fic_status' => null,
                'reachable' => false,
            ];
        }

        usleep(250000);
    }

    // Conteggi live
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type='invoice'")->fetchColumn();
    $ready = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NULL AND document_type='invoice'")->fetchColumn();
    $done = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NOT NULL AND document_type='invoice'")->fetchColumn();

    echo json_encode([
        'ok' => true,
        'processed' => $processed,
        'created' => $created,
        'linked' => $linked,
        'failed' => $failed,
        'skipped' => $skipped,
        'auto_cleaned_duplicates' => $autoCleanedDuplicates,
        'tot' => $tot,
        'ready' => $ready,
        'done' => $done,
        'items' => $items,
        'missing_clients' => $missing_clients,
    ]);
    exit;
}

// Fine logica PHP, inizio output HTML/JS
$countReady = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NULL AND document_type = 'invoice'")->fetchColumn();
$countDone  = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NOT NULL AND document_type = 'invoice'")->fetchColumn();
$eiCounts = fic_get_ei_counts($pdo);

require __DIR__ . '/../../../layouts/header.php';
?>
<style>
.bp-card{border:1px solid #e2e8f0;border-radius:12px;padding:18px;background:#fff;margin-bottom:14px}
.bp-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px}
.bp-kpi .k{border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#f8fafc}
.bp-kpi .k .label{font-size:11px;color:#64748b;text-transform:uppercase}
.bp-kpi .k .val{font-size:22px;font-weight:700;color:#0f172a;margin-top:2px}
.bp-table{width:100%;border-collapse:collapse;font-size:13px}
.bp-table th,.bp-table td{border-bottom:1px solid #e5e7eb;padding:8px 10px;text-align:left}
.bp-table th{background:#f8fafc;color:#334155;font-weight:700}
.bp-table tbody tr:hover{background:#f0f9ff}
.bp-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}
.bp-badge.ok{background:#dcfce7;color:#166534}.bp-badge.ko{background:#fee2e2;color:#991b1b}
.bp-badge.warn{background:#fef3c7;color:#92400e}.bp-badge.run{background:#dbeafe;color:#1e40af}
.bp-btn{padding:6px 14px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}
.bp-btn.primary{background:#3b82f6;color:#fff}.bp-btn.primary:hover{background:#2563eb}
.bp-btn:disabled{opacity:.5;cursor:not-allowed}
.bp-progress{margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff;display:none}
.bp-bar{height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:8px}
.bp-bar .fill{height:100%;background:#3b82f6;transition:width .3s;width:0}
.bp-log{margin-top:10px;max-height:250px;overflow-y:auto;font-size:12px;font-family:monospace;padding:8px;background:#f1f5f9;border-radius:6px}
.bp-log div{padding:2px 0;border-bottom:1px solid #e2e8f0}
.bp-log .ok{color:#166534}.bp-log .err{color:#991b1b}
</style>
<div class="wrap">
    <div class="bp-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div>
                <h2 style="margin:0">Invio massivo a Fatture in Cloud</h2>
                <p class="muted" style="margin:4px 0 0 0">Invia tutte le fatture pronte (senza FIC ID) a FIC. Solo creazione documento, nessuna email.</p>
            </div>
            <a class="btn secondary" href="<?php echo htmlspecialchars($baseUrl); ?>/modules/fatture/index.php">&larr; Fatture</a>
        </div>
    </div>

    <div class="bp-kpi">
        <div class="k"><div class="label">Da inviare a FIC</div><div class="val" style="color:#92400e" id="kpi-ready"><?php echo $countReady; ?></div></div>
        <div class="k"><div class="label">Già su FIC</div><div class="val" style="color:#166534" id="kpi-done"><?php echo $countDone; ?></div></div>
        <div class="k"><div class="label">Inviate ora</div><div class="val" style="color:#1e40af" id="kpi-sent">0</div></div>
        <div class="k"><div class="label">Errori</div><div class="val" style="color:#991b1b" id="kpi-err">0</div></div>
        <div class="k"><div class="label">SDI da inviare</div><div class="val" style="color:#b45309" id="kpi-ei-todo"><?php echo (int)$eiCounts['ei_todo']; ?></div></div>
        <div class="k"><div class="label">SDI in flusso/ok</div><div class="val" style="color:#166534" id="kpi-ei-ok"><?php echo (int)$eiCounts['ei_done']; ?></div></div>
        <div class="k"><div class="label">SDI errori</div><div class="val" style="color:#991b1b" id="kpi-ei-err"><?php echo (int)$eiCounts['ei_error']; ?></div></div>
    </div>

    <div class="bp-card">
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="bp-btn primary" id="btn-load" onclick="loadList()">Carica lista fatture</button>
            <button class="bp-btn primary" id="btn-push-all" onclick="pushAll()" disabled>Invia tutte a FIC</button>
            <button class="bp-btn primary" id="btn-live-start" onclick="startLiveSync()" style="background:#0ea5e9">Avvia Sync LIVE (reale)</button>
            <button class="bp-btn primary" id="btn-live-stop" onclick="stopLiveSync()" style="background:#475569" disabled>Stop</button>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
                Chunk
                <input type="number" id="live-chunk" min="1" max="25" value="10" style="width:62px;padding:4px 6px;">
            </label>
            <button class="bp-btn primary" id="btn-ei-start" onclick="startEiLive()" style="background:#7c3aed">Avvia Invio SDI LIVE</button>
            <button class="bp-btn primary" id="btn-ei-stop" onclick="stopEiLive()" style="background:#475569" disabled>Stop SDI</button>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
                Chunk SDI
                <input type="number" id="ei-chunk" min="1" max="20" value="5" style="width:62px;padding:4px 6px;">
            </label>
            <button class="bp-btn primary" id="btn-verify" onclick="verifyFic()" style="background:#059669">Verifica documenti su FIC</button>
            <button class="bp-btn primary" id="btn-cleanup" onclick="cleanupFic()" style="background:#dc2626">Pulizia FIC IDs falsi</button>
        </div>

        <div class="bp-progress" id="progress">
            <strong id="prog-label">...</strong>
            <div class="bp-bar"><div class="fill" id="prog-fill"></div></div>
            <div class="bp-log" id="prog-log"></div>
        </div>
    </div>

    <div class="bp-card">
        <h3 style="margin:0 0 10px">Fatture pronte</h3>
        <div style="overflow-x:auto">
            <table class="bp-table">
                <thead><tr>
                    <th><input type="checkbox" id="sel-all" checked></th>
                    <th>N. Fattura</th><th>Data</th><th>Cliente</th><th>P.IVA</th>
                    <th style="text-align:right">Totale</th><th>Stato</th><th>Esito FIC</th>
                </tr></thead>
                <tbody id="tbody"><tr><td colspan="8" class="muted" style="text-align:center;padding:20px">Clicca "Carica lista fatture".</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
<script>
// Aggiorna la cella di risultato nella tabella delle fatture
function appendResultRow(it) {
    var cell = document.getElementById('res-' + it.id);
    if (!cell) return;
    if (it.esito === 'error') {
        cell.innerHTML = '<span class="bp-badge ko" title="' + esc(it.message || '') + '">Errore</span>';
    } else if (it.esito === 'skipped') {
        cell.innerHTML = '<span class="bp-badge warn" title="' + esc(it.message || '') + '">Saltata</span>';
    } else if (it.reachable) {
        cell.innerHTML = '<span class="bp-badge ok">FIC ' + esc(String(it.fic_document_id || '-')) + '</span>';
    } else if (it.fic_document_id) {
        cell.innerHTML = '<span class="bp-badge ok">FIC ' + esc(String(it.fic_document_id || '-')) + '</span>';
    } else {
        cell.innerHTML = '<span style="color:#9ca3af">—</span>';
    }
}
var rows=[], sent=0, errs=0, baseDelay=3500, liveRunning=false, eiRunning=false, eiSent=0, eiErr=0, eiSkip=0;
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function parseJsonSafe(response){
    return response.text().then(function(txt){
        try{
            return JSON.parse(txt);
        }catch(e){
            var snippet = (txt||'').replace(/\s+/g,' ').slice(0,220);
            throw new Error('Risposta non JSON dal server. ' + snippet);
        }
    });
}

document.getElementById('sel-all').addEventListener('change',function(){
    document.querySelectorAll('.row-cb').forEach(function(c){c.checked=this.checked}.bind(this));
});

function loadList(){
    var btn=document.getElementById('btn-load');
    btn.disabled=true; btn.textContent='Caricamento...';
    fetch('fic_bulk_push.php?action=list',{credentials:'same-origin'})
    .then(parseJsonSafe)
    .then(function(d){
        rows=d.rows||[];
        renderTable();
        document.getElementById('kpi-ready').textContent=rows.length;
        if(rows.length) document.getElementById('btn-push-all').disabled=false;
        btn.disabled=false; btn.textContent='Carica lista fatture';
    }).catch(function(e){btn.textContent='Errore'; btn.disabled=false;});
}

function refreshLiveStats(){
    return fetch('fic_bulk_push.php?action=stats',{credentials:'same-origin'})
    .then(parseJsonSafe)
    .then(function(d){
        if(!d || !d.ok) return;
        document.getElementById('kpi-ready').textContent=d.ready;
        document.getElementById('kpi-done').textContent=d.done;
    }).catch(function(){});
}

function refreshEiStats(){
    return fetch('fic_bulk_push.php?action=ei_stats',{credentials:'same-origin'})
    .then(parseJsonSafe)
    .then(function(d){
        if(!d || !d.ok) return;
        document.getElementById('kpi-ei-todo').textContent=d.ei_todo;
        document.getElementById('kpi-ei-ok').textContent=d.ei_done;
        document.getElementById('kpi-ei-err').textContent=d.ei_error;
    }).catch(function(){});
}

function renderTable(){
    var body=document.getElementById('tbody');
    if(!rows.length){body.innerHTML='<tr><td colspan="8" class="muted" style="text-align:center">Nessuna fattura da inviare.</td></tr>';return;}
    var h='';
    rows.forEach(function(r){
        h+='<tr id="r-'+r.id+'">'
          +'<td><input type="checkbox" class="row-cb" data-id="'+r.id+'" checked></td>'
          +'<td><strong>'+esc(r.numero)+'</strong></td>'
          +'<td>'+esc(r.data_emissione)+'</td>'
          +'<td>'+esc(r.cliente||'-')+'</td>'
          +'<td style="font-size:12px">'+esc(r.piva||'-')+'</td>'
          +'<td style="text-align:right;font-weight:600">&euro;'+Number(r.totale).toLocaleString('it-IT',{minimumFractionDigits:2})+'</td>'
          +'<td><span class="bp-badge '+(r.stato==='pagata'?'ok':'warn')+'">'+esc(r.stato)+'</span></td>'
          +'<td id="res-'+r.id+'"><span style="color:#9ca3af">-</span></td>'
          +'</tr>';
    });
    body.innerHTML=h;
}

function pushAll(){
    var cbs=document.querySelectorAll('.row-cb:checked');
    var ids=[]; cbs.forEach(function(c){ids.push(Number(c.dataset.id))});
    if(!ids.length){alert('Seleziona almeno una fattura.');return;}
    if(!confirm('Inviare '+ids.length+' fatture a Fatture in Cloud?')) return;

    var btn=document.getElementById('btn-push-all');
    btn.disabled=true; btn.textContent='Invio in corso...';
    var prog=document.getElementById('progress'); prog.style.display='block';
    var log=document.getElementById('prog-log'); log.innerHTML='';
    sent=0; errs=0;

    var i=0;
    function next(){
        if(i>=ids.length){
            document.getElementById('prog-label').textContent='Completato: '+sent+' inviate, '+errs+' errori.';
            document.getElementById('prog-fill').style.width='100%';
            btn.textContent='Invia tutte a FIC'; btn.disabled=false;
            return;
        }
        var id=ids[i]; i++;
        var pct=Math.round((i/ids.length)*100);
        document.getElementById('prog-label').textContent='Invio '+i+'/'+ids.length+'...';
        document.getElementById('prog-fill').style.width=pct+'%';

        var fd=new FormData(); fd.append('id',id);
        var cell=document.getElementById('res-'+id);
        pushOne(id,fd,0,cell);
    }

    function pushOne(id,fd,retryCount,cell){
        if(cell && retryCount===0) cell.innerHTML='<span class="bp-badge run">Invio...</span>';
        fetch('fic_bulk_push.php?action=push',{method:'POST',body:fd,credentials:'same-origin'})
        .then(parseJsonSafe)
        .then(function(d){
            if(!d.ok && d.code===429 && retryCount<5){
                var wait=(retryCount+1)*15000;
                if(cell) cell.innerHTML='<span class="bp-badge warn">429 riprovo...</span>';
                log.innerHTML+='<div style="color:#92400e">[retry] #'+id+': Rate limit 429, riprovo tra '+(wait/1000)+'s...</div>';
                log.scrollTop=log.scrollHeight;
                setTimeout(function(){pushOne(id,fd,retryCount+1,cell)},wait);
                return;
            }
            if(d.ok){
                sent++;
                document.getElementById('kpi-sent').textContent=sent;
                var label=d.note?'Collegata FIC '+esc(String(d.doc_id)):'FIC '+esc(String(d.doc_id));
                if(cell) cell.innerHTML='<span class="bp-badge ok">'+label+'</span>';
                log.innerHTML+='<div class="ok">[OK] #'+id+' -> '+label+'</div>';
            } else {
                errs++;
                document.getElementById('kpi-err').textContent=errs;
                if(cell) cell.innerHTML='<span class="bp-badge ko" title="'+esc(d.error||'')+'">Errore</span>';
                log.innerHTML+='<div class="err">[ERR] #'+id+': '+esc(d.error||'sconosciuto')+'</div>';
            }
            log.scrollTop=log.scrollHeight;
            setTimeout(next,baseDelay);
        }).catch(function(e){
            errs++;
            document.getElementById('kpi-err').textContent=errs;
            if(cell) cell.innerHTML='<span class="bp-badge ko">Errore rete</span>';
            log.innerHTML+='<div class="err">[ERR] #'+id+': '+esc(e.message)+'</div>';
            setTimeout(next,baseDelay);
        });
    }
    next();
}

function stopLiveSync(){
    liveRunning=false;
    document.getElementById('btn-live-stop').disabled=true;
}

function stopEiLive(){
    eiRunning=false;
    document.getElementById('btn-ei-stop').disabled=true;
}

function startEiLive(){
    if(eiRunning) return;
    if(liveRunning){
        alert('Ferma prima la sincronizzazione FIC.');
        return;
    }
    if(!confirm('Avvio invio massivo SDI (fatture elettroniche) per le fatture gia collegate a FIC?')) return;
    eiRunning=true;
    eiSent=0; eiErr=0; eiSkip=0;

    var btnStart=document.getElementById('btn-ei-start');
    var btnStop=document.getElementById('btn-ei-stop');
    var chunkInput=document.getElementById('ei-chunk');
    var chunk=Math.max(1,Math.min(20,parseInt(chunkInput.value||'5')));
    chunkInput.value=String(chunk);

    btnStart.disabled=true;
    btnStart.textContent='Invio SDI in corso...';
    btnStop.disabled=false;

    var prog=document.getElementById('progress'); prog.style.display='block';
    var label=document.getElementById('prog-label');
    var fill=document.getElementById('prog-fill');
    var log=document.getElementById('prog-log');
    label.textContent='Preparazione invio SDI LIVE...';
    fill.style.width='0%';

    var loopCount=0;
    function tick(){
        if(!eiRunning){
            label.textContent='Invio SDI fermato manualmente.';
            btnStart.disabled=false;
            btnStart.textContent='Avvia Invio SDI LIVE';
            btnStop.disabled=true;
            return;
        }
        fetch('fic_bulk_push.php?action=ei_chunk',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'chunk='+encodeURIComponent(chunk)
        })
        .then(parseJsonSafe)
        .then(function(d){
            if(!d || !d.ok){
                throw new Error((d&&d.error)?d.error:'Risposta non valida');
            }
            loopCount++;
            eiSent += (d.sent_now||0);
            eiErr += (d.failed||0);
            eiSkip += (d.skipped||0);

            document.getElementById('kpi-ei-todo').textContent=d.ei_todo;
            document.getElementById('kpi-ei-ok').textContent=d.ei_done;
            document.getElementById('kpi-ei-err').textContent=d.ei_error;

            var donePct = d.linked>0 ? Math.max(0, Math.min(100, Math.round((d.ei_done/d.linked)*100))) : 100;
            fill.style.width = donePct+'%';
            label.textContent='SDI LIVE: blocco '+loopCount+' • Processate '+d.processed+' • Da inviare '+d.ei_todo+' • Completate '+donePct+'%';

            (d.items||[]).forEach(function(it){
                if(it.esito==='error'){
                    log.innerHTML+='<div class="err">✕ SDI #'+it.id+' '+esc(it.numero||'')+' — '+esc(it.message||'')+'</div>';
                }else if(it.esito==='skip'){
                    log.innerHTML+='<div style="color:#92400e">⚠ SDI #'+it.id+' '+esc(it.numero||'')+' — '+esc(it.message||'')+'</div>';
                }else{
                    log.innerHTML+='<div class="ok">✓ SDI #'+it.id+' '+esc(it.numero||'')+' inviato ('+esc(it.ei_status||'sent')+')</div>';
                }
            });
            log.scrollTop=log.scrollHeight;

            if((d.processed||0)===0 || (d.ei_todo||0)===0){
                eiRunning=false;
                btnStart.disabled=false;
                btnStart.textContent='Avvia Invio SDI LIVE';
                btnStop.disabled=true;
                label.textContent='Invio SDI completato. Inviate: '+eiSent+', errori: '+eiErr+', saltate: '+eiSkip+'.';
                fill.style.width='100%';
                return;
            }
            setTimeout(tick, 1300);
        })
        .catch(function(e){
            eiRunning=false;
            btnStart.disabled=false;
            btnStart.textContent='Avvia Invio SDI LIVE';
            btnStop.disabled=true;
            label.textContent='Errore invio SDI live: '+e.message;
        });
    }

    tick();
}

function startLiveSync(){
    if(liveRunning) return;
    if(eiRunning){
        alert('Ferma prima l\'invio SDI.');
        return;
    }
    if(!confirm('Avvio sincronizzazione LIVE verso Fatture in Cloud?')) return;

    liveRunning=true;

    var btnStart=document.getElementById('btn-live-start');
    var btnStop=document.getElementById('btn-live-stop');
    var chunkInput=document.getElementById('live-chunk');
    var chunk=Math.max(1,Math.min(25,parseInt(chunkInput.value||'10',10)));
    chunkInput.value=String(chunk);

    btnStart.disabled=true;
    btnStart.textContent='Sync LIVE in corso...';
    btnStop.disabled=false;

    var prog=document.getElementById('progress'); prog.style.display='block';
    var label=document.getElementById('prog-label');
    var fill=document.getElementById('prog-fill');
    var log=document.getElementById('prog-log');

    label.textContent='Preparazione sincronizzazione LIVE...';
    fill.style.width='0%';

    var loopCount=0;
    function tick(){
        if(!liveRunning){
            label.textContent='Sync LIVE fermata manualmente.';
            btnStart.disabled=false;
            btnStart.textContent='Avvia Sync LIVE (reale)';
            btnStop.disabled=true;
            return;
        }
        fetch('fic_bulk_push.php?action=sync_chunk',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'chunk='+encodeURIComponent(chunk)
        })
        .then(parseJsonSafe)
        .then(function(d){
            if(!d || !d.ok){
                throw new Error((d&&d.error)?d.error:'Risposta non valida');
            }
            loopCount++;
            sent += (d.created||0) + (d.linked||0);
            errs += (d.failed||0);
            document.getElementById('kpi-sent').textContent=sent;
            document.getElementById('kpi-err').textContent=errs;
            document.getElementById('kpi-ready').textContent=d.ready;
            document.getElementById('kpi-done').textContent=d.done;

            if ((d.auto_cleaned_duplicates||0) > 0) {
                log.innerHTML+='<div style="color:#92400e">⚠ Pulizia automatica: rimossi '+(d.auto_cleaned_duplicates||0)+' collegamenti FIC duplicati dal DB locale.</div>';
            }

            var done = d.tot>0 ? Math.max(0, Math.min(100, Math.round((d.done/d.tot)*100))) : 100;
            fill.style.width = done+'%';
            label.textContent='Sync LIVE: blocco '+loopCount+' • Processate '+d.processed+' • Mancanti '+d.ready+' • Verificate '+done+'%';

            (d.items||[]).forEach(function(it){
                appendResultRow(it);
                if(it.esito==='error'){
                    log.innerHTML+='<div class="err">✗ #'+it.id+' '+esc(it.numero||'')+' — '+esc(it.message||'')+'</div>';
                }else if(it.reachable){
                    log.innerHTML+='<div class="ok">✓ #'+it.id+' '+esc(it.numero||'')+' → FIC '+esc(it.fic_document_id||'-')+' (verificata)</div>';
                }else{
                    log.innerHTML+='<div style="color:#92400e">⚠ #'+it.id+' '+esc(it.numero||'')+' → FIC '+esc(it.fic_document_id||'-')+' (no verify immediata)</div>';
                }
            });
            log.scrollTop=log.scrollHeight;

            // ALERT: mostra elenco fatture saltate per cliente non collegato a FIC
            if((d.missing_clients||[]).length > 0 && ((d.processed||0)===0 || (d.ready||0)===0)){
                var msg = 'Fatture NON inviate per clienti non collegati a Fatture in Cloud:\n';
                d.missing_clients.forEach(function(m){
                    msg += '- Fattura #' + esc(m.numero||'') + ' (ID: ' + esc(m.fattura_id||'') + ') — Cliente: ' + esc(m.cliente_nome||'') + '\n';
                });
                alert(msg);
            }

            if((d.processed||0)===0 || (d.ready||0)===0){
                liveRunning=false;
                btnStart.disabled=false;
                btnStart.textContent='Avvia Sync LIVE (reale)';
                btnStop.disabled=true;
                label.textContent='Sync LIVE completata. Totale su FIC: '+d.done+' / '+d.tot+'. Errori sessione: '+errs+'.';
                fill.style.width='100%';
                return;
            }

            setTimeout(tick, 1200);
        })
        .catch(function(e){
            liveRunning=false;
            btnStart.disabled=false;
            btnStart.textContent='Avvia Sync LIVE (reale)';
            btnStop.disabled=true;
            label.textContent='Errore sync live: '+e.message;
        });
    }

    tick();
}

function cleanupFic(){
    if(!confirm('Pulizia FIC IDs: scollega fatture con FIC doc ID duplicato o sbagliato.\n\nQuesta operazione controlla ogni fattura su FIC e scollega quelle errate.\nPuò richiedere diversi minuti.\n\nProcedere?')) return;
    var btn=document.getElementById('btn-cleanup');
    btn.disabled=true; btn.textContent='Pulizia in corso...';
    var prog=document.getElementById('progress'); prog.style.display='block';
    var log=document.getElementById('prog-log'); log.innerHTML='';
    document.getElementById('prog-label').textContent='Pulizia FIC IDs in corso (potrebbe richiedere qualche minuto)...';
    document.getElementById('prog-fill').style.width='50%';

    fetch('fic_bulk_push.php?action=cleanup',{credentials:'same-origin'})
    .then(parseJsonSafe)
    .then(function(d){
        document.getElementById('prog-fill').style.width='100%';
        btn.disabled=false; btn.textContent='Pulizia FIC IDs falsi';
        if(!d.ok){
            document.getElementById('prog-label').textContent='Errore: '+(d.error||'sconosciuto');
            return;
        }
        document.getElementById('prog-label').textContent='Pulizia completata: '+d.cleaned+' fatture scollegate, '+d.new_ready+' da inviare, '+d.new_done+' ancora su FIC.';
        document.getElementById('kpi-ready').textContent=d.new_ready;
        document.getElementById('kpi-done').textContent=d.new_done;

        var html='';
        if(d.duplicates_found>0) html+='<div style="color:#92400e;font-weight:600">'+d.duplicates_found+' gruppi di FIC ID duplicati trovati e resettati.</div>';
        html+='<div>'+d.checked_singles+' fatture singole verificate su FIC.</div>';
        (d.details||[]).forEach(function(det){
            html+='<div>'+esc(det)+'</div>';
        });
        log.innerHTML=html;
        log.scrollTop=log.scrollHeight;
    })
    .catch(function(e){
        document.getElementById('prog-label').textContent='Errore: '+e.message;
        btn.disabled=false; btn.textContent='Pulizia FIC IDs falsi';
    });
}

function verifyFic(){
    var btn=document.getElementById('btn-verify');
    btn.disabled=true; btn.textContent='Verifica in corso...';
    var prog=document.getElementById('progress'); prog.style.display='block';
    document.getElementById('prog-label').textContent='Verifico documenti su FIC (può richiedere qualche minuto)...';
    document.getElementById('prog-fill').style.width='40%';

    fetch('fic_bulk_push.php?action=verify',{credentials:'same-origin'})
    .then(parseJsonSafe)
    .then(function(d){
        document.getElementById('prog-fill').style.width='100%';
        document.getElementById('prog-label').textContent='Verifica completata.';
        btn.disabled=false; btn.textContent='Verifica documenti su FIC';

        if(!d.ok||!d.rows||!d.rows.length){
            document.getElementById('prog-log').innerHTML='<div>Nessuna fattura con FIC ID trovata.</div>';
            return;
        }

        var html='<h3 style="margin:14px 0 10px">Documenti su Fatture in Cloud (ultimi 50)</h3>'
            +'<div style="overflow-x:auto"><table class="bp-table"><thead><tr>'
            +'<th>N. locale</th><th>Data locale</th><th>Cliente</th><th>Totale</th>'
            +'<th>FIC Doc ID</th><th>N. FIC</th><th>Data FIC</th><th>Stato FIC</th><th>Oggetto FIC</th><th>Raggiungibile</th>'
            +'</tr></thead><tbody>';
        d.rows.forEach(function(r){
            var fi=r.fic_info||{};
            var ok=fi.reachable;
            html+='<tr>'
                +'<td><strong>'+esc(r.numero)+'</strong></td>'
                +'<td>'+esc(r.data_emissione)+'</td>'
                +'<td>'+esc(r.cliente||'-')+'</td>'
                +'<td style="text-align:right">&euro;'+Number(r.totale).toLocaleString('it-IT',{minimumFractionDigits:2})+'</td>'
                +'<td style="font-family:monospace;font-size:11px">'+esc(r.fic_document_id)+'</td>'
                +'<td>'+(ok?esc(String(fi.fic_number||'-')):'-')+'</td>'
                +'<td>'+(ok?esc(fi.fic_date||'-'):'-')+'</td>'
                +'<td>'+(ok?'<span class="bp-badge ok">'+esc(fi.fic_status||'-')+'</span>':'<span class="bp-badge ko">Non raggiungibile</span>')+'</td>'
                +'<td>'+(ok?esc(fi.fic_subject||'-'):'-')+'</td>'
                +'<td>'+(ok?'<span class="bp-badge ok">SI</span>':'<span class="bp-badge ko">NO</span>')+'</td>'
                +'</tr>';
        });
        html+='</tbody></table></div>';

        var container=document.getElementById('verify-results');
        if(!container){
            container=document.createElement('div');
            container.id='verify-results';
            container.className='bp-card';
            document.querySelector('.wrap').appendChild(container);
        }
        container.innerHTML=html;
    })
    .catch(function(e){
        document.getElementById('prog-label').textContent='Errore: '+e.message;
        btn.disabled=false; btn.textContent='Verifica documenti su FIC';
    });
}

document.addEventListener('DOMContentLoaded', function(){
    refreshLiveStats();
    refreshEiStats();
});

// Handler globale per errori JS
window.addEventListener('error', function(event) {
    var errBox = document.getElementById('js-error-log');
    if (!errBox) {
        errBox = document.createElement('div');
        errBox.id = 'js-error-log';
        errBox.style = 'background:#fee2e2;color:#991b1b;padding:10px 16px;margin:12px 0;border-radius:8px;font-size:15px;font-family:monospace;white-space:pre-wrap;';
        var wrap = document.querySelector('.wrap') || document.body;
        wrap.insertBefore(errBox, wrap.firstChild);
    }
    errBox.textContent = 'JS ERROR: ' + event.message + '\nIn: ' + event.filename + ':' + event.lineno;
});
</script>
<?php require __DIR__ . '/../../../layouts/footer.php'; ?>
