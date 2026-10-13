<style>
#loading-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.85);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5em;
    color: #0ea5e9;
    font-weight: bold;
    display: none;
}
.loading-spinner {
    border: 6px solid #e0e7ef;
    border-top: 6px solid #0ea5e9;
    border-radius: 50%;
    width: 48px;
    height: 48px;
    animation: spin 1s linear infinite;
    margin-right: 18px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<div id="loading-overlay">
    <div class="loading-spinner"></div>
    Attendere, generazione in corso...
</div>
<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../fatture/actions/fic_alert_helpers.php';

$config = require __DIR__ . '/../../config/config.php';
$user = current_user();

// Recupera le impostazioni Stripe (usa secret.php se esiste, altrimenti config.php)
$secretConfigPath = __DIR__ . '/../../config/secret.php';
$stripeConfig = file_exists($secretConfigPath) 
    ? (require $secretConfigPath)['stripe'] ?? []
    : ($config['stripe'] ?? []);

$stripe_secret_key = $stripeConfig['secret_key'] ?? null;
$stripe_public_key = $stripeConfig['public_key'] ?? null;

// Recupera gli ultimi pagamenti sincronizzati (se esistono)
$pdo = require __DIR__ . '/../../config/db.php';

// Parametri paginazione e ricerca
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$perPage = isset($_GET['per_page']) ? (($_GET['per_page'] === 'all') ? 'all' : max(1, (int)$_GET['per_page'])) : 10;
$offset = ($page - 1) * ($perPage === 'all' ? 10 : $perPage);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$stato_filter = isset($_GET['stato']) ? trim($_GET['stato']) : '';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

$pagamenti = [];
$totalPagamenti = 0;
$errorMessage = null;

try {
    // Query base
    $conditions = [];
    $params = [];
    

    if ($search !== '') {
        $conditions[] = '(c.nome LIKE ? OR c.email LIKE ? OR p.stripe_id LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    if ($stato_filter !== '') {
        $conditions[] = 'p.stato = ?';
        $params[] = $stato_filter;
    }
    if ($from_date !== '') {
        $conditions[] = 'DATE(p.created_at) >= ?';
        $params[] = $from_date;
    }
    if ($to_date !== '') {
        $conditions[] = 'DATE(p.created_at) <= ?';
        $params[] = $to_date;
    }
    $whereClause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';
    
    // Conta totale
    $countSql = "SELECT COUNT(*) as total 
                 FROM pagamenti p 
                 LEFT JOIN clienti c ON p.cliente_id = c.id" . $whereClause;

    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalPagamenti = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Recupera pagamenti con dati cliente + stato fattura FIC

    $sql = "SELECT p.*, c.nome as cliente_nome, c.email as cliente_email,
                   c.piva as cliente_piva, c.azienda as cliente_azienda,
                   f.id as fattura_id, f.numero as fattura_numero, f.fic_document_id, f.fic_status, 
                   f.mail_status, f.mail_error, f.mail_sent_at
            FROM pagamenti p 
            LEFT JOIN clienti c ON p.cliente_id = c.id
            LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id" . 
            $whereClause . 
            " ORDER BY p.created_at DESC";
    if ($perPage !== 'all') {
        $sql .= " LIMIT ? OFFSET ?";
    }

    $stmt = $pdo->prepare($sql);
    if ($perPage !== 'all') {
        $stmt->execute(array_merge($params, [$perPage, $offset]));
    } else {
        $stmt->execute($params);
    }
    $pagamenti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Tabella potrebbe non esistere ancora
    $errorMessage = $e->getMessage();
}

$totalPages = ($perPage === 'all') ? 1 : ceil($totalPagamenti / $perPage);

// KPI
$kpiTotIncassato = 0;
$kpiSucceeded = 0;
$kpiFailed = 0;
$kpiPending = 0;
$kpiFicCreated = 0;
$kpiFicMissing = 0;
try {
    $kpiStmt = $pdo->query("SELECT stato, SUM(importo) as tot, COUNT(*) as cnt FROM pagamenti GROUP BY stato");
    while ($row = $kpiStmt->fetch(PDO::FETCH_ASSOC)) {
        $s = strtolower($row['stato'] ?? '');
        if ($s === 'succeeded') { $kpiTotIncassato = (int)$row['tot']; $kpiSucceeded = (int)$row['cnt']; }
        elseif ($s === 'failed') { $kpiFailed = (int)$row['cnt']; }
        else { $kpiPending += (int)$row['cnt']; }
    }
    // Conta fatture FIC per pagamenti succeeded
    $ficStmt = $pdo->query("SELECT 
        SUM(CASE WHEN f.fic_document_id IS NOT NULL THEN 1 ELSE 0 END) as fic_ok,
        SUM(CASE WHEN f.fic_document_id IS NULL THEN 1 ELSE 0 END) as fic_missing
        FROM pagamenti p 
        LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id 
        WHERE p.stato = 'succeeded'");
    $ficRow = $ficStmt->fetch(PDO::FETCH_ASSOC);
    $kpiFicCreated = (int)($ficRow['fic_ok'] ?? 0);
    $kpiFicMissing = (int)($ficRow['fic_missing'] ?? 0);
} catch (Throwable $e) {}

$page_title = 'Pagamenti';
require __DIR__ . '/../../layouts/header.php';
?>

<div class="wrap">
    <?php if ($errorMessage): ?>
        <div class="alert error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="card full-width">
        <div class="top-bar">
            <h2>
                <svg width="22" height="22" fill="none" stroke="#0ea5e9" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>
                Pagamenti Stripe
            </h2>
            <div class="top-bar-form">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;font-weight:600;width:auto;">
                    Fatture dal
                    <input type="date" id="invoiceFromDate" style="width:auto;padding:7px 10px;">
                </label>
                <a class="btn secondary" href="esclusioni_fatture.php">Esclusioni</a>
                <button class="btn secondary" id="preCheckBtn">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Prerequisiti
                </button>
                <button class="btn" id="syncStripeBtn">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Sincronizza Stripe
                </button>
                <button class="btn secondary" id="creaFattureLocaliBtn" style="margin-left:10px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    Crea fatture locali da Stripe
                </button>
                <button class="btn secondary" id="rinominaNumeriBtn" style="margin-left:10px;" title="Rinomina i numeri fattura non ancora sincronizzate su FIC (formato STR-ANNO-ID) per evitare duplicati">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Rinomina numeri fatture
                </button>
                    <a class="btn accent-green" href="genera_fatture_elettroniche.php?batch=1" id="generaFattureBtn" style="margin-left:10px;">
                        <script>
                        document.getElementById('generaFattureBtn')?.addEventListener('click', function(e) {
                            document.getElementById('loading-overlay').style.display = 'flex';
                        });
                        </script>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8M8 16h8M8 8h.01"/></svg>
                        Genera Fatture su FIC da Stripe
                    </a>
            </div>
        </div>
    </div>

    <div id="preCheckResults" style="display:none;margin-bottom:20px;"></div>
    <div id="creaFattureLocaliResult" style="display:none;margin-bottom:20px;"></div>

    <!-- KPI -->
    <div class="kpi-row">
        <div class="kpi-card accent-green">
            <span class="kpi-label">Incassato</span>
            <span class="kpi-value">&euro;<?php echo number_format($kpiTotIncassato / 100, 2, ',', '.'); ?></span>
            <span class="kpi-sub"><?php echo number_format($kpiSucceeded, 0, ',', '.'); ?> pagamenti riusciti</span>
        </div>
        <div class="kpi-card accent-blue">
            <span class="kpi-label">Totale Pagamenti</span>
            <span class="kpi-value"><?php echo number_format($totalPagamenti, 0, ',', '.'); ?></span>
            <span class="kpi-sub">sincronizzati da Stripe</span>
        </div>
        <div class="kpi-card accent-amber">
            <span class="kpi-label">In Attesa</span>
            <span class="kpi-value"><?php echo number_format($kpiPending, 0, ',', '.'); ?></span>
            <span class="kpi-sub">da completare</span>
        </div>
        <div class="kpi-card accent-red">
            <span class="kpi-label">Falliti</span>
            <span class="kpi-value"><?php echo number_format($kpiFailed, 0, ',', '.'); ?></span>
            <span class="kpi-sub">non andati a buon fine</span>
        </div>
        <div class="kpi-card accent-purple">
            <span class="kpi-label">Fatture FIC</span>
            <span class="kpi-value"><?php echo number_format($kpiFicCreated, 0, ',', '.'); ?> / <?php echo number_format($kpiSucceeded, 0, ',', '.'); ?></span>
            <span class="kpi-sub"><?php echo $kpiFicMissing > 0 ? $kpiFicMissing . ' da creare' : 'tutte sincronizzate'; ?></span>
        </div>
    </div>

    <!-- Stato connessione -->
    <div class="card">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <svg width="18" height="18" fill="none" stroke="#64748b" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <strong style="color:#334155;font-size:14px;">Stato Connessione</strong>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
            <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                <span class="status-dot <?php echo $stripe_secret_key ? 'active' : 'inactive'; ?>"></span>
                <div>
                    <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Stripe API</div>
                    <div style="font-size:13px;font-weight:700;color:<?php echo $stripe_secret_key ? '#059669' : '#dc2626'; ?>;">
                        <?php echo $stripe_secret_key ? 'Connesso' : 'Disconnesso'; ?>
                    </div>
                </div>
            </div>
            <div style="padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Chiave Pubblica</div>
                <div style="font-family:monospace;font-size:11px;color:#475569;margin-top:2px;">
                    <?php echo $stripe_public_key ? htmlspecialchars(substr($stripe_public_key, 0, 20)) . '...' : 'Non configurata'; ?>
                </div>
            </div>
            <div style="padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">URL Pubblico</div>
                <div style="font-family:monospace;font-size:11px;color:#475569;margin-top:2px;">
                    <?php echo htmlspecialchars($config['stripe']['public_url'] ?? 'Non configurato'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Ricerca + Tabella -->

    <div class="card full-width">
        <form method="GET" class="top-bar-form" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <input class="input-search" type="text" name="search" placeholder="Cerca per cliente, email o ID Stripe..." value="<?php echo htmlspecialchars($search); ?>" style="min-width:220px;" title="Cerca per nome, email o ID Stripe">
            <select name="stato" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#334155;background:#f8fafc;min-width:140px;" title="Filtra per stato pagamento">
                <option value="">Tutti gli stati</option>
                <option value="succeeded" <?php echo $stato_filter === 'succeeded' ? 'selected' : ''; ?>>&#9989; Succeeded</option>
                <option value="pending" <?php echo $stato_filter === 'pending' ? 'selected' : ''; ?>>&#9203; Pending</option>
                <option value="failed" <?php echo $stato_filter === 'failed' ? 'selected' : ''; ?>>&#10060; Failed</option>
            </select>
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;" title="Filtra da data">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;" title="Filtra a data">
            <select name="per_page" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:90px;" title="Paginazione">
                <option value="10" <?php if($perPage==10) echo 'selected'; ?>>10</option>
                <option value="20" <?php if($perPage==20) echo 'selected'; ?>>20</option>
                <option value="50" <?php if($perPage==50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if($perPage==100) echo 'selected'; ?>>100</option>
                <option value="all" <?php if($perPage==='all') echo 'selected'; ?>>Tutti</option>
            </select>
            <button class="btn" type="submit">Filtra</button>
            <?php if ($search !== '' || $stato_filter !== '' || !empty($_GET['from_date']) || !empty($_GET['to_date']) || (isset($_GET['per_page']) && $_GET['per_page']!=='10')): ?>
                <a class="btn secondary" href="?">Azzera</a>
            <?php endif; ?>
        </form>

        <!-- Azioni di massa -->
        <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <select id="batchActionSelect" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">Azioni di massa...</option>
                <option value="retry">Riprova selezionati</option>
                <option value="export">Esporta selezionati</option>
            </select>
            <button class="btn secondary" id="batchActionBtn" disabled>Esegui</button>
            <span class="muted" style="font-size:12px;">Seleziona pagamenti tramite checkbox</span>
        </div>

        <div class="list-meta">

            <div>
                <strong><?php echo number_format($totalPagamenti, 0, ',', '.'); ?></strong> pagamenti
                <?php if ($totalPagamenti > 0): ?>
                    &middot; Pag. <?php echo $page; ?>/<?php echo $totalPages; ?>
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    &middot; filtro: <em><?php echo htmlspecialchars($search); ?></em>
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pager">
                    <?php $prevD = $page <= 1; $nextD = $page >= $totalPages; ?>
                    <a class="btn btn-sm <?php echo $prevD ? 'btn-disabled' : 'secondary'; ?>" href="?page=<?php echo max(1,$page-1); ?>&search=<?php echo urlencode($search); ?>&stato=<?php echo urlencode($stato_filter); ?>" <?php echo $prevD ? 'aria-disabled="true"' : ''; ?>>← Prec.</a>
                    <a class="btn btn-sm <?php echo $nextD ? 'btn-disabled' : 'secondary'; ?>" href="?page=<?php echo min($totalPages,$page+1); ?>&search=<?php echo urlencode($search); ?>&stato=<?php echo urlencode($stato_filter); ?>" <?php echo $nextD ? 'aria-disabled="true"' : ''; ?>>Succ. →</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
        <?php if (!empty($pagamenti)): ?>
            <table class="clienti-table">
                <thead>
                    <tr>
                        <th>ID Stripe</th>
                        <th>Cliente</th>
                        <th style="text-align:right;">Importo</th>
                        <th>Valuta</th>
                        <th>Stato</th>
                        <th>Metodo</th>
                        <th style="text-align:center;">Fattura FIC</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagamenti as $pag):
                        $stato = strtolower($pag['stato'] ?? 'pending');
                        $statusClass = $stato === 'succeeded' ? 'active' : ($stato === 'failed' ? 'inactive' : 'pending');
                    ?>
                        <tr>
                            <td><code style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?php echo htmlspecialchars(substr($pag['stripe_id'] ?? '', 0, 24)); ?></code></td>
                            <td>
                                <?php if (!empty($pag['cliente_nome'])): ?>
                                    <span class="cliente-name"><?php echo htmlspecialchars($pag['cliente_nome']); ?></span>
                                    <span class="cliente-company"><?php echo htmlspecialchars($pag['cliente_email'] ?? ''); ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">Nessun cliente</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;" class="amount-cell">&euro;<?php echo number_format(($pag['importo'] ?? 0) / 100, 2, ',', '.'); ?></td>
                            <td><span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;"><?php echo htmlspecialchars(strtoupper($pag['valuta'] ?? 'EUR')); ?></span></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;
                                    <?php if ($stato === 'succeeded'): ?>background:#dcfce7;color:#166534;
                                    <?php elseif ($stato === 'failed'): ?>background:#fee2e2;color:#991b1b;
                                    <?php else: ?>background:#fef3c7;color:#92400e;<?php endif; ?>">
                                    <span class="status-dot <?php echo $statusClass; ?>"></span>
                                    <?php echo htmlspecialchars(ucfirst($stato)); ?>
                                </span>
                            </td>
                            <td style="font-size:12px;color:#475569;"><?php echo htmlspecialchars($pag['metodo'] ?? '—'); ?></td>
                            <td style="text-align:center;">
                                <?php
                                $ficDocId = $pag['fic_document_id'] ?? null;
                                $mailStatus = $pag['mail_status'] ?? null;
                                $fatturaId = $pag['fattura_id'] ?? null;
                                $hasFattura = $fatturaId !== null;
                                $isSucceeded = $stato === 'succeeded';
                                ?>
                                <?php if ($ficDocId): ?>
                                    <span title="FIC #<?php echo htmlspecialchars($ficDocId); ?> — <?php echo htmlspecialchars($pag['fattura_numero'] ?? ''); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;cursor:help;">
                                        <span class="status-dot active"></span>FIC OK
                                    </span>
                                <?php elseif ($mailStatus === 'sent'): ?>
                                    <span title="Email inviata" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af;">
                                        <span class="status-dot active"></span>Inviata
                                    </span>
                                <?php elseif ($mailStatus === 'pending'): ?>
                                    <?php $reason = tdg_sdi_normalize_reason_text((string)($pag['mail_error'] ?? '')); ?>
                                    <span title="In attesa: <?php echo htmlspecialchars($reason); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;cursor:help;">
                                        <span class="status-dot pending"></span>Pending
                                    </span>
                                <?php elseif ($mailStatus === 'error'): ?>
                                    <span title="Errore: <?php echo htmlspecialchars(tdg_sdi_normalize_reason_text((string)($pag['mail_error'] ?? ''))); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b;cursor:help;">
                                        <span class="status-dot inactive"></span>Errore
                                    </span>
                                <?php elseif ($isSucceeded && $hasFattura): ?>
                                    <span title="Fattura locale creata, invio non ancora tentato" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;cursor:help;">
                                        <span class="status-dot pending"></span>Locale
                                    </span>
                                <?php elseif ($isSucceeded && !$hasFattura): ?>
                                    <span title="Fattura non ancora creata" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b;cursor:help;">
                                        <span class="status-dot inactive"></span>Manca
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#475569;white-space:nowrap;">
                                <?php echo date('d/m/Y H:i', strtotime($pag['created_at'] ?? 'now')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align:center;padding:50px 20px;color:#94a3b8;">
                <svg width="48" height="48" fill="none" stroke="#cbd5e1" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 12px"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>
                <p style="margin:0;font-size:15px;font-weight:600;color:#64748b;">Nessun pagamento trovato</p>
                <p style="margin:6px 0 0;font-size:12px;">Clicca "Sincronizza Stripe" per importare i pagamenti.</p>
            </div>
        <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="list-footer">
                <div class="muted">
                    <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalPagamenti); ?> di <?php echo $totalPagamenti; ?>
                </div>
                <div class="pager">
                    <?php $prevD = $page <= 1; $nextD = $page >= $totalPages; ?>
                    <a class="btn btn-sm <?php echo $prevD ? 'btn-disabled' : 'secondary'; ?>" href="?page=<?php echo max(1,$page-1); ?>&search=<?php echo urlencode($search); ?>&stato=<?php echo urlencode($stato_filter); ?>">← Prec.</a>
                    <span class="page-count">Pag. <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong></span>
                    <a class="btn btn-sm <?php echo $nextD ? 'btn-disabled' : 'secondary'; ?>" href="?page=<?php echo min($totalPages,$page+1); ?>&search=<?php echo urlencode($search); ?>&stato=<?php echo urlencode($stato_filter); ?>">Succ. →</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('creaFattureLocaliBtn');
    var resultBox = document.getElementById('creaFattureLocaliResult');
    if (!btn || !resultBox) return;

    btn.addEventListener('click', function() {
        if (!confirm('Creare le fatture locali per tutti i pagamenti Stripe succeeded non ancora collegati?\nQuesto NON invia ancora su FIC.')) return;

        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Creazione in corso...';
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';

        fetch('../ordini/sincronizza_ordini_stripe.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = orig;
            if (data.ok) {
                var msg = 'Fatture create: <b>' + (data.created || 0) + '</b>';
                if ((data.converted_legacy || 0) > 0) msg += ', convertite da ordine: <b>' + data.converted_legacy + '</b>';
                if ((data.skipped || 0) > 0) msg += ', saltate: <b>' + data.skipped + '</b>';
                if (data.message) msg = data.message + ' &mdash; ' + msg;
                resultBox.className = 'alert success';
                resultBox.innerHTML = '&#9989; ' + msg + '. Ora clicca <b>Genera Fatture su FIC da Stripe</b> per sincronizzarle.';
            } else {
                resultBox.className = 'alert error';
                resultBox.innerHTML = '&#9888;&#65039; Errore: ' + (data.error || 'risposta non valida');
            }
            resultBox.style.display = 'block';
            if (data.ok && (data.created || 0) > 0) {
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('rinominaNumeriBtn');
    var resultBox = document.getElementById('creaFattureLocaliResult');
    if (!btn || !resultBox) return;

    btn.addEventListener('click', function() {
        if (!confirm('Rinominare i numeri di tutte le fatture non ancora sincronizzate su FIC?\n\nI numeri verranno impostati nel formato STR-ANNO-ID (es. STR-2026-42) per garantire unicità ed evitare duplicati.\n\nQuesta operazione NON modifica le fatture già su FIC.')) return;

        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Rinomina in corso...';
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';

        fetch('rinomina_numeri_fatture.php', {
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
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
