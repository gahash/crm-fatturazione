<?php
require_once __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$config = require __DIR__ . '/../../config/config.php';
$secret = require __DIR__ . '/../../config/secret.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

// Gestione retry invio email con PDF (solo admin)
$retryOutput = null;
if (($user['ruolo'] ?? '') === 'admin' && isset($_POST['force_mail_retry_pdf'])) {
    ob_start();
    try {
        include __DIR__ . '/../fatture/actions/mail_retry_pdf.php';
    } catch (Throwable $e) {
        echo 'Errore retry email: ' . $e->getMessage() . "\n";
    }
    $retryOutput = (string) ob_get_clean();
}

// Card SDI pagamenti
$sdiPagamenti = [];
try {
    $stmt = $pdo->query("SELECT id, stripe_id, fic_document_id, fic_status, numero_fattura, created_at FROM pagamenti WHERE fic_document_id IS NOT NULL ORDER BY created_at DESC LIMIT 10");
    $sdiPagamenti = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sdiPagamenti = [];
}

require __DIR__ . '/../fatture/actions/fic_api.php';
require __DIR__ . '/../lead/facebook_api.php';

// Tracking avanzato invio email/PDF fatture (solo admin)
$mailLogs = [];
if (($user['ruolo'] ?? '') === 'admin') {
    try {
        $stmt = $pdo->prepare("SELECT l.id, l.fattura_id, l.esito, l.messaggio, l.meta_json, l.creato_il, f.numero, c.email AS destinatario FROM fatture_log l LEFT JOIN fatture f ON f.id = l.fattura_id LEFT JOIN clienti c ON c.id = f.cliente_id WHERE l.azione = 'send_mail' ORDER BY l.id DESC LIMIT 30");
        $stmt->execute();
        $mailLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $mailLogs = [['messaggio' => 'Errore caricamento log: ' . $e->getMessage()]];
    }
}

// Carica token da tmp se presente
$tokenPath = __DIR__ . '/../../tmp/fic_token.json';
$tokenTmp = null;
$accessTokenTmp = null;
$companyIdTmp = null;
if (file_exists($tokenPath)) {
    $raw = file_get_contents($tokenPath);
    $tokenTmp = json_decode($raw, true);
    $accessTokenTmp = $tokenTmp['access_token'] ?? null;
    $companyIdTmp = $tokenTmp['company_id'] ?? null;
}

$ficToken = '';
try {
    $ficToken = fic_get_access_token();
} catch (Throwable $e) {
    error_log('impostazioni/index.php fic_get_access_token: ' . $e->getMessage());
}
$ficConnected = false;
$ficConnectionError = null;
if ($ficToken !== '') {
    try {
        $ficPing = fic_request('GET', '/user/info');
        $ficConnected = (($ficPing['status'] ?? 0) >= 200 && ($ficPing['status'] ?? 0) < 300);
        if (!$ficConnected) {
            $ficConnectionError = 'HTTP ' . (int)($ficPing['status'] ?? 0);
        }
    } catch (Throwable $e) {
        $ficConnected = false;
        $ficConnectionError = substr((string)$e->getMessage(), 0, 140);
    }
}

$fbToken = '';
try {
    $fbToken = fb_get_access_token();
} catch (Throwable $e) {
    error_log('impostazioni/index.php fb_get_access_token: ' . $e->getMessage());
}
$fbConnected = $fbToken !== '';

$stripe = $secret['stripe'] ?? [];
$stripeSecret = $stripe['secret_key'] ?? '';
$stripePublic = $stripe['public_key'] ?? '';
$stripeConnected = $stripeSecret !== '' && $stripePublic !== '';

$ficCfg = $secret['fattureincloud'] ?? [];
$fbCfg = $secret['facebook'] ?? [];
$ficConfigAccess = $ficCfg['access_token'] ?? '';
$ficConfigCompany = $ficCfg['company_id'] ?? '';
$ficRecoveryNumeration = trim((string)($ficCfg['duplicate_recovery_numeration'] ?? ''));

$mailConfigPath = __DIR__ . '/../../config/mail.php';
$mailCfg = file_exists($mailConfigPath) ? (require $mailConfigPath) : [];
$mailConfigured = !empty($mailCfg['smtp_host']) && !empty($mailCfg['from_email']);
$mailSecure = $mailCfg['smtp_secure'] ?? 'tls';

$schedule = [
    'enabled' => 0,
    'interval_minutes' => 60,
    'last_run_at' => null,
    'updated_at' => null,
];
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_scheduler_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sync_key VARCHAR(100) NOT NULL UNIQUE,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
        last_run_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $scheduleStmt = $pdo->prepare('SELECT enabled, interval_minutes, last_run_at, updated_at FROM sync_scheduler_settings WHERE sync_key = :sync_key LIMIT 1');
    $scheduleStmt->execute([':sync_key' => 'pagamenti_stripe']);
    $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC) ?: $schedule;
} catch (Throwable $e) {
    error_log('impostazioni/index.php sync_scheduler_settings: ' . $e->getMessage());
}

$scheduleEnabled = (int)($schedule['enabled'] ?? 0) === 1;
$scheduleInterval = (int)($schedule['interval_minutes'] ?? 60);
$scheduleLastRun = $schedule['last_run_at'] ?? null;

