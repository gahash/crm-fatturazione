<?php
require __DIR__ . '/../auth.php';
require_login();
$user = current_user();
if (!has_admin_access()) {
    header('Location: ../modules/clienti/clienti.php');
    exit;
}
$appConfig = require __DIR__ . '/../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? '', '/');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Monitor â€” CRM Fatturazione</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }

        .topbar { background: #1e293b; border-bottom: 1px solid #334155; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; }
        .topbar h1 { font-size: 18px; font-weight: 700; color: #38bdf8; }
        .topbar .user-info { font-size: 13px; color: #94a3b8; }
        .topbar a { color: #94a3b8; text-decoration: none; margin-left: 16px; }
        .topbar a:hover { color: #f1f5f9; }

        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .kpi-card .label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 4px; }
        .kpi-card .value { font-size: 32px; font-weight: 700; color: #f1f5f9; }
        .kpi-card .value.green { color: #4ade80; }
        .kpi-card .value.blue { color: #38bdf8; }
        .kpi-card .value.yellow { color: #facc15; }

        .panel { background: #1e293b; border: 1px solid #334155; border-radius: 12px; margin-bottom: 24px; overflow: hidden; }
        .panel-header { padding: 16px 20px; border-bottom: 1px solid #334155; display: flex; align-items: center; justify-content: space-between; }
        .panel-header h2 { font-size: 15px; font-weight: 600; color: #f1f5f9; }
        .panel-header .controls { display: flex; gap: 8px; align-items: center; }
        .panel-header select, .panel-header input { background: #0f172a; border: 1px solid #475569; border-radius: 6px; color: #e2e8f0; padding: 5px 10px; font-size: 13px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; padding: 10px 16px; border-bottom: 1px solid #334155; }
        td { padding: 10px 16px; font-size: 13px; border-bottom: 1px solid rgba(51,65,85,0.5); }
        tr:hover td { background: rgba(56,189,248,0.04); }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
        .badge-green { background: rgba(74,222,128,0.15); color: #4ade80; }
        .badge-blue { background: rgba(56,189,248,0.15); color: #38bdf8; }
        .badge-red { background: rgba(248,113,113,0.15); color: #f87171; }
        .badge-yellow { background: rgba(250,204,21,0.15); color: #facc15; }
        .badge-gray { background: rgba(148,163,184,0.15); color: #94a3b8; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s; }
        .btn-danger { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover { background: rgba(239,68,68,0.25); }
        .btn-primary { background: rgba(56,189,248,0.15); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3); }
        .btn-primary:hover { background: rgba(56,189,248,0.25); }

        .tabs { display: flex; gap: 0; margin-bottom: 24px; }
        .tab { padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; color: #94a3b8; transition: all 0.15s; }
        .tab:hover { color: #e2e8f0; }
        .tab.active { color: #38bdf8; border-bottom-color: #38bdf8; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .refresh-btn { background: none; border: 1px solid #475569; color: #94a3b8; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .refresh-btn:hover { border-color: #38bdf8; color: #38bdf8; }

        .page-path { max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .time-ago { color: #64748b; font-size: 12px; }

        .section-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; margin: 28px 0 12px; padding-left: 4px; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 14px 16px; }
        .stat-card .stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: #f1f5f9; }
        .stat-card .stat-value.green { color: #4ade80; }
        .stat-card .stat-value.blue { color: #38bdf8; }
        .stat-card .stat-value.red { color: #f87171; }
        .stat-card .stat-value.yellow { color: #facc15; }
        .stat-card .stat-value.purple { color: #c084fc; }
        .stat-card .stat-sub { font-size: 11px; color: #475569; margin-top: 2px; }

        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .status-dot.on { background: #4ade80; box-shadow: 0 0 6px rgba(74,222,128,0.5); }
        .status-dot.off { background: #f87171; box-shadow: 0 0 6px rgba(248,113,113,0.5); }
        .status-dot.warn { background: #facc15; box-shadow: 0 0 6px rgba(250,204,21,0.5); }
    </style>
</head>
<body>

<div class="topbar">
    <h1>&#128737; Admin Monitor</h1>
    <div>
        <span class="user-info"><?= htmlspecialchars($user['nome']) ?> (admin)</span>
        <a href="<?= htmlspecialchars($baseUrl) ?>/modules/dashboard/index.php">â† Gestionale</a>
        <a href="<?= htmlspecialchars($baseUrl) ?>/logout.php">Esci</a>
    </div>
</div>

<div class="container">
    <div class="kpi-grid">
        <div class="kpi-card"><div class="label">Sessioni Attive</div><div class="value green" id="kpi-sessions">--</div></div>
        <div class="kpi-card"><div class="label">Login Oggi</div><div class="value blue" id="kpi-logins">--</div></div>
        <div class="kpi-card"><div class="label">Visite Oggi</div><div class="value yellow" id="kpi-views">--</div></div>
        <div class="kpi-card"><div class="label">Utenti Attivi</div><div class="value" id="kpi-users">--</div></div>
    </div>

    <div class="tabs">
        <div class="tab active" data-tab="sessions">Sessioni Live</div>
        <div class="tab" data-tab="integrations">Integrazioni</div>
        <div class="tab" data-tab="activity">Attivit&agrave; Recente</div>
    </div>

    <div class="tab-content active" id="tab-sessions">
        <div class="panel">
            <div class="panel-header">
                <h2>Sessioni Attive</h2>
                <div class="controls">
                    <select id="session-minutes">
                        <option value="15">Ultimi 15 min</option>
                        <option value="30" selected>Ultimi 30 min</option>
                        <option value="60">Ultima ora</option>
                        <option value="1440">Ultime 24h</option>
                    </select>
                    <button class="refresh-btn" onclick="loadSessions()">&#8635; Aggiorna</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Utente</th><th>Email</th><th>Pagina Corrente</th><th>IP</th><th>Ultimo Accesso</th><th>Azioni</th></tr>
                </thead>
                <tbody id="sessions-body">
                    <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- INTEGRATIONS TAB -->
    <div class="tab-content" id="tab-integrations">

        <!-- STRIPE -->
        <div class="section-title">&#9889; Stripe</div>
        <div class="stat-grid" id="stripe-stats">
            <div class="stat-card"><div class="stat-label">Pagamenti Totali</div><div class="stat-value" id="st-total">--</div></div>
            <div class="stat-card"><div class="stat-label">Succeeded</div><div class="stat-value green" id="st-ok">--</div></div>
            <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value yellow" id="st-pending">--</div></div>
            <div class="stat-card"><div class="stat-label">Failed</div><div class="stat-value red" id="st-failed">--</div></div>
            <div class="stat-card"><div class="stat-label">Refunded</div><div class="stat-value purple" id="st-refunded">--</div></div>
            <div class="stat-card"><div class="stat-label">Volume Totale</div><div class="stat-value blue" id="st-volume">--</div></div>
            <div class="stat-card"><div class="stat-label">Clienti Stripe</div><div class="stat-value" id="st-clients">--</div></div>
            <div class="stat-card"><div class="stat-label">Pagamenti Oggi</div><div class="stat-value green" id="st-today">--</div></div>
        </div>
        <div class="panel" id="stripe-sync-panel">
            <div class="panel-header"><h2>Ultimo Sync Stripe</h2></div>
            <div style="padding:16px 20px;font-size:13px;" id="stripe-sync-info">Caricamento...</div>
        </div>


        <!-- FIC -->
        <div class="section-title">&#128196; Fatture in Cloud</div>
        <div class="stat-grid" id="fic-stats">
            <div class="stat-card"><div class="stat-label">Fatture Totali</div><div class="stat-value" id="fic-total">--</div></div>
            <div class="stat-card"><div class="stat-label">Inviate a FIC</div><div class="stat-value green" id="fic-ok">--</div><div class="stat-sub" id="fic-ok-pct"></div></div>
            <div class="stat-card"><div class="stat-label">Senza FIC</div><div class="stat-value red" id="fic-missing">--</div></div>
            <div class="stat-card"><div class="stat-label">FIC OK</div><div class="stat-value green" id="fic-st-ok">--</div></div>
            <div class="stat-card"><div class="stat-label">FIC Pending</div><div class="stat-value yellow" id="fic-st-pending">--</div></div>
            <div class="stat-card"><div class="stat-label">FIC Errore</div><div class="stat-value red" id="fic-st-error">--</div></div>
            <div class="stat-card"><div class="stat-label">FIC Non Sync</div><div class="stat-value" id="fic-st-null">--</div></div>
            <div class="stat-card">
                <div class="stat-label">Auto-Invio</div>
                <div class="stat-value" id="fic-autosend">--</div>
                <div class="stat-sub" id="fic-autosend-mode"></div>
            </div>
        </div>

        <!-- FIC ERROR PANEL -->
        <div class="panel">
            <div class="panel-header">
                <h2>Errori Fatture in Cloud</h2>
                <button class="refresh-btn" onclick="loadFicErrorLog()">&#8635; Aggiorna</button>
            </div>
            <table>
                <thead><tr><th>Data/Ora</th><th>Fattura</th><th>Utente</th><th>Messaggio</th></tr></thead>
                <tbody id="fic-error-log-body">
                    <tr><td colspan="4" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- EMAIL -->
        <div class="section-title">&#9993; Email Fatture</div>
        <div class="stat-grid" id="mail-stats">
            <div class="stat-card"><div class="stat-label">Inviate</div><div class="stat-value green" id="mail-sent">--</div></div>
            <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value yellow" id="mail-pending">--</div></div>
            <div class="stat-card"><div class="stat-label">Errore</div><div class="stat-value red" id="mail-error">--</div></div>
            <div class="stat-card"><div class="stat-label">Non Inviate</div><div class="stat-value" id="mail-none">--</div></div>
            <div class="stat-card"><div class="stat-label">Ultima Email</div><div class="stat-value blue" id="mail-last" style="font-size:14px;">--</div></div>
        </div>

            <!-- EMAIL LOG PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Storico Invio Email</h2>
                    <button class="refresh-btn" onclick="loadMailLog()">&#8635; Aggiorna</button>
                </div>
                <table>
                    <thead><tr><th>Data/Ora</th><th>Destinatario</th><th>Oggetto</th><th>Stato</th><th>Esito</th><th>Azioni</th></tr></thead>
                    <tbody id="mail-log-body">
                        <tr><td colspan="6" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                    </tbody>
                </table>
            </div>

                <!-- EMAIL INBOX PANEL -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Casella Posta in Arrivo</h2>
                        <button class="refresh-btn" onclick="loadInbox()">&#8635; Aggiorna</button>
                    </div>
                    <table>
                        <thead><tr><th>Data/Ora</th><th>Mittente</th><th>Oggetto</th></tr></thead>
                        <tbody id="inbox-body">
                            <tr><td colspan="3" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- EMAIL SEND PANEL -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Invia Nuova Email</h2>
                    </div>
                    <form id="send-mail-form" style="padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
                        <input type="email" id="mail-to" placeholder="Destinatario" required style="flex:1;min-width:180px;">
                        <input type="text" id="mail-subject" placeholder="Oggetto" required style="flex:2;min-width:180px;">
                        <textarea id="mail-body" placeholder="Testo email" required style="flex:3;min-width:220px;height:60px;"></textarea>
                        <button type="submit" class="btn btn-primary">Invia</button>
                        <span id="send-mail-status" style="margin-left:12px;font-size:13px;"></span>
                    </form>
                </div>

        <!-- SEZIONE: FATTURE NON INVIATE -->
        <div class="panel">
            <div class="panel-header">
                <h2>Fatture non inviate via mail</h2>
                <button class="refresh-btn" onclick="loadFattureNonInviate()">&#8635; Aggiorna</button>
            </div>
            <table>
                <thead><tr><th>Numero</th><th>Data</th><th>Cliente</th><th>Email</th><th>Oggetto</th><th>Stato</th><th>Azioni</th></tr></thead>
                <tbody id="fatture-non-inviate-body">
                    <tr><td colspan="7" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- MODALE INVIO/RINVIO MAIL FATTURA -->
        <div id="modal-invia-mail" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.85);z-index:1000;align-items:center;justify-content:center;">
            <div style="background:#1e293b;padding:32px 28px;border-radius:12px;min-width:320px;max-width:95vw;box-shadow:0 8px 32px #0008;">
                <h3 style="color:#38bdf8;font-size:18px;margin-bottom:16px;">Invia Mail Fattura</h3>
                <form id="form-invia-mail-fattura">
                    <input type="hidden" id="fattura-id">
                    <div style="margin-bottom:10px;"><label>Destinatario:<br><input type="email" id="fattura-mail-to" style="width:100%;padding:6px;" required></label></div>
                    <div style="margin-bottom:10px;"><label>Oggetto:<br><input type="text" id="fattura-mail-subject" style="width:100%;padding:6px;" required></label></div>
                    <div style="margin-bottom:10px;"><label>Testo:<br><textarea id="fattura-mail-body" style="width:100%;padding:6px;min-height:60px;" required></textarea></label></div>
                    <button type="submit" class="btn btn-primary">Invia</button>
                    <button type="button" class="btn" onclick="closeModalInviaMail()">Annulla</button>
                    <span id="invia-mail-status" style="margin-left:12px;font-size:13px;"></span>
                </form>
            </div>
        </div>

        <!-- FATTURE LOG -->
        <div class="panel">
            <div class="panel-header">
                <h2>Operazioni Fatture Recenti</h2>
                <button class="refresh-btn" onclick="loadIntegrations()">&#8635; Aggiorna</button>
            </div>
            <table>
                <thead><tr><th>Data/Ora</th><th>Utente</th><th>Azione</th><th>Esito</th><th>Messaggio</th></tr></thead>
                <tbody id="fatture-log-body">
                    <tr><td colspan="5" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- CRON LOG -->
        <div class="panel">
            <div class="panel-header"><h2>Cron Jobs Recenti</h2></div>
            <table>
                <thead><tr><th>Job</th><th>Stato</th><th>Inizio</th><th>Fine</th><th>Importati</th><th>Fatture Create</th><th>Clienti Creati</th><th>Errori</th></tr></thead>
                <tbody id="cron-log-body">
                    <tr><td colspan="8" style="text-align:center;padding:16px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-content" id="tab-activity">
        <div class="panel">
            <div class="panel-header">
                <h2>Log Attivit&agrave;</h2>
                <div class="controls">
                    <select id="activity-limit">
                        <option value="50">Ultime 50</option>
                        <option value="100" selected>Ultime 100</option>
                        <option value="200">Ultime 200</option>
                        <option value="500">Ultime 500</option>
                    </select>
                    <button class="refresh-btn" onclick="loadActivity()">&#8635; Aggiorna</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Data/Ora</th><th>Utente</th><th>Ruolo</th><th>Azione</th><th>Pagina</th><th>IP</th></tr>
                </thead>
                <tbody id="activity-body">
                    <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748b;">Caricamento...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="monitor.js"></script>
</body>
</html>

