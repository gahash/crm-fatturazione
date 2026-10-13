<?php
/**
 * Ripristino fatture cancellate da Fatture in Cloud.
 *
 * Fasi:
 *  1. VERIFICA   – controlla quali fatture locali hanno fic_document_id ma il documento
 *                  non è più raggiungibile su FIC (cancellato).
 *  2. RIPRISTINO – cancella i riferimenti FIC locali e ri-crea i documenti su FIC.
 *
 * Uso:
 *   GET  ?action=check          → mostra lo stato di tutte le fatture con fic_document_id
 *   POST ?action=restore_all    → ripristina tutte le fatture cancellate su FIC
 *   POST ?action=restore&id=123 → ripristina una singola fattura
 */

ini_set('display_errors', '1');
ini_set('max_execution_time', '300');
error_reporting(E_ALL);

require __DIR__ . '/../../../auth.php';
require_login();

$user = current_user();
if (!has_admin_access()) {
    http_response_code(403);
    die('Accesso non autorizzato.');
}

$pdo    = require __DIR__ . '/../../../config/db.php';
$config = require __DIR__ . '/../../../config/config.php';

require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$cfg       = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}
if ($companyId === '') {
    die('Errore: company_id FIC non configurato.');
}

$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');
$action  = $_REQUEST['action'] ?? 'check';

// ─── Helpers ────────────────────────────────────────────────────────────────

