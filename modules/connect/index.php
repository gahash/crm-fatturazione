<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/connect_service.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

function connect_eur_from_cents(int $amountCents): string
{
    return number_format($amountCents / 100, 2, ',', '.');
}

function connect_safe_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    return date('d/m/Y H:i', $ts);
}

$secretPath = __DIR__ . '/../../config/secret.php';
$secretKey = '';
if (file_exists($secretPath)) {
    $secret = require $secretPath;
    $secretKey = (string)($secret['stripe']['secret_key'] ?? '');
}
if ($secretKey === '') {
    $secretKey = (string)($config['stripe']['secret_key'] ?? '');
}

$pageError = null;
$syncMessage = null;
$syncCount = 0;
$canUseStripe = $secretKey !== '' && strpos($secretKey, 'sk_') === 0;

try {
    tdg_stripe_connect_ensure_schema($pdo);
} catch (Throwable $e) {
    $pageError = 'Impossibile inizializzare la tabella Stripe Connect: ' . $e->getMessage();
}

if ($pageError === null && $canUseStripe) {
    try {
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            throw new RuntimeException('Stripe SDK non trovato. Carica la cartella vendor/.');
        }
        require_once $autoloadPath;
        \Stripe\Stripe::setApiKey($secretKey);

        $row = $pdo->query("SELECT COUNT(*) AS c, MAX(last_synced_at) AS last_sync FROM stripe_connected_accounts")->fetch(PDO::FETCH_ASSOC);
        $countExisting = (int)($row['c'] ?? 0);
        $lastSync = (string)($row['last_sync'] ?? '');
        $forceSync = isset($_GET['sync']) && (string)$_GET['sync'] === '1';
        $isStale = true;
        if ($lastSync !== '') {
            $lastSyncTs = strtotime($lastSync);
            $isStale = $lastSyncTs === false || (time() - $lastSyncTs) > 900;
        }

        if ($forceSync || $countExisting === 0 || $isStale) {
            $sync = tdg_stripe_connect_sync_accounts($pdo, 50);
            $syncCount = count($sync['accounts'] ?? []);
            $syncMessage = 'Sincronizzazione completata: ' . number_format($syncCount, 0, ',', '.') . ' account.';
        }
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
} elseif (!$canUseStripe && $pageError === null) {
    $pageError = 'Chiave segreta Stripe non configurata.';
}

$accounts = [];
try {
    $accounts = tdg_stripe_connect_list_local($pdo, 120);
} catch (Throwable $e) {
    if ($pageError === null) {
        $pageError = $e->getMessage();
    }
}

$overview = [
    'total' => 0,
    'active' => 0,
    'incomplete' => 0,
    'balance_available' => 0,
    'balance_pending' => 0,
];
foreach ($accounts as $acc) {
    $overview['total']++;
    $isActive = (int)($acc['onboarding_completed'] ?? 0) === 1;
    if ($isActive) {
        $overview['active']++;
    } else {
        $overview['incomplete']++;
    }
    $overview['balance_available'] += (int)($acc['balance_available'] ?? 0);
    $overview['balance_pending'] += (int)($acc['balance_pending'] ?? 0);
}

// Auto-select Partner by default
$partnerAccount = null;
foreach ($accounts as $acc) {
    $name = strtolower((string)($acc['business_name'] ?? ''));
    $email = strtolower((string)($acc['email'] ?? ''));
    if (strpos($name, 'partner') !== false || strpos($email, 'partner') !== false) {
        $partnerAccount = $acc;
        break;
    }
}

$selectedAccountId = trim((string)($_GET['account'] ?? ''));
if ($selectedAccountId === '' && $partnerAccount) {
    $selectedAccountId = (string)$partnerAccount['stripe_account_id'];
}

$selectedLocal = null;
if ($selectedAccountId !== '') {
    $selectedLocal = tdg_stripe_connect_get_local_account($pdo, $selectedAccountId);
}