require_once __DIR__ . '/../../config/env.php';
$cronSecret = (string)(getenv('CRON_SECRET') ?: '');
$cronBase = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$cronUrl = $cronBase . '/modules/pagamenti/sincronizza_stripe.php?scheduled=1&cron=' . rawurlencode($cronSecret);

$sendSettings = [
    'send_mode' => 'manuale',
    'enabled' => 0,
    'updated_at' => null,
];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_send_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        send_mode VARCHAR(20) NOT NULL DEFAULT 'manuale',
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sendStmt = $pdo->prepare('SELECT send_mode, enabled, updated_at FROM invoice_send_settings WHERE setting_key = :setting_key LIMIT 1');
    $sendStmt->execute([':setting_key' => 'fatture_send']);
    $sendSettings = $sendStmt->fetch(PDO::FETCH_ASSOC) ?: $sendSettings;
} catch (Throwable $e) {
    error_log('impostazioni/index.php invoice_send_settings: ' . $e->getMessage());
}

$sendMode = (string)($sendSettings['send_mode'] ?? 'manuale');
if (!in_array($sendMode, ['manuale', 'automatico'], true)) {
    $sendMode = 'manuale';
}
$sendEnabled = (int)($sendSettings['enabled'] ?? 0) === 1;

require __DIR__ . '/../../layouts/header.php';
?>
<style>
.settings-page {
    max-width: 1280px;
    margin: 0 auto;
    display: grid;
    gap: 22px;
}
.settings-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    padding: 22px;
    border: 1px solid #dbe4f0;
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 32%),
        linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.06);
}
.settings-hero h2 {
    margin: 0;
    color: #0f172a;
    font-size: 28px;
}
.settings-hero p {
    margin: 6px 0 0 0;
    max-width: 780px;
}
.settings-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}
.settings-status-card {
    position: relative;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px;
    background: #ffffff;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
}
.settings-status-card::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: #94a3b8;
}
.settings-status-card.is-ok::before {
    background: #10b981;
}
.settings-status-card.is-warn::before {
    background: #f59e0b;
}
.settings-status-card.is-info::before {
    background: #0ea5e9;
}
.settings-status-card .eyebrow {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.settings-status-card .value {
    margin-top: 8px;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}
.settings-status-card .meta {
    margin-top: 6px;
    font-size: 12px;
    color: #64748b;
}
.settings-section {
    display: grid;
    gap: 14px;
}
.settings-section-head h3 {
    margin: 0;
    font-size: 20px;
    color: #0f172a;
}
.settings-section-head p {
    margin: 4px 0 0 0;
}
.settings-section-head {
    padding: 0 2px;
}
.settings-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 12px;
}
.settings-page .card {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 10px 26px rgba(15, 23, 42, 0.04);
}
.settings-page .card h3 {
    color: #0f172a;
}
.settings-page .btn {
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.16);
}
.settings-page .btn.secondary {
    box-shadow: none;
}
.settings-page .badge.ok {
    background: #dcfce7;
    color: #166534;
}
.settings-page .badge.ko {
    background: #fee2e2;
    color: #991b1b;
}
.settings-list {
    margin: 0;
    padding-left: 18px;
}
.settings-list li {
    margin-bottom: 8px;
}
.settings-output {
    margin-top: 12px;
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #dbe4f0;
    background: #f8fbff;
    font-size: 13px;
    white-space: pre-wrap;
}
.settings-table-wrap {
    overflow-x: auto;
    max-width: 100%;
}
.settings-page .table {
    border-collapse: separate;
    border-spacing: 0;
}
.settings-page .table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8fafc;
    color: #334155;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.settings-page .table th,