function check_fic_reachable(string $companyId, string $docId): array
{
    try {
        $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
        $status = (int)($resp['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $doc = $resp['json']['data'] ?? [];
            return [
                'reachable' => true,
                'status'    => (string)($doc['status'] ?? 'unknown'),
                'subject'   => (string)($doc['visible_subject'] ?? ''),
            ];
        }
        return ['reachable' => false, 'status' => 'HTTP ' . $status, 'subject' => ''];
    } catch (Throwable $e) {
        return ['reachable' => false, 'status' => $e->getMessage(), 'subject' => ''];
    }
}

function restore_single(PDO $pdo, int $fatturaId, string $companyId, array $cfg): array
{
    // Carica fattura + cliente
    $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                           FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
    $stmt->execute([$fatturaId]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        return ['ok' => false, 'error' => 'Fattura ID ' . $fatturaId . ' non trovata nel DB locale.'];
    }

    // Carica righe
    $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC");
    $righeStmt->execute([$fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pulisci riferimenti FIC locali (il doc non esiste più su FIC)
    $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_synced_at = NULL WHERE id = ?")
        ->execute([$fatturaId]);

    // Aggiorna array locale per riflettere il reset
    $fattura['fic_document_id'] = null;
    $fattura['fic_status']      = null;
    $fattura['fic_synced_at']   = null;

    try {
        [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);

        if (!$docId) {
            fatture_log($pdo, $fatturaId, 'fic_restore', 'error', 'Ripristino FIC: documento non creato.');
            return ['ok' => false, 'error' => 'Documento FIC non creato.'];
        }

        // Pubblica
        fic_publish_document_if_draft($companyId, (string)$docId);

        fatture_log($pdo, $fatturaId, 'fic_restore', 'ok', 'Fattura ripristinata su FIC.', [
            'fic_document_id' => $docId,
            'fic_status'      => $status,
            'numero'          => $fattura['numero'] ?? null,
        ]);

        return [
            'ok'     => true,
            'doc_id' => $docId,
            'status' => $status,
            'numero' => $fattura['numero'] ?? '',
        ];
    } catch (Throwable $e) {
        fatture_log($pdo, $fatturaId, 'fic_restore', 'error', 'Errore ripristino FIC: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ─── AJAX: restore singolo ──────────────────────────────────────────────────
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'ID mancante.']);
        exit;
    }
    echo json_encode(restore_single($pdo, $id, $companyId, $cfg));
    exit;
}

// ─── AJAX: restore_all ──────────────────────────────────────────────────────
if ($action === 'restore_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Trova tutte le fatture con fic_document_id che non sono raggiungibili
    $rows = $pdo->query("SELECT id, fic_document_id, numero FROM fatture WHERE fic_document_id IS NOT NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $results = ['restored' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
    foreach ($rows as $row) {
        $check = check_fic_reachable($companyId, (string)$row['fic_document_id']);
        if ($check['reachable']) {
            $results['skipped']++;
            continue;
        }
        // Non raggiungibile → ripristina
        $res = restore_single($pdo, (int)$row['id'], $companyId, $cfg);
        if ($res['ok']) {
            $results['restored']++;
        } else {
            $results['errors']++;
        }
        $res['fattura_id'] = (int)$row['id'];
        $res['numero']     = $row['numero'] ?? '';
        $results['details'][] = $res;

        // Rate limiting per non saturare l'API FIC
        usleep(400000); // 400ms
    }
    echo json_encode($results);
    exit;
}

// ─── AJAX: check ────────────────────────────────────────────────────────────
if ($action === 'check_ajax') {
    header('Content-Type: application/json');

    $rows = $pdo->query("SELECT f.id, f.numero, f.data_emissione, f.stato, f.totale, f.fic_document_id, f.fic_status,
                                c.nome AS cliente
                         FROM fatture f
                         LEFT JOIN clienti c ON c.id = f.cliente_id
                         WHERE f.fic_document_id IS NOT NULL
                         ORDER BY f.data_emissione DESC")->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $check = check_fic_reachable($companyId, (string)$row['fic_document_id']);
        $results[] = [
            'id'              => (int)$row['id'],
            'numero'          => $row['numero'],
            'data_emissione'  => $row['data_emissione'],
            'stato'           => $row['stato'],
            'totale'          => (float)$row['totale'],
            'cliente'         => $row['cliente'] ?? '-',
            'fic_document_id' => $row['fic_document_id'],
            'fic_status'      => $row['fic_status'],
            'reachable'       => $check['reachable'],
            'fic_remote_status' => $check['status'],
        ];
        usleep(300000); // 300ms rate limit
    }
    echo json_encode(['ok' => true, 'rows' => $results]);
    exit;
}

// ─── HTML UI ────────────────────────────────────────────────────────────────

// Conteggio rapido senza chiamate API
$countAll = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NOT NULL")->fetchColumn();
$countNoFic = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE fic_document_id IS NULL AND document_type = 'invoice'")->fetchColumn();

require __DIR__ . '/../../../layouts/header.php';
?>
<style>
.fic-r-card { border:1px solid #e2e8f0; border-radius:12px; padding:18px; background:#fff; margin-bottom:14px; }
.fic-r-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:16px; }
.fic-r-kpi .k { border:1px solid #e2e8f0; border-radius:10px; padding:12px; background:#f8fafc; }
.fic-r-kpi .k .label { font-size:11px; color:#64748b; text-transform:uppercase; }
.fic-r-kpi .k .val { font-size:22px; font-weight:700; color:#0f172a; margin-top:2px; }
.fic-r-table { width:100%; border-collapse:collapse; font-size:13px; }
.fic-r-table th,.fic-r-table td { border-bottom:1px solid #e5e7eb; padding:8px 10px; text-align:left; }
.fic-r-table th { background:#f8fafc; color:#334155; font-weight:700; }
.fic-r-table tbody tr:hover { background:#f0f9ff; }
.fic-r-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; }
.fic-r-badge.ok { background:#dcfce7; color:#166534; }
.fic-r-badge.ko { background:#fee2e2; color:#991b1b; }
.fic-r-badge.warn { background:#fef3c7; color:#92400e; }
.fic-r-btn { padding:6px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all .12s; }
.fic-r-btn.primary { background:#3b82f6; color:#fff; }
.fic-r-btn.primary:hover { background:#2563eb; }
.fic-r-btn.danger { background:#ef4444; color:#fff; }
.fic-r-btn.danger:hover { background:#dc2626; }
.fic-r-btn:disabled { opacity:.5; cursor:not-allowed; }
.fic-r-progress { margin:12px 0; padding:12px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; display:none; }
.fic-r-progress .bar { height:6px; background:#e2e8f0; border-radius:99px; overflow:hidden; margin-top:8px; }
.fic-r-progress .bar .fill { height:100%; background:#3b82f6; transition:width .3s; width:0; }
.fic-r-log { margin-top:10px; max-height:200px; overflow-y:auto; font-size:12px; font-family:monospace; padding:8px; background:#f1f5f9; border-radius:6px; }
.fic-r-log div { padding:2px 0; border-bottom:1px solid #e2e8f0; }
.fic-r-log .ok { color:#166534; }
.fic-r-log .err { color:#991b1b; }
</style>
<div class="wrap">
    <div class="fic-r-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h2 style="margin:0;">Ripristino Fatture in Cloud</h2>
                <p class="muted" style="margin:4px 0 0 0;">Verifica e ripristina le fatture cancellate da FIC.</p>
            </div>
            <a class="btn secondary" href="<?php echo htmlspecialchars($baseUrl); ?>/modules/fatture/index.php">&larr; Fatture</a>
        </div>
    </div>

    <div class="fic-r-kpi">
        <div class="k">
            <div class="label">Fatture con FIC ID</div>
            <div class="val" id="kpi-with-fic"><?php echo $countAll; ?></div>
        </div>
        <div class="k">
            <div class="label">Senza FIC ID</div>
            <div class="val" style="color:#92400e;" id="kpi-no-fic"><?php echo $countNoFic; ?></div>
        </div>
        <div class="k">
            <div class="label">Raggiungibili su FIC</div>
            <div class="val" style="color:#166534;" id="kpi-ok">—</div>
        </div>
        <div class="k">
            <div class="label">Cancellate (da ripristinare)</div>
            <div class="val" style="color:#991b1b;" id="kpi-deleted">—</div>
        </div>
    </div>

    <div class="fic-r-card">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="fic-r-btn primary" id="btn-check" onclick="startCheck()">1. Verifica stato FIC</button>
            <button class="fic-r-btn danger" id="btn-restore-all" onclick="startRestoreAll()" disabled>2. Ripristina tutte le cancellate</button>
        </div>
        <p class="muted" style="margin:8px 0 0 0;">Prima esegui la verifica per capire quali fatture sono state cancellate. Poi ripristina.</p>

        <div class="fic-r-progress" id="progress">
            <strong id="progress-label">Verifica in corso...</strong>
            <div class="bar"><div class="fill" id="progress-fill"></div></div>
            <div class="fic-r-log" id="progress-log"></div>
        </div>
    </div>

    <div class="fic-r-card">
        <h3 style="margin:0 0 10px 0;">Risultati verifica</h3>
        <div style="overflow-x:auto;">
            <table class="fic-r-table">
                <thead>
                    <tr>
                        <th>N. Fattura</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th style="text-align:right;">Totale</th>
                        <th>Stato locale</th>
                        <th>FIC Doc ID</th>
                        <th>Stato FIC</th>
                        <th>Azione</th>
                    </tr>
                </thead>
                <tbody id="results-body">
                    <tr><td colspan="8" class="muted" style="text-align:center;padding:20px;">Clicca "Verifica stato FIC" per iniziare.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
var checkResults = [];

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function startCheck() {
    var btn = document.getElementById('btn-check');
    btn.disabled = true;
    btn.textContent = 'Verifica in corso...';

    var prog = document.getElementById('progress');
    prog.style.display = 'block';
    document.getElementById('progress-label').textContent = 'Recupero stato da FIC (può richiedere qualche minuto)...';
    document.getElementById('progress-fill').style.width = '30%';
    document.getElementById('progress-log').innerHTML = '';

    fetch('fic_restore.php?action=check_ajax', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) throw new Error(d.error || 'Errore');
            checkResults = d.rows || [];
            renderResults(checkResults);
            document.getElementById('progress-fill').style.width = '100%';
            document.getElementById('progress-label').textContent = 'Verifica completata.';

            var deleted = checkResults.filter(function(r) { return !r.reachable; }).length;
            var ok = checkResults.filter(function(r) { return r.reachable; }).length;
            document.getElementById('kpi-ok').textContent = ok;
            document.getElementById('kpi-deleted').textContent = deleted;

            if (deleted > 0) {
                document.getElementById('btn-restore-all').disabled = false;
            }
            btn.disabled = false;
            btn.textContent = '1. Verifica stato FIC';
        })
        .catch(function(e) {
            document.getElementById('progress-label').textContent = 'Errore: ' + e.message;
            btn.disabled = false;
            btn.textContent = '1. Verifica stato FIC';
        });
}

function renderResults(rows) {
    var body = document.getElementById('results-body');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" class="muted" style="text-align:center;">Nessuna fattura con FIC ID trovata.</td></tr>';
        return;
    }
    var html = '';
    rows.forEach(function(r) {
        var statusCls = r.reachable ? 'ok' : 'ko';
        var statusTxt = r.reachable ? 'Presente su FIC' : 'CANCELLATA';
        html += '<tr id="row-' + r.id + '">'
            + '<td><strong>' + escH(r.numero) + '</strong></td>'
            + '<td>' + escH(r.data_emissione) + '</td>'
            + '<td>' + escH(r.cliente) + '</td>'
            + '<td style="text-align:right;font-weight:600;">&euro;' + Number(r.totale).toLocaleString('it-IT', {minimumFractionDigits:2}) + '</td>'
            + '<td><span class="fic-r-badge ' + (r.stato === 'pagata' ? 'ok' : 'warn') + '">' + escH(r.stato) + '</span></td>'
            + '<td style="font-family:monospace;font-size:11px;">' + escH(r.fic_document_id) + '</td>'
            + '<td><span class="fic-r-badge ' + statusCls + '">' + statusTxt + '</span></td>'
            + '<td>'
            + (r.reachable
                ? '<span style="color:#166534;">✓ OK</span>'
                : '<button class="fic-r-btn primary" style="font-size:12px;padding:4px 10px;" onclick="restoreSingle(' + r.id + ',this)">Ripristina</button>')
            + '</td>'
            + '</tr>';
    });
    body.innerHTML = html;
}

function restoreSingle(id, btn) {
    btn.disabled = true;
    btn.textContent = '...';
    var fd = new FormData();
    fd.append('id', id);

    fetch('fic_restore.php?action=restore', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var cell = btn.parentElement;
            if (d.ok) {
                cell.innerHTML = '<span class="fic-r-badge ok">Ripristinata (FIC ' + escH(String(d.doc_id)) + ')</span>';
                // Update badge
                var row = document.getElementById('row-' + id);
                if (row) {
                    var cells = row.querySelectorAll('td');
                    if (cells[6]) cells[6].innerHTML = '<span class="fic-r-badge ok">Ripristinata</span>';
                }
            } else {
                cell.innerHTML = '<span class="fic-r-badge ko">Errore: ' + escH(d.error || 'sconosciuto') + '</span>';
            }
        })
        .catch(function(e) {
            btn.textContent = 'Errore';
            btn.disabled = false;
        });
}

function startRestoreAll() {
    var deleted = checkResults.filter(function(r) { return !r.reachable; });
    if (!deleted.length) { alert('Nessuna fattura da ripristinare.'); return; }
    if (!confirm('Ripristinare ' + deleted.length + ' fatture su Fatture in Cloud?')) return;

    var btn = document.getElementById('btn-restore-all');
    btn.disabled = true;
    btn.textContent = 'Ripristino in corso...';

    var prog = document.getElementById('progress');
    prog.style.display = 'block';
    document.getElementById('progress-label').textContent = 'Ripristino di ' + deleted.length + ' fatture...';
    document.getElementById('progress-fill').style.width = '10%';

    fetch('fic_restore.php?action=restore_all', { method: 'POST', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            document.getElementById('progress-fill').style.width = '100%';
            var msg = 'Completato: ' + (d.restored || 0) + ' ripristinate, '
                + (d.skipped || 0) + ' già presenti, '
                + (d.errors || 0) + ' errori.';
            document.getElementById('progress-label').textContent = msg;

            var logEl = document.getElementById('progress-log');
            if (d.details && d.details.length) {
                var logHtml = '';
                d.details.forEach(function(det) {
                    var cls = det.ok ? 'ok' : 'err';
                    logHtml += '<div class="' + cls + '">'
                        + 'Fattura #' + escH(det.numero || String(det.fattura_id))
                        + ': ' + (det.ok ? 'OK (FIC ' + det.doc_id + ')' : 'ERRORE - ' + escH(det.error || ''))
                        + '</div>';
                });
                logEl.innerHTML = logHtml;
            }

            btn.textContent = '2. Ripristina tutte le cancellate';
            btn.disabled = false;

            // Refresh check
            startCheck();
        })
        .catch(function(e) {
            document.getElementById('progress-label').textContent = 'Errore: ' + e.message;
            btn.disabled = false;
            btn.textContent = '2. Ripristina tutte le cancellate';
        });
}
</script>
<?php require __DIR__ . '/../../../layouts/footer.php'; ?>