// Date filters per Partner
$filterMese = isset($_GET['mese']) && $_GET['mese'] !== '' ? (int)$_GET['mese'] : 0;
$filterAnno = isset($_GET['anno']) && $_GET['anno'] !== '' ? (int)$_GET['anno'] : 0;
$isAllMonths = $filterMese === 0;
$isAllYears = $filterAnno === 0;

// Partner transaction metrics
$partnerMetrics = [
    'total_transactions' => 0,
    'total_volume_cents' => 0,
    'avg_transaction_cents' => 0,
    'commission_cents' => 0,
    'net_cents' => 0,
];

if ($selectedLocal && $selectedAccountId === (string)($partnerAccount['stripe_account_id'] ?? '')) {
    try {
        $whereClause = "1=1";
        $params = [];
        if (!$isAllYears) {
            $whereClause .= " AND YEAR(created_at) = ?";
            $params[] = $filterAnno;
        }
        if (!$isAllMonths && !$isAllYears) {
            $whereClause .= " AND MONTH(created_at) = ?";
            $params[] = $filterMese;
        }
        
        $txStmt = $pdo->prepare("SELECT 
            COUNT(*) AS tx_count,
            COALESCE(SUM(importo), 0) AS total_volume,
            COALESCE(AVG(importo), 0) AS avg_volume
            FROM pagamenti
            WHERE stripe_id IS NOT NULL AND stato = 'succeeded' AND $whereClause"
        );
        $txStmt->execute($params);
        $txRow = $txStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        $partnerMetrics['total_transactions'] = (int)($txRow['tx_count'] ?? 0);
        $partnerMetrics['total_volume_cents'] = (int)($txRow['total_volume'] ?? 0);
        $partnerMetrics['avg_transaction_cents'] = (int)($txRow['avg_volume'] ?? 0);
        // Estimate Stripe fee (2.9% + 0.30 EUR per transaction)
        $partnerMetrics['commission_cents'] = (int)($partnerMetrics['total_volume_cents'] * 0.029) + ($partnerMetrics['total_transactions'] * 30);
        $partnerMetrics['net_cents'] = $partnerMetrics['total_volume_cents'] - $partnerMetrics['commission_cents'];
    } catch (Throwable $e) {
        // Silently fail
    }
}

$detailError = null;
$liveDetail = null;
if ($selectedAccountId !== '' && $pageError === null && $canUseStripe) {
    try {
        $liveDetail = tdg_stripe_connect_get_account_live($selectedAccountId);
    } catch (Throwable $e) {
        $detailError = $e->getMessage();
    }
}

$selectedCapabilities = [];
$selectedRequirements = [];
if ($selectedLocal) {
    $selectedCapabilities = json_decode((string)($selectedLocal['capabilities_json'] ?? '{}'), true);
    if (!is_array($selectedCapabilities)) {
        $selectedCapabilities = [];
    }

    $selectedRequirements = json_decode((string)($selectedLocal['requirements_json'] ?? '{}'), true);
    if (!is_array($selectedRequirements)) {
        $selectedRequirements = [];
    }
}

$liveAvailable = 0;
$livePending = 0;
if ($liveDetail && !empty($liveDetail['balance'])) {
    $currency = strtolower((string)($selectedLocal['default_currency'] ?? 'eur'));
    $liveAvailable = tdg_stripe_connect_sum_balance_for_currency($liveDetail['balance']->available ?? [], $currency);
    $livePending = tdg_stripe_connect_sum_balance_for_currency($liveDetail['balance']->pending ?? [], $currency);
    if ($liveAvailable === 0) {
        $liveAvailable = tdg_stripe_connect_sum_balance_all($liveDetail['balance']->available ?? []);
    }
    if ($livePending === 0) {
        $livePending = tdg_stripe_connect_sum_balance_all($liveDetail['balance']->pending ?? []);
    }
}