.settings-page .table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
}
.settings-page .table tbody tr:hover {
    background: #fafcff;
}
.settings-page details {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px 12px;
    background: #fcfdff;
}
.settings-page summary {
    color: #0f172a;
}
.settings-muted-box {
    border: 1px solid #dbe4f0;
    border-radius: 12px;
    background: linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
    padding: 14px;
}
.settings-stack {
    display: grid;
    gap: 12px;
}
.settings-card-title-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
@media (max-width: 760px) {
    .settings-hero {
        align-items: stretch;
        padding: 18px;
    }
    .settings-hero h2 {
        font-size: 24px;
    }
}
</style>
<div class="wrap settings-page">
    <?php if (isset($_GET['fic_connected']) && (string)$_GET['fic_connected'] === '1'): ?>
        <div class="alert success">✅ Connessione Fatture in Cloud aggiornata con successo<?php echo !empty($_GET['fic_company']) ? ' (company_id: ' . htmlspecialchars((string)$_GET['fic_company']) . ')' : ''; ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_oauth_error'])): ?>
        <div class="alert error">Connessione OAuth Fatture in Cloud fallita: <?php echo htmlspecialchars((string)($_GET['fic_oauth_error_desc'] ?? (string)$_GET['fic_oauth_error'])); ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_recovery_saved']) && (string)$_GET['fic_recovery_saved'] === '1'): ?>
        <div class="alert success">Configurazione numerazione recupero FIC aggiornata.</div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_recovery_error'])): ?>
        <div class="alert error">Salvataggio numerazione recupero FIC fallito: <?php echo htmlspecialchars((string)$_GET['fic_recovery_error']); ?>.</div>
    <?php endif; ?>
    <?php if (!$ficConnected && !empty($ficConnectionError)): ?>
        <div class="alert error">FIC non connesso: <?php echo htmlspecialchars((string)$ficConnectionError); ?>.</div>
    <?php endif; ?>
    <div class="card settings-hero">
        <div>
            <h2>Impostazioni e Diagnostica</h2>
            <p class="muted">Pannello operativo per integrazioni, automazioni, invio fatture e strumenti di verifica.</p>
        </div>
        <div class="badge <?php echo ($sendMode === 'automatico' && $sendEnabled) ? 'ok' : 'ko'; ?>">
            <?php echo ($sendMode === 'automatico' && $sendEnabled) ? 'Invio automatico attivo' : 'Invio manuale/disattivo'; ?>
        </div>
    </div>

    <div class="settings-status-grid">
        <div class="settings-status-card <?php echo $stripeConnected ? 'is-ok' : 'is-warn'; ?>">
            <div class="eyebrow">Stripe</div>
            <div class="value"><?php echo $stripeConnected ? 'Connesso' : 'Da configurare'; ?></div>
            <div class="meta">Chiavi API <?php echo $stripeConnected ? 'presenti' : 'incomplete'; ?></div>
        </div>
        <div class="settings-status-card <?php echo $ficConnected ? 'is-ok' : 'is-warn'; ?>">
            <div class="eyebrow">Fatture in Cloud</div>
            <div class="value"><?php echo $ficConnected ? 'Connesso' : 'Non connesso'; ?></div>
            <div class="meta">Company ID: <?php echo $companyIdTmp ? htmlspecialchars((string)$companyIdTmp) : ($ficConfigCompany !== '' ? htmlspecialchars((string)$ficConfigCompany) : 'non disponibile'); ?></div>
            <div style="margin-top:10px;">
                <a class="btn <?php echo $ficConnected ? 'secondary' : ''; ?>" href="../fatture/actions/fic_oauth_start.php?return=%2Fmodules%2Fimpostazioni%2Findex.php">
                    <?php echo $ficConnected ? 'Ricollega Fatture in Cloud' : 'Connetti Fatture in Cloud'; ?>
                </a>
            </div>
        </div>
        <div class="settings-status-card <?php echo $mailConfigured ? 'is-ok' : 'is-warn'; ?>">
            <div class="eyebrow">SMTP</div>
            <div class="value"><?php echo $mailConfigured ? 'Configurato' : 'Da configurare'; ?></div>
            <div class="meta">Protezione: <?php echo htmlspecialchars((string)$mailSecure); ?></div>
        </div>
        <div class="settings-status-card <?php echo $scheduleEnabled ? 'is-info' : 'is-warn'; ?>">
            <div class="eyebrow">Sync pagamenti</div>
            <div class="value"><?php echo $scheduleEnabled ? 'Automatica attiva' : 'Automatica disattiva'; ?></div>
            <div class="meta">Ultima esecuzione: <?php echo $scheduleLastRun ? htmlspecialchars((string)$scheduleLastRun) : 'mai'; ?></div>
        </div>
    </div>

    <div class="settings-section">
        <div class="settings-section-head">
            <h3>Automazioni</h3>
            <p class="muted">Gestisci sincronizzazione pagamenti e invio automatico fatture.</p>
        </div>
        <div class="settings-grid-2">
            <div class="card">
                <div class="settings-card-title-row">
                    <h3 style="margin:0;">Pianificazione sincronizzazione pagamenti</h3>
                    <span class="badge <?php echo $scheduleEnabled ? 'ok' : 'ko'; ?>"><?php echo $scheduleEnabled ? 'Attiva' : 'Disattiva'; ?></span>
                </div>
                <p class="muted">Configura ogni quanto tempo eseguire la sincronizzazione automatica dei pagamenti da Stripe.</p>
                <form method="post" action="sync_schedule_store.php">
                    <div class="grid" style="margin-top:12px;">
                        <div>
                            <label>Stato automazione</label>
                            <select name="enabled">
                                <option value="1" <?php echo $scheduleEnabled ? 'selected' : ''; ?>>Attiva</option>
                                <option value="0" <?php echo !$scheduleEnabled ? 'selected' : ''; ?>>Disattiva</option>
                            </select>
                        </div>
                        <div>
                            <label>Intervallo sincronizzazione</label>
                            <select name="interval_minutes" required>
                                <?php foreach ([5, 10, 15, 30, 60, 120, 240, 720, 1440] as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $scheduleInterval === $opt ? 'selected' : ''; ?>>
                                        <?php echo $opt >= 60 ? ($opt % 60 === 0 ? ($opt / 60) . ' ore' : $opt . ' minuti') : $opt . ' minuti'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Ultima sincronizzazione automatica</label>
                            <input type="text" value="<?php echo $scheduleLastRun ? htmlspecialchars((string)$scheduleLastRun) : 'Mai eseguita'; ?>" readonly>
                        </div>
                        <div>
                            <label>URL Cron (automatico)</label>
                            <input type="text" value="<?php echo htmlspecialchars($cronUrl); ?>" readonly>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                        <button class="btn" type="submit">Salva pianificazione</button>
                    </div>
                </form>
                <div class="settings-muted-box" style="margin-top:10px;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Nota operativa</div>
                    <div style="margin-top:6px;color:#334155;font-size:13px;">Configura un cron esterno usando l'URL sopra per avviare la sincronizzazione automatica.</div>
                </div>
            </div>

            <div class="card">
                <div class="settings-card-title-row">
                    <h3 style="margin:0;">Invio fatture</h3>
                    <span class="badge <?php echo ($sendMode === 'automatico' && $sendEnabled) ? 'ok' : 'ko'; ?>"><?php echo ($sendMode === 'automatico' && $sendEnabled) ? 'Automatico attivo' : 'Manuale/Disattivo'; ?></span>
                </div>
                <p class="muted">Se attivi l'invio automatico, le fatture vengono inoltrate automaticamente solo per clienti con <strong>P.IVA</strong>, <strong>email valida</strong> e anagrafica minima (nome o azienda).</p>
                <form method="post" action="invio_fatture_store.php">
                    <div class="grid" style="margin-top:12px;">
                        <div>
                            <label>Modalità invio</label>
                            <select name="send_mode" required>
                                <option value="manuale" <?php echo $sendMode === 'manuale' ? 'selected' : ''; ?>>Manuale</option>
                                <option value="automatico" <?php echo $sendMode === 'automatico' ? 'selected' : ''; ?>>Automatico</option>
                            </select>
                        </div>
                        <div>
                            <label>Stato automazione</label>
                            <select name="enabled" required>
                                <option value="1" <?php echo $sendEnabled ? 'selected' : ''; ?>>Attiva</option>
                                <option value="0" <?php echo !$sendEnabled ? 'selected' : ''; ?>>Disattiva</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                        <button class="btn" type="submit">Salva impostazioni invio</button>
                    </div>
                </form>
                <div class="settings-muted-box" style="margin-top:10px;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Regola</div>
                    <div style="margin-top:6px;color:#334155;font-size:13px;">Senza P.IVA o email valida il sistema mantiene l'invio manuale per quella fattura o cliente.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="settings-section">
        <div class="settings-section-head">
            <h3>Monitoraggio e Operazioni</h3>
            <p class="muted">Azioni amministrative, stato integrazioni e ultimi eventi rilevanti.</p>
        </div>
        <div class="settings-grid-2">
            <div class="card">
                <h3>Azioni amministratore</h3>
                <form method="post" style="margin-bottom:12px;">
                    <button class="btn" name="force_mail_retry_pdf" value="1" type="submit">Forza retry invio email con PDF</button>
                </form>
                <?php if ($retryOutput !== null): ?>
                    <?php
                        $sentCount = 0;
                        if (preg_match('/Inviate: (\d+)/', $retryOutput, $m)) {
                            $sentCount = (int)$m[1];
                        }
                    ?>
                    <?php if ($sentCount > 0): ?>
                        <div class="alert success">Sono state inviate <strong><?php echo $sentCount; ?></strong> email con PDF allegato.</div>
                    <?php else: ?>
                        <div class="alert info">Nessuna nuova email inviata.</div>
                    <?php endif; ?>
                    <div class="settings-output"><?php echo htmlspecialchars($retryOutput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Check configurazioni</h3>
                <ul class="settings-list">
                    <li>FIC access_token (config/secret.php): <strong><?php echo $ficConfigAccess === '' || $ficConfigAccess === 'FIC_ACCESS_TOKEN' ? 'Vuoto' : substr($ficConfigAccess, 0, 15) . '...'; ?></strong></li>
                    <li>FIC company_id (config/secret.php): <strong><?php echo $ficConfigCompany === '' || $ficConfigCompany === '12345' ? 'Placeholder' : htmlspecialchars((string)$ficConfigCompany); ?></strong></li>
                    <li>FIC numerazione recupero duplicati: <strong><?php echo $ficRecoveryNumeration !== '' ? htmlspecialchars($ficRecoveryNumeration) : 'Serie standard'; ?></strong></li>
                    <?php if ($accessTokenTmp && $companyIdTmp): ?>
                        <li>FIC token disponibile (tmp/fic_token.json): <strong>Sì (Company: <?php echo htmlspecialchars((string)$companyIdTmp); ?>)</strong></li>
                    <?php endif; ?>
                    <li>Facebook Page ID: <strong><?php echo htmlspecialchars((string)($fbCfg['page_id'] ?? '')); ?></strong></li>
                    <li>Facebook Lead Form ID: <strong><?php echo htmlspecialchars((string)($fbCfg['lead_form_id'] ?? '')); ?></strong></li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="settings-card-title-row">
                <h3 style="margin:0;">Fatture in Cloud: numerazione recupero duplicati</h3>
                <span class="badge <?php echo $ficRecoveryNumeration !== '' ? 'ok' : 'ko'; ?>"><?php echo $ficRecoveryNumeration !== '' ? 'Configurata' : 'Non configurata'; ?></span>
            </div>
            <p class="muted">Usa una numerazione o sezionale dedicata per rigenerare le fatture Stripe arretrate senza entrare in conflitto con la serie ordinaria FIC. Lascia vuoto per continuare a usare la serie standard.</p>
            <form method="post" action="fic_recovery_store.php">
                <div class="grid" style="margin-top:12px;">
                    <div>
                        <label>Numerazione / sezionale FIC</label>
                        <input type="text" name="duplicate_recovery_numeration" value="<?php echo htmlspecialchars($ficRecoveryNumeration); ?>" placeholder="Es. STRIPE-REC o S2">
                    </div>
                    <div>
                        <label>Uso</label>
                        <input type="text" value="Applicata solo alla rigenerazione duplicati FIC" readonly>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                    <button class="btn" type="submit">Salva numerazione recupero</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="settings-card-title-row">
                <h3 style="margin:0;">Tracking invio email fatture</h3>
                <span class="badge" style="background:#e0f2fe;color:#075985;">Ultimi 30 eventi</span>
            </div>
            <p class="muted">Ultimi 30 invii automatici/manuali di fatture via email, con stato PDF e destinatario.</p>
            <div class="settings-table-wrap">
                <table class="table" style="font-size:13px;min-width:600px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fattura</th>
                            <th>Destinatario</th>
                            <th>Esito</th>
                            <th>PDF</th>
                            <th>Tipo</th>
                            <th>Messaggio</th>
                            <th>Data/Ora</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mailLogs as $log):
                        $meta = [];
                        if (!empty($log['meta_json'])) {
                            $meta = @json_decode($log['meta_json'], true) ?: [];
                        }
                        $pdfStatus = $meta['pdf_status'] ?? ($meta['allegati'] ?? 0 ? 'attached' : 'link');
                        $tipo = $meta['invio_tipo'] ?? '-';
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($log['numero'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($log['destinatario'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($log['esito'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($pdfStatus); ?></td>
                            <td><?php echo htmlspecialchars($tipo); ?></td>
                            <td><?php echo htmlspecialchars($log['messaggio'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($log['creato_il'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="settings-card-title-row">
                <h3 style="margin:0;">Ultimi documenti SDI da pagamenti</h3>
                <span class="badge" style="background:#ede9fe;color:#5b21b6;">Tracciamento</span>
            </div>
            <?php if (!$sdiPagamenti): ?>
                <p class="muted">Nessun documento SDI collegato ai pagamenti trovato.</p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="table" style="font-size:13px;min-width:640px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Stripe ID</th>
                                <th>FIC Document ID</th>
                                <th>Stato</th>
                                <th>Numero fattura</th>
                                <th>Creato il</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sdiPagamenti as $row): ?>
                                <tr>
                                    <td><?php echo (int)($row['id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['stripe_id'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['fic_document_id'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['fic_status'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['numero_fattura'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="settings-section">
        <div class="settings-section-head">
            <h3>Diagnostica</h3>
            <p class="muted">Strumenti di verifica per allineamento FIC, PDF e sincronizzazione delle fatture.</p>
        </div>
        <div class="settings-grid-2">
            <div class="card">
                <div class="settings-card-title-row">
                    <h3 style="margin:0;">Test Allineamento Fatture in Cloud</h3>
                    <span class="badge" style="background:#6366f1;color:white;">Diagnostica</span>
                </div>
                <p class="muted">Verifica la coerenza tra le fatture del gestionale e Fatture in Cloud: controlla se i documenti esistono, sono raggiungibili e i PDF sono disponibili.</p>
                <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button class="btn" id="runAlignmentTestBtn" type="button">Esegui Test Allineamento</button>
                    <label style="display:flex;align-items:center;gap:6px;margin:0;">
                        <input type="checkbox" id="testIncludeOk" style="margin:0;">
                        <span class="muted" style="font-size:0.9rem;">Includi fatture OK nel report</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;margin:0;">
                        <input type="checkbox" id="testAllInvoices" style="margin:0;">
                        <span class="muted" style="font-size:0.9rem;">Tutte le fatture (non solo Stripe)</span>
                    </label>
                </div>
                <div id="alignmentTestResults" style="margin-top:16px;display:none;">
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px;">
                        <h4 style="margin:0 0 12px 0;font-size:1rem;">Risultati Test</h4>
                        <div id="alignmentTestContent"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="settings-card-title-row">
                    <h3 style="margin:0;">Test Sincronizzazione FIC (Debug esteso)</h3>
                    <span class="badge" style="background:#8b5cf6;color:white;">Debug</span>
                </div>
                <p class="muted">Test dettagliato della sincronizzazione con Fatture in Cloud: verifica connettività, configurazione e tenta di creare documenti mancanti per le ultime 10 fatture Stripe.</p>
                <div style="margin-top:16px;">
                    <button class="btn" id="runFicSyncTestBtn" type="button">Esegui Test Sincronizzazione FIC</button>
                </div>
                <div id="ficSyncTestResults" style="margin-top:16px;display:none;">
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px;">
                        <h4 style="margin:0 0 12px 0;font-size:1rem;">Risultati Test</h4>
                        <div id="ficSyncTestContent"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="settings-section">
        <div class="settings-section-head">
            <h3>Setup Tecnico</h3>
            <p class="muted">Procedure assistite e script per completare la configurazione database Stripe.</p>
        </div>
        <div class="card">
            <div class="settings-card-title-row">
                <h3 style="margin:0;">Migrazione Database Stripe</h3>
                <span class="badge" style="background:#8b5cf6;color:white;">Setup</span>
            </div>
            <p class="muted">Se i test mostrano <code>migration_needed: true</code>, significa che mancano alcune colonne nella tabella fatture per supportare le funzionalità Stripe.</p>
            <div class="settings-muted-box" style="margin-top:12px;border-left:3px solid #f59e0b;background:linear-gradient(180deg,#fffaf0 0%,#fffbeb 100%);">
                <strong style="color:#92400e;">Colonne necessarie:</strong>
                <ul style="margin:8px 0 0 0;padding-left:20px;color:#92400e;">
                    <li><code>metodo_pagamento</code> - per identificare fatture da pagamenti Stripe</li>
                    <li><code>stripe_session_id</code>, <code>stripe_payment_intent_id</code>, <code>stripe_checkout_url</code> - per tracciare transazioni Stripe</li>
                </ul>
            </div>
            <div style="margin-top:16px;">
                <strong>Come eseguire la migrazione:</strong>
                <div class="settings-muted-box" style="margin:12px 0;border-left:3px solid #3b82f6;background:linear-gradient(180deg,#f5fbff 0%,#eff6ff 100%);">
                    <strong>Opzione 1: Script automatico</strong>
                    <p style="margin:8px 0;">Usa lo script automatico che esegue la migrazione per te.</p>
                    <a href="run_stripe_migration.php" class="btn" style="background:#10b981;margin-top:8px;">Esegui Migrazione Automatica</a>
                </div>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-weight:600;padding:8px 0;">Opzione 2: Manuale (phpMyAdmin o client MySQL)</summary>
                    <ol style="margin:8px 0 0 20px;line-height:1.6;">
                        <li>Accedi al database remoto (phpMyAdmin o MySQL client)</li>
                        <li>Seleziona il database del gestionale</li>
                        <li>Esegui il contenuto di uno di questi file SQL:
                            <ul style="margin:4px 0 0 20px;">
                                <li><code style="background:#f9fafb;padding:2px 6px;border:1px solid #e5e7eb;">database/add_metodo_pagamento.sql</code> <span class="muted">(solo metodo_pagamento)</span></li>
                                <li><code style="background:#f9fafb;padding:2px 6px;border:1px solid #e5e7eb;">database/add_stripe_fields_fatture.sql</code> <span class="muted">(tutte le colonne Stripe)</span></li>
                            </ul>
                        </li>
                        <li>Ricarica la pagina ed esegui nuovamente i test per verificare</li>
                    </ol>
                </details>
            </div>
            <details style="margin-top:16px;">
                <summary style="cursor:pointer;font-weight:600;color:#1e40af;padding:8px 0;">Mostra script SQL rapido (solo metodo_pagamento)</summary>
                <pre style="background:#f9fafb;padding:12px;border:1px solid #e5e7eb;border-radius:4px;font-size:0.85rem;overflow-x:auto;margin-top:8px;">ALTER TABLE fatture 
ADD COLUMN metodo_pagamento VARCHAR(50) NULL;</pre>
                <button class="btn secondary" onclick="navigator.clipboard.writeText('ALTER TABLE fatture ADD COLUMN metodo_pagamento VARCHAR(50) NULL;').then(() => alert('SQL copiato negli appunti!'))" type="button" style="margin-top:8px;">Copia SQL</button>
            </details>
        </div>
    </div>
</div>

<script>
document.getElementById('runAlignmentTestBtn').addEventListener('click', function() {
    const btn = this;
    const resultsDiv = document.getElementById('alignmentTestResults');
    const contentDiv = document.getElementById('alignmentTestContent');
    const includeOk = document.getElementById('testIncludeOk').checked ? '1' : '0';
    const stripeOnly = document.getElementById('testAllInvoices').checked ? '0' : '1';
    
    btn.disabled = true;
    btn.textContent = 'Test in corso...';
    contentDiv.innerHTML = '<p class="muted">Verifica in corso, attendere...</p>';
    resultsDiv.style.display = 'block';
    
    const url = '../fatture/actions/fic_alignment_test.php?stripe_only=' + stripeOnly + '&include_ok=' + includeOk + '&limit=100';
    
    fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (!data.ok) {
            contentDiv.innerHTML = '<div class="alert error">❌ Errore: ' + (data.error || 'Test fallito') + '</div>';
            return;
        }
        
        let html = '';
        
        // Avviso migrazione database necessaria
        if (data.migration_needed) {
            html += '<div class="alert error" style="margin-bottom:16px;">';
            html += '<strong>⚠️ Migrazione database necessaria!</strong><br>';
            html += 'La colonna <code>metodo_pagamento</code> non esiste nella tabella fatture.<br>';
            html += 'Esegui questo script SQL sul database remoto:<br>';
            html += '<code style="display:block;background:#fff;padding:8px;margin-top:8px;border:1px solid #ddd;">database/add_stripe_fields_fatture.sql</code>';
            html += '</div>';
        }
        
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">';
        
        const s = data.summary;
        html += '<div style="background:white;padding:12px;border-radius:4px;border-left:3px solid #3b82f6;"><div class="muted" style="font-size:0.75rem;text-transform:uppercase;">Fatture verificate</div><div style="font-size:1.5rem;font-weight:600;margin-top:4px;">' + s.checked + '</div></div>';
        html += '<div style="background:white;padding:12px;border-radius:4px;border-left:3px solid #10b981;"><div class="muted" style="font-size:0.75rem;text-transform:uppercase;">Allineate</div><div style="font-size:1.5rem;font-weight:600;margin-top:4px;color:#10b981;">' + s.aligned + '</div></div>';
        html += '<div style="background:white;padding:12px;border-radius:4px;border-left:3px solid #f59e0b;"><div class="muted" style="font-size:0.75rem;text-transform:uppercase;">Con problemi</div><div style="font-size:1.5rem;font-weight:600;margin-top:4px;color:#f59e0b;">' + s.with_issues + '</div></div>';
        html += '<div style="background:white;padding:12px;border-radius:4px;border-left:3px solid #ef4444;"><div class="muted" style="font-size:0.75rem;text-transform:uppercase;">Anagrafica incompleta</div><div style="font-size:1.5rem;font-weight:600;margin-top:4px;color:#ef4444;">' + s.anagrafica_incomplete + '</div></div>';
        html += '</div>';
        
        if (s.with_issues > 0 || s.anagrafica_incomplete > 0) {
            html += '<div style="background:#fef3c7;border-left:3px solid #f59e0b;padding:12px;border-radius:4px;margin-bottom:16px;">';
            html += '<strong style="color:#92400e;">⚠️ Problemi rilevati:</strong><ul style="margin:8px 0 0 0;padding-left:20px;color:#92400e;">';
            if (s.missing_fic_document > 0) html += '<li>' + s.missing_fic_document + ' fatture senza fic_document_id</li>';
            if (s.fic_document_unreachable > 0) html += '<li>' + s.fic_document_unreachable + ' documenti FIC non raggiungibili</li>';
            if (s.pdf_not_ready > 0) html += '<li>' + s.pdf_not_ready + ' PDF non disponibili</li>';
            if (s.auto_send_expected_but_not_sent > 0) html += '<li>' + s.auto_send_expected_but_not_sent + ' fatture con invio automatico atteso ma non eseguito</li>';
            html += '</ul></div>';
        } else {
            html += '<div class="alert success">✅ Tutte le fatture verificate sono allineate con Fatture in Cloud.</div>';
        }
        
        if (data.results && data.results.length > 0) {
            html += '<details style="margin-top:12px;"><summary style="cursor:pointer;font-weight:600;padding:8px 0;">Dettagli (' + data.results.length + ' fatture)</summary>';
            html += '<div style="margin-top:12px;max-height:400px;overflow-y:auto;">';
            data.results.forEach(function(r) {
                const statusClass = r.ok ? 'ok' : 'ko';
                const statusIcon = r.ok ? '✓' : '✗';
                html += '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:10px;margin-bottom:8px;background:white;">';
                html += '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:6px;">';
                html += '<div><strong>' + r.numero + '</strong> · ' + r.cliente + ' <span class="muted">(ID: ' + r.fattura_id + ')</span></div>';
                html += '<span class="badge ' + statusClass + '">' + statusIcon + '</span>';
                html += '</div>';
                
                if (r.issues && r.issues.length > 0) {
                    html += '<div style="background:#fee2e2;padding:8px;border-radius:3px;margin-top:6px;font-size:0.85rem;">';
                    html += '<strong style="color:#991b1b;">Problemi:</strong><ul style="margin:4px 0 0 0;padding-left:20px;color:#991b1b;">';
                    r.issues.forEach(function(issue) {
                        html += '<li>' + issue + '</li>';
                    });
                    html += '</ul></div>';
                }
                
                if (r.warnings && r.warnings.length > 0) {
                    html += '<div style="background:#fef3c7;padding:8px;border-radius:3px;margin-top:6px;font-size:0.85rem;">';
                    html += '<strong style="color:#92400e;">Avvisi:</strong><ul style="margin:4px 0 0 0;padding-left:20px;color:#92400e;">';
                    r.warnings.forEach(function(warn) {
                        html += '<li>' + warn + '</li>';
                    });
                    html += '</ul></div>';
                }
                
                if (r.mail_error) {
                    html += '<div class="muted" style="font-size:0.8rem;margin-top:6px;"><strong>Dettaglio tecnico:</strong> ' + r.mail_error + '</div>';
                }
                
                html += '</div>';
            });
            html += '</div></details>';
        }
        
        html += '<div class="muted" style="margin-top:16px;font-size:0.85rem;padding-top:12px;border-top:1px solid #e5e7eb;">Test eseguito: ' + data.run_at + ' · Company ID: ' + data.company_id + '</div>';
        
        contentDiv.innerHTML = html;
    })
    .catch(error => {
        contentDiv.innerHTML = '<div class="alert error">❌ Errore di rete: ' + error.message + '</div>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Esegui Test Allineamento';
    });
});

document.getElementById('runFicSyncTestBtn').addEventListener('click', function() {
    const btn = this;
    const resultsDiv = document.getElementById('ficSyncTestResults');
    const contentDiv = document.getElementById('ficSyncTestContent');
    
    btn.disabled = true;
    btn.textContent = 'Test in corso...';
    contentDiv.innerHTML = '<p class="muted">Verifica configurazione e sincronizzazione FIC...</p>';
    resultsDiv.style.display = 'block';
    
    fetch('../fatture/actions/test_fic_sync.php', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (!data.ok) {
            contentDiv.innerHTML = '<div class="alert error">❌ Errore: ' + (data.error || 'Test fallito') + '</div>';
            if (data.trace) {
                contentDiv.innerHTML += '<details style="margin-top:12px;"><summary style="cursor:pointer;">Stack trace</summary><pre style="background:#fefefe;padding:10px;border:1px solid #ddd;font-size:0.8rem;overflow-x:auto;">' + data.trace.join('\n') + '</pre></details>';
            }
            return;
        }
        
        let html = '';
        
        // Avviso migrazione database necessaria
        if (data.migration_needed) {
            html += '<div class="alert error" style="margin-bottom:16px;">';
            html += '<strong>⚠️ Migrazione database necessaria!</strong><br>';
            html += 'La colonna <code>metodo_pagamento</code> non esiste nella tabella fatture.<br>';
            html += 'Senza questa colonna non è possibile filtrare le fatture Stripe.<br>';
            html += '<strong>Soluzione:</strong> Esegui questo script SQL sul database remoto:<br>';
            html += '<code style="display:block;background:#fff;padding:8px;margin-top:8px;border:1px solid #ddd;font-family:monospace;">database/add_stripe_fields_fatture.sql</code>';
            html += '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#1e40af;">Mostra contenuto script SQL</summary>';
            html += '<pre style="background:#f9fafb;padding:10px;border:1px solid #ddd;font-size:0.8rem;overflow-x:auto;margin-top:8px;">-- Aggiungi metodo_pagamento se non esiste\n';
            html += 'ALTER TABLE fatture ADD metodo_pagamento VARCHAR(50) NULL AFTER data_pagamento;</pre></details>';
            html += '</div>';
        }
        
        html += '<div class="alert success">✅ Connessione FIC: OK</div>';
        html += '<div style="background:white;padding:12px;border-radius:4px;margin:12px 0;border-left:3px solid #3b82f6;">';
        html += '<strong>Company ID:</strong> ' + data.company_id + '<br>';
        if (data.account_info) {
            html += '<strong>Azienda:</strong> ' + (data.account_info.company_name || 'N/A') + '<br>';
            html += '<strong>Email:</strong> ' + (data.account_info.company_email || 'N/A');
        }
        html += '</div>';
        
        html += '<h4 style="margin:16px 0 12px 0;">Fatture verificate: ' + data.fatture_checked + '</h4>';
        
        if (data.results && data.results.length > 0) {
            data.results.forEach(function(r) {
                html += '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:12px;margin-bottom:10px;background:white;">';
                html += '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px;">';
                html += '<div><strong>' + r.numero + '</strong> · ' + r.cliente + ' <span class="muted">(ID: ' + r.id + ')</span><br>';
                html += '<span class="muted" style="font-size:0.85rem;">Totale: €' + r.totale + '</span></div>';
                html += '</div>';
                
                html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px;font-size:0.85rem;">';
                html += '<div><strong>FIC Doc ID:</strong> ' + (r.fic_document_id || '<span class="muted">mancante</span>') + '</div>';
                html += '<div><strong>FIC Status:</strong> ' + (r.fic_status || '<span class="muted">-</span>') + '</div>';
                html += '<div><strong>Mail Status:</strong> ' + (r.mail_status || '<span class="muted">-</span>') + '</div>';
                html += '<div><strong>Email:</strong> ' + (r.has_email ? '✓' : '✗') + '</div>';
                html += '<div><strong>P.IVA:</strong> ' + (r.has_piva ? '✓' : '✗') + '</div>';
                html += '</div>';
                
                if (r.test) {
                    if (r.test.ok) {
                        html += '<div class="alert success" style="margin-top:10px;padding:10px;font-size:0.9rem;">✅ ' + r.test.message;
                        if (r.test.created_fic_document_id) {
                            html += '<br><strong>FIC Document ID creato:</strong> ' + r.test.created_fic_document_id;
                            html += '<br><strong>Status:</strong> ' + (r.test.status || '-');
                        }
                        html += '</div>';
                    } else {
                        html += '<div class="alert error" style="margin-top:10px;padding:10px;font-size:0.9rem;">❌ ' + (r.test.error || r.test.message);
                        if (r.test.trace && r.test.trace.length > 0) {
                            html += '<details style="margin-top:8px;"><summary style="cursor:pointer;font-size:0.85rem;">Stack trace</summary>';
                            html += '<pre style="background:#fefefe;padding:8px;border:1px solid #ddd;font-size:0.75rem;margin-top:6px;overflow-x:auto;max-height:200px;">';
                            html += r.test.trace.join('\n');
                            html += '</pre></details>';
                        }
                        html += '</div>';
                    }
                }
                
                html += '</div>';
            });
        }
        
        contentDiv.innerHTML = html;
    })
    .catch(error => {
        contentDiv.innerHTML = '<div class="alert error">❌ Errore di rete: ' + error.message + '</div>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Esegui Test Sincronizzazione FIC';
    });
});
</script>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