require __DIR__ . '/../../layouts/header.php';
?>
<style>
.connect-layout { display: grid; grid-template-columns: minmax(260px, 340px) 1fr; gap: 16px; }
.connect-section { margin-top: 18px; }
.connect-overview { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 12px; }
.connect-pill { display: inline-block; border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 600; }
.connect-pill.ok { background: #dcfce7; color: #166534; }
.connect-pill.warn { background: #fff7ed; color: #9a3412; }
.connect-pill.bad { background: #ffe4e6; color: #9f1239; }
.connect-metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; }
.connect-metric .label { color: #475569; font-size: 12px; margin-bottom: 6px; }
.connect-metric .value { font-size: 24px; font-weight: 700; color: #0f172a; line-height: 1.1; }
.connect-list { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px; }
.connect-account-link { display: block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; text-decoration: none; color: inherit; margin-bottom: 8px; }
.connect-account-link:hover { border-color: #c7d2fe; background: #f8faff; }
.connect-account-link.active { border-color: #6366f1; background: #eef2ff; }
.connect-account-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.connect-account-name { font-weight: 700; color: #1f2937; }
.connect-account-id { font-family: monospace; font-size: 12px; color: #6b7280; }
.connect-account-meta { margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap; font-size: 12px; color: #4b5563; }
.connect-detail-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; }
.connect-detail-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.connect-balance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 14px; }
.connect-balance-item { background: #f8fafc; border: 1px solid #dbeafe; border-radius: 10px; padding: 12px; }
.connect-balance-item .b-label { color: #475569; font-size: 12px; margin-bottom: 4px; }
.connect-balance-item .b-value { color: #0f172a; font-size: 24px; font-weight: 700; }
.connect-cols { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(240px, 1fr); gap: 14px; margin-top: 14px; }
.connect-box { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; background: #ffffff; }
.connect-box h4 { margin: 0 0 10px 0; color: #1f2937; }
.connect-kv { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 10px; font-size: 13px; }
.connect-kv .k { color: #6b7280; }
.connect-kv .v { color: #111827; font-weight: 600; word-break: break-word; }
.connect-table-wrap { overflow-x: auto; margin-top: 10px; }
.connect-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.connect-table th, .connect-table td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; white-space: nowrap; }
.connect-table th { color: #374151; background: #f8fafc; font-weight: 700; }
@media (max-width: 1024px) { .connect-layout { grid-template-columns: 1fr; } }
@media (max-width: 760px) { .connect-cols { grid-template-columns: 1fr; } }
</style>

<div class="wrap">
    <div class="card" style="border-top: 6px solid #6366f1;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
            <div>
                <h2 style="margin:0;color:#1e293b;">Stripe Connect - Partner</h2>
                <p class="muted" style="margin:6px 0 0 0;font-size:14px;">Azienda collaborante | Transazioni e saldi live</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/index.php">← Dashboard</a>
                <a class="btn" href="?sync=1<?php echo $selectedAccountId !== '' ? '&account=' . urlencode($selectedAccountId) : ''; ?>">↻ Sincronizza</a>
            </div>
        </div>
        
        <?php if ($selectedLocal && $selectedAccountId === (string)($partnerAccount['stripe_account_id'] ?? '')): ?>
            <div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-top:12px;">
                <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="font-weight:600;color:#374151;font-size:13px;">Filtra per:</label>
                    <select name="mese" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;font-size:13px;">
                        <option value="0" <?php echo $isAllMonths ? 'selected' : ''; ?>>Tutti i mesi</option>
                        <option value="1" <?php echo $filterMese === 1 ? 'selected' : ''; ?>>Gennaio</option>
                        <option value="2" <?php echo $filterMese === 2 ? 'selected' : ''; ?>>Febbraio</option>
                        <option value="3" <?php echo $filterMese === 3 ? 'selected' : ''; ?>>Marzo</option>
                        <option value="4" <?php echo $filterMese === 4 ? 'selected' : ''; ?>>Aprile</option>
                        <option value="5" <?php echo $filterMese === 5 ? 'selected' : ''; ?>>Maggio</option>
                        <option value="6" <?php echo $filterMese === 6 ? 'selected' : ''; ?>>Giugno</option>
                        <option value="7" <?php echo $filterMese === 7 ? 'selected' : ''; ?>>Luglio</option>
                        <option value="8" <?php echo $filterMese === 8 ? 'selected' : ''; ?>>Agosto</option>
                        <option value="9" <?php echo $filterMese === 9 ? 'selected' : ''; ?>>Settembre</option>
                        <option value="10" <?php echo $filterMese === 10 ? 'selected' : ''; ?>>Ottobre</option>
                        <option value="11" <?php echo $filterMese === 11 ? 'selected' : ''; ?>>Novembre</option>
                        <option value="12" <?php echo $filterMese === 12 ? 'selected' : ''; ?>>Dicembre</option>
                    </select>
                    <select name="anno" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;font-size:13px;">
                        <option value="0" <?php echo $isAllYears ? 'selected' : ''; ?>>Tutti gli anni</option>
                        <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $filterAnno === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" style="padding:8px 16px;background:#3b82f6;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;">Filtra</button>
                </form>
            </div>
        <?php endif; ?>
        <?php if ($syncMessage): ?>
            <p style="margin-top:10px;color:#14532d;"><?php echo htmlspecialchars($syncMessage); ?></p>
        <?php endif; ?>
        <?php if ($pageError): ?>
            <p style="margin-top:10px;color:#991b1b;"><?php echo htmlspecialchars($pageError); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($selectedLocal && $selectedAccountId === (string)($partnerAccount['stripe_account_id'] ?? '')): ?>
        <div class="connect-overview connect-section">
            <div class="connect-metric">
                <div class="label">Transazioni</div>
                <div class="value" style="color:#10b981;"><?php echo number_format($partnerMetrics['total_transactions'], 0, ',', '.'); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Volume lordo</div>
                <div class="value" style="color:#3b82f6;">&euro;<?php echo connect_eur_from_cents($partnerMetrics['total_volume_cents']); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Media per transazione</div>
                <div class="value">&euro;<?php echo connect_eur_from_cents($partnerMetrics['avg_transaction_cents']); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Commissioni Stripe</div>
                <div class="value" style="color:#f59e0b;">&euro;<?php echo connect_eur_from_cents($partnerMetrics['commission_cents']); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Netto (stimato)</div>
                <div class="value" style="color:#059669;">&euro;<?php echo connect_eur_from_cents($partnerMetrics['net_cents']); ?></div>
            </div>
        </div>
    <?php else: ?>
        <div class="connect-overview connect-section">
            <div class="connect-metric">
                <div class="label">Account collegati</div>
                <div class="value"><?php echo number_format($overview['total'], 0, ',', '.'); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Account attivi</div>
                <div class="value"><?php echo number_format($overview['active'], 0, ',', '.'); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Saldo disponibile</div>
                <div class="value">&euro;<?php echo connect_eur_from_cents((int)$overview['balance_available']); ?></div>
            </div>
            <div class="connect-metric">
                <div class="label">Saldo in pending</div>
                <div class="value">&euro;<?php echo connect_eur_from_cents((int)$overview['balance_pending']); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="connect-layout connect-section">
        <div class="connect-list">
            <h3 style="margin-top:4px;color:#1f2937;">Tutti gli account</h3>
            <p style="font-size:12px;color:#6b7280;margin:0 0 10px 0;">Seleziona per visualizzare dettagli specifici</p>
            <?php if (!$accounts): ?>
                <p class="muted">Nessun account Connect presente.</p>
            <?php endif; ?>
            <?php foreach ($accounts as $acc): ?>
                <?php
                $accountId = (string)$acc['stripe_account_id'];
                $isCurrent = $accountId === $selectedAccountId;
                $isComplete = (int)($acc['onboarding_completed'] ?? 0) === 1;
                ?>
                <a class="connect-account-link <?php echo $isCurrent ? 'active' : ''; ?>" href="?account=<?php echo urlencode($accountId); ?>">
                    <div class="connect-account-top">
                        <div class="connect-account-name"><?php echo htmlspecialchars((string)($acc['business_name'] ?: $accountId)); ?></div>
                        <span class="connect-pill <?php echo $isComplete ? 'ok' : 'warn'; ?>">
                            <?php echo $isComplete ? 'Attivo' : 'Onboarding'; ?>
                        </span>
                    </div>
                    <div class="connect-account-id"><?php echo htmlspecialchars($accountId); ?></div>
                    <div class="connect-account-meta">
                        <span><?php echo htmlspecialchars((string)($acc['country'] ?: '-')); ?></span>
                        <span><?php echo htmlspecialchars((string)($acc['default_currency'] ?: 'EUR')); ?></span>
                        <span>Disp: &euro;<?php echo connect_eur_from_cents((int)($acc['balance_available'] ?? 0)); ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="connect-detail-card">
            <?php if (!$selectedLocal): ?>
                <h3 style="margin-top:0;">Vista aggregata account Connect</h3>
                <p class="muted">Seleziona un account a sinistra per visualizzare i dettagli.</p>
                <div class="connect-table-wrap">
                    <table class="connect-table">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>ID</th>
                                <th>Stato</th>
                                <th>Disponibile</th>
                                <th>Pending</th>
                                <th>Apri</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $acc): ?>
                                <?php
                                $accId = (string)($acc['stripe_account_id'] ?? '');
                                $accActive = (int)($acc['onboarding_completed'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)(($acc['business_name'] ?? '') !== '' ? $acc['business_name'] : $accId)); ?></td>
                                    <td><span style="font-family:monospace;"><?php echo htmlspecialchars($accId); ?></span></td>
                                    <td><?php echo $accActive ? 'Attivo' : 'Onboarding'; ?></td>
                                    <td>&euro;<?php echo connect_eur_from_cents((int)($acc['balance_available'] ?? 0)); ?></td>
                                    <td>&euro;<?php echo connect_eur_from_cents((int)($acc['balance_pending'] ?? 0)); ?></td>
                                    <td><a href="?account=<?php echo urlencode($accId); ?>">Dettaglio</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$accounts): ?>
                                <tr><td colspan="6" class="muted">Nessun account Connect disponibile.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php
                $accountName = (string)($selectedLocal['business_name'] ?: $selectedLocal['stripe_account_id']);
                $isActive = (int)($selectedLocal['onboarding_completed'] ?? 0) === 1;
                $available = $liveAvailable > 0 ? $liveAvailable : (int)($selectedLocal['balance_available'] ?? 0);
                $pending = $livePending > 0 ? $livePending : (int)($selectedLocal['balance_pending'] ?? 0);
                $currency = strtoupper((string)($selectedLocal['default_currency'] ?? 'EUR'));
                $capabilities = $selectedCapabilities;
                ?>
                <div class="connect-detail-head">
                    <div>
                        <h3 style="margin:0;"><?php echo htmlspecialchars($accountName); ?></h3>
                        <div class="connect-account-id" style="margin-top:4px;"><?php echo htmlspecialchars((string)$selectedLocal['stripe_account_id']); ?></div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span class="connect-pill <?php echo $isActive ? 'ok' : 'warn'; ?>">
                            <?php echo $isActive ? 'Abilitato' : 'Da completare'; ?>
                        </span>
                        <?php if ((int)($selectedLocal['charges_enabled'] ?? 0) !== 1): ?>
                            <span class="connect-pill bad">Charge OFF</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($detailError): ?>
                    <p style="color:#92400e;margin-top:10px;">Dettaglio live non disponibile: <?php echo htmlspecialchars($detailError); ?></p>
                <?php endif; ?>

                <div class="connect-balance-grid">
                    <div class="connect-balance-item">
                        <div class="b-label">Saldo disponibile (<?php echo htmlspecialchars($currency); ?>)</div>
                        <div class="b-value">&euro;<?php echo connect_eur_from_cents($available); ?></div>
                    </div>
                    <div class="connect-balance-item">
                        <div class="b-label">Saldo pending (<?php echo htmlspecialchars($currency); ?>)</div>
                        <div class="b-value">&euro;<?php echo connect_eur_from_cents($pending); ?></div>
                    </div>
                    <div class="connect-balance-item">
                        <div class="b-label">Bonifici abilitati</div>
                        <div class="b-value"><?php echo (int)($selectedLocal['payouts_enabled'] ?? 0) === 1 ? 'SI' : 'NO'; ?></div>
                    </div>
                </div>

                <div class="connect-cols">
                    <div class="connect-box">
                        <h4>Profilo</h4>
                        <div class="connect-kv">
                            <div class="k">Email</div><div class="v"><?php echo htmlspecialchars((string)($selectedLocal['email'] ?: '-')); ?></div>
                            <div class="k">Paese</div><div class="v"><?php echo htmlspecialchars((string)($selectedLocal['country'] ?: '-')); ?></div>
                            <div class="k">Tipo</div><div class="v"><?php echo htmlspecialchars((string)($selectedLocal['account_type'] ?: '-')); ?></div>
                            <div class="k">Business type</div><div class="v"><?php echo htmlspecialchars((string)($selectedLocal['business_type'] ?: '-')); ?></div>
                            <div class="k">Creato su Stripe</div><div class="v"><?php echo htmlspecialchars(connect_safe_date((string)($selectedLocal['stripe_created_at'] ?? null))); ?></div>
                            <div class="k">Ultimo sync</div><div class="v"><?php echo htmlspecialchars(connect_safe_date((string)($selectedLocal['last_synced_at'] ?? null))); ?></div>
                        </div>
                    </div>
                    <div class="connect-box">
                        <h4>Capability</h4>
                        <div class="connect-kv">
                            <?php if (!$capabilities): ?>
                                <div class="k">Stato</div><div class="v">Nessuna capability salvata</div>
                            <?php else: ?>
                                <?php foreach ($capabilities as $capName => $capState): ?>
                                    <div class="k"><?php echo htmlspecialchars((string)$capName); ?></div>
                                    <div class="v"><?php echo htmlspecialchars((string)$capState); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!empty($selectedLocal['disabled_reason'])): ?>
                                <div class="k">Disabled reason</div>
                                <div class="v"><?php echo htmlspecialchars((string)$selectedLocal['disabled_reason']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="connect-cols">
                    <div class="connect-box">
                        <h4>Ultimi bonifici</h4>
                        <div class="connect-table-wrap">
                            <table class="connect-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Importo</th>
                                        <th>Stato</th>
                                        <th>Arrivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $payouts = $liveDetail['payouts'] ?? []; ?>
                                    <?php if (!$payouts): ?>
                                        <tr><td colspan="4" class="muted">Nessun bonifico disponibile.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($payouts as $payout): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$payout['id']); ?></td>
                                            <td>&euro;<?php echo connect_eur_from_cents((int)$payout['amount']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$payout['status']); ?></td>
                                            <td><?php echo htmlspecialchars(connect_safe_date((string)($payout['arrival_date'] ?? null))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="connect-box">
                        <h4>Movimenti recenti</h4>
                        <div class="connect-table-wrap">
                            <table class="connect-table">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Importo</th>
                                        <th>Fee</th>
                                        <th>Netto</th>
                                        <th>Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $txRows = $liveDetail['transactions'] ?? []; ?>
                                    <?php if (!$txRows): ?>
                                        <tr><td colspan="5" class="muted">Nessun movimento disponibile.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($txRows as $tx): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$tx['type']); ?></td>
                                            <td>&euro;<?php echo connect_eur_from_cents((int)$tx['amount']); ?></td>
                                            <td>&euro;<?php echo connect_eur_from_cents((int)$tx['fee']); ?></td>
                                            <td>&euro;<?php echo connect_eur_from_cents((int)$tx['net']); ?></td>
                                            <td><?php echo htmlspecialchars(connect_safe_date((string)($tx['created'] ?? null))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
