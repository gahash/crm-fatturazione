<?php
require_once __DIR__ . '/../auth.php';
$config = require __DIR__ . '/../config/config.php';
$user = current_user();
$topbarFattureAlerts = [
    'issue_count' => 0,
    'in_progress_count' => 0,
    'issue_buckets' => [],
    'issue_rows' => [],
    'in_progress_rows' => [],
];

function tdg_topbar_alerts_cache_path(): string
{
    return __DIR__ . '/../tmp/topbar_fatture_alerts_summary.json';
}

function tdg_load_cached_topbar_alerts(int $ttlSeconds = 90): ?array
{
    $cachePath = tdg_topbar_alerts_cache_path();
    if (!is_file($cachePath)) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $cachedAt = (int)($decoded['_cached_at'] ?? 0);
    if ($cachedAt <= 0 || (time() - $cachedAt) > $ttlSeconds) {
        return null;
    }

    unset($decoded['_cached_at']);
    return $decoded;
}

function tdg_store_cached_topbar_alerts(array $alerts): void
{
    $cachePath = tdg_topbar_alerts_cache_path();
    $payload = $alerts;
    $payload['_cached_at'] = time();
    @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function tdg_topbar_alerts_summary_text(array $alerts): string
{
    $issueCount = (int)($alerts['issue_count'] ?? 0);
    $inProgressCount = (int)($alerts['in_progress_count'] ?? 0);

    if ($issueCount > 0 && $inProgressCount > 0) {
        return number_format($issueCount, 0, ',', '.') . ' SDI bloccati o incompleti, '
            . number_format($inProgressCount, 0, ',', '.') . ' in corso';
    }

    if ($issueCount > 0) {
        return number_format($issueCount, 0, ',', '.') . ' SDI bloccati o incompleti';
    }

    if ($inProgressCount > 0) {
        return number_format($inProgressCount, 0, ',', '.') . ' invii SDI in corso';
    }

    return 'Nessuna criticita SDI';
}

function tdg_topbar_alerts_primary_issue_label(array $alerts): string
{
    $issueBuckets = $alerts['issue_buckets'] ?? [];
    if (!is_array($issueBuckets) || empty($issueBuckets[0]['reason_bucket'])) {
        return '';
    }

    return trim((string)$issueBuckets[0]['reason_bucket']);
}

function tdg_topbar_alerts_label_text(array $alerts): string
{
    $summary = tdg_topbar_alerts_summary_text($alerts);
    $issueCount = (int)($alerts['issue_count'] ?? 0);
    $primaryReason = tdg_topbar_alerts_primary_issue_label($alerts);

    if ($issueCount > 0 && $primaryReason !== '') {
        return $summary . ' · principale: ' . $primaryReason;
    }

    return $summary;
}

function tdg_topbar_alerts_compact_text(array $alerts): string
{
    $issueCount = (int)($alerts['issue_count'] ?? 0);
    $inProgressCount = (int)($alerts['in_progress_count'] ?? 0);

    if ($issueCount > 0 && $inProgressCount > 0) {
        return number_format($issueCount, 0, ',', '.') . ' blocchi · '
            . number_format($inProgressCount, 0, ',', '.') . ' in corso';
    }

    if ($issueCount > 0) {
        return number_format($issueCount, 0, ',', '.') . ' blocchi SDI';
    }

    if ($inProgressCount > 0) {
        return number_format($inProgressCount, 0, ',', '.') . ' in corso';
    }

    return 'Nessuna criticita';
}

if ($user && has_admin_access()) {
    try {
        $cachedAlerts = tdg_load_cached_topbar_alerts();
        if (is_array($cachedAlerts)) {
            $topbarFattureAlerts = $cachedAlerts;
        } else {
            require_once __DIR__ . '/../modules/fatture/actions/fic_alert_helpers.php';
            $topbarPdo = require __DIR__ . '/../config/db.php';
            $topbarFattureAlerts = fatture_load_topbar_alerts($topbarPdo, 'invoice', 0, 0);
            tdg_store_cached_topbar_alerts($topbarFattureAlerts);
        }
    } catch (Throwable $e) {
        $topbarFattureAlerts = [
            'issue_count' => 0,
            'in_progress_count' => 0,
            'issue_buckets' => [],
            'issue_rows' => [],
            'in_progress_rows' => [],
        ];
    }
}

$topbarAlertTotal = (int)($topbarFattureAlerts['issue_count'] ?? 0) + (int)($topbarFattureAlerts['in_progress_count'] ?? 0);
$topbarIssuesUrl = $config['app']['base_url'] . '/modules/fatture/index.php?sdi_flow=issues&page=1';
$topbarSentUrl = $config['app']['base_url'] . '/modules/fatture/index.php?sdi_flow=sent&page=1';
$topbarExportUrl = $config['app']['base_url'] . '/modules/fatture/actions/fic_export_delivery_report.php?sdi_flow=issues';
$topbarClientIssuesUrl = $config['app']['base_url'] . '/modules/clienti/clienti.php?sdi_alert=1&page=1';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRM Fatturazione</title>
    <link rel="stylesheet" href="<?php echo $config['app']['base_url']; ?>/modules/clienti/css/clienti.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $config['app']['base_url']; ?>/modules/fatture/css/fatture.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $config['app']['base_url']; ?>/modali/modal.css">
    <script src="<?php echo $config['app']['base_url']; ?>/modali/modal.js" defer></script>
    <script src="<?php echo $config['app']['base_url']; ?>/modali/menu.js" defer></script>

    <style>
        .app-header {
            background-color: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 0;
        }
        .app-header a {
            color: #1f2937;
            text-decoration: none;
            font-weight: 500;
        }
        .app-header a:hover {
            color: #2596be;
        }
        .user-menu__btn {
            color: #1f2937;
            cursor: pointer;
            background: none;
            border: none;
            font-size: inherit;
        }
        .user-menu__btn:hover {
            color: #2596be;
        }
        .user-menu__name {
            color: #1f2937;
        }
        .user-menu__avatar {
            background-color: #2596be;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 14px;
        }
        .user-menu__dropdown {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px 0;
            min-width: 180px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .user-menu__dropdown a {
            display: block;
            padding: 10px 16px;
            color: #1f2937;
            text-decoration: none;
            font-weight: 400;
        }
        .user-menu__dropdown a:hover {
            background-color: #f3f4f6;
            color: #2596be;
        }
        .topbar-alerts {
            position: relative;
        }
        .topbar-alerts[open] summary {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .topbar-alerts summary {
            list-style: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 8px 12px;
            border: 1px solid #dbe4ea;
            border-radius: 999px;
            background: #fff;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .topbar-alerts summary::-webkit-details-marker {
            display: none;
        }
        .topbar-alerts__icon {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            color: #1d4ed8;
            font-size: 16px;
            line-height: 1;
        }
        .topbar-alerts__dot {
            position: absolute;
            top: -5px;
            right: -7px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 2px #fff;
        }
        .topbar-alerts__label {
            display: flex;
            flex-direction: column;
            gap: 2px;
            line-height: 1.1;
        }
        .topbar-alerts__label strong {
            font-size: 13px;
            color: #0f172a;
        }
        .topbar-alerts__label span {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        .topbar-alerts__text--compact {
            display: none;
        }
        .topbar-alerts__panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(520px, calc(100vw - 24px));
            max-height: 78vh;
            overflow: auto;
            background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
            border: 1px solid #dbe4ea;
            border-radius: 18px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.18);
            padding: 14px;
            z-index: 220;
        }
        .topbar-alerts__panel a {
            color: inherit;
        }
        .topbar-alerts__hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding: 6px 4px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .topbar-alerts__hero strong {
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
            color: #0f172a;
        }
        .topbar-alerts__hero p {
            margin: 0;
            font-size: 12px;
            color: #475569;
            line-height: 1.4;
        }
        .topbar-alerts__hero-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 44px;
            border-radius: 14px;
            background: #fee2e2;
            color: #b91c1c;
            font-weight: 800;
            font-size: 18px;
            padding: 0 12px;
        }
        .topbar-alerts__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .topbar-alerts__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #dbe4ea;
            background: #fff;
            font-size: 12px;
            font-weight: 600;
            color: #0f172a !important;
            -webkit-text-fill-color: #0f172a;
            text-decoration: none;
            opacity: 1;
            visibility: visible;
        }
        .topbar-alerts__action:visited,
        .topbar-alerts__action:hover,
        .topbar-alerts__action:focus,
        .topbar-alerts__action:active {
            color: #0f172a !important;
            -webkit-text-fill-color: #0f172a;
            text-decoration: none;
        }
        .topbar-alerts__action:hover,
        .topbar-alerts__action:focus {
            border-color: #93c5fd;
            background: #eff6ff;
            outline: none;
        }
        .topbar-alerts__section {
            margin-top: 14px;
            padding: 12px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
        }
        .topbar-alerts__section--danger {
            background: linear-gradient(180deg, #fff7f7 0%, #fff 100%);
            border-color: #fecaca;
        }
        .topbar-alerts__section--warning {
            background: linear-gradient(180deg, #fffbeb 0%, #fff 100%);
            border-color: #fde68a;
        }
        .topbar-alerts__section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .topbar-alerts__section-head strong {
            font-size: 13px;
            color: #0f172a;
        }
        .topbar-alerts__section-head span {
            font-size: 11px;
            color: #64748b;
        }
        .topbar-alerts__pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            background: #0f172a;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }
        .topbar-alerts__bucket-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .topbar-alerts__bucket {
            padding: 10px 12px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .topbar-alerts__bucket strong {
            display: block;
            font-size: 15px;
            color: #991b1b;
        }
        .topbar-alerts__bucket span {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            color: #475569;
            line-height: 1.35;
        }
        .topbar-alerts__list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .topbar-alerts__item {
            padding: 10px 12px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .topbar-alerts__item-title {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: baseline;
            margin-bottom: 5px;
            font-size: 12px;
            color: #334155;
        }
        .topbar-alerts__item-title strong {
            font-size: 13px;
            color: #0f172a;
        }
        .topbar-alerts__item-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .topbar-alerts__item-badge--warning {
            background: #fef3c7;
            color: #92400e;
        }
        .topbar-alerts__item-reason {
            font-size: 12px;
            line-height: 1.45;
            color: #475569;
        }
        .topbar-alerts__empty {
            padding: 16px 14px;
            border-radius: 16px;
            background: linear-gradient(180deg, #f0fdf4 0%, #fff 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
            font-size: 13px;
            line-height: 1.5;
            margin-top: 14px;
        }
        @media (max-width: 960px) {
            .topbar-alerts__label {
                display: flex;
                max-width: 148px;
            }
            .topbar-alerts__text--full {
                display: none;
            }
            .topbar-alerts__text--compact {
                display: inline;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .topbar-alerts__panel {
                right: -36px;
            }
        }
        @media (max-width: 640px) {
            .topbar-alerts summary {
                gap: 8px;
                padding: 7px 10px;
            }
            .topbar-alerts__label {
                max-width: 120px;
                gap: 1px;
            }
            .topbar-alerts__label strong {
                font-size: 12px;
            }
            .topbar-alerts__label span {
                font-size: 10px;
            }
        }
        @media (max-width: 640px) {
            .topbar-alerts {
                order: 2;
            }
            .topbar-alerts__panel {
                position: fixed;
                top: 72px;
                right: 12px;
                left: 12px;
                width: auto;
            }
            .topbar-alerts__bucket-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<header class="app-header">
    <div class="wrap" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <img src="<?php echo $config['app']['base_url']; ?>/assets/brand/logo.svg" alt="CRM Fatturazione" style="height:36px;width:auto;">
        </div>
        <nav style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
            <?php if ($user && has_admin_access()): ?>
                <a href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/index.php">Dashboard</a>
            <?php endif; ?>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/lead/index.php">Lead</a>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/clienti/clienti.php">Clienti</a>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/fatture/index.php">Fatture</a>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/ordini/index.php">Ordini</a>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/preventivi/index.php">Preventivi</a>
            <div class="nav-pag-wrap" style="position:relative;display:inline-flex;align-items:center;gap:0;">
                <a href="<?php echo $config['app']['base_url']; ?>/modules/pagamenti/index.php">Pagamenti</a>
                <button type="button" class="nav-pag-toggle" onclick="this.parentElement.classList.toggle('open')" style="background:none;border:none;cursor:pointer;padding:2px 6px;font-size:16px;color:#1f2937;line-height:1;">⏷</button>
                <div class="nav-pag-menu" style="display:none;position:absolute;top:100%;left:0;margin-top:6px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:200px;z-index:100;padding:4px 0;">
                    <a href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/transazioni.php?tipo=pagamenti" style="display:block;padding:10px 14px;font-size:14px;">📊 Transazioni Stripe</a>
                    <a href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/transazioni.php?tipo=fatture" style="display:block;padding:10px 14px;font-size:14px;">📄 Transazioni Fatture</a>
                </div>
            </div>
            <style>
            .nav-pag-wrap.open .nav-pag-menu{display:block!important}
            .nav-pag-toggle:hover{color:#2596be}
            .nav-pag-menu a:hover{background:#f3f4f6;color:#2596be}
            </style>
            <script>
            document.addEventListener('click',function(e){
                document.querySelectorAll('.nav-pag-wrap.open').forEach(function(el){
                    if(!el.contains(e.target)) el.classList.remove('open');
                });
                document.querySelectorAll('.topbar-alerts[open]').forEach(function(el){
                    if(!el.contains(e.target)) el.removeAttribute('open');
                });
            });
            </script>
            <?php if ($user && has_admin_access()): ?>
                <a href="<?php echo $config['app']['base_url']; ?>/modules/connect/index.php">Connect</a>
            <?php endif; ?>
            <a href="<?php echo $config['app']['base_url']; ?>/modules/scadenziario/index.php">Scadenziario</a>
            <?php if ($user && has_admin_access()): ?>
                <details class="topbar-alerts" id="topbarAlerts">
                    <summary aria-haspopup="menu" aria-controls="topbarAlertsPanel">
                        <span class="topbar-alerts__icon" aria-hidden="true">
                            &#128276;
                            <?php if ($topbarAlertTotal > 0): ?>
                                <span class="topbar-alerts__dot"><?php echo $topbarAlertTotal > 99 ? '99+' : (int)$topbarAlertTotal; ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="topbar-alerts__label">
                            <strong>Alert SDI</strong>
                            <span class="topbar-alerts__text--full"><?php echo htmlspecialchars(tdg_topbar_alerts_label_text($topbarFattureAlerts)); ?></span>
                            <span class="topbar-alerts__text--compact"><?php echo htmlspecialchars(tdg_topbar_alerts_compact_text($topbarFattureAlerts)); ?></span>
                        </span>
                    </summary>
                    <div class="topbar-alerts__panel" id="topbarAlertsPanel" role="menu">
                        <div class="topbar-alerts__hero">
                            <div>
                                <strong>Controllo SDI e Fatture in Cloud</strong>
                                <p>
                                    <?php if ($topbarAlertTotal > 0): ?>
                                        Il progetto salva i motivi in ei_error, mail_error e nei log. Qui trovi il riepilogo operativo delle criticita aperte.
                                    <?php else: ?>
                                        Nessuna criticita aperta: gli invii SDI bloccati o in corso non richiedono azioni immediate.
                                    <?php endif; ?>
                                </p>
                                <div class="topbar-alerts__actions">
                                    <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarIssuesUrl); ?>">Apri solo criticita SDI</a>
                                    <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarExportUrl); ?>">Esporta report criticita</a>
                                    <?php if ((int)($topbarFattureAlerts['in_progress_count'] ?? 0) > 0): ?>
                                        <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarSentUrl); ?>">Apri invii SDI in corso</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="topbar-alerts__hero-badge"><?php echo $topbarAlertTotal; ?></span>
                        </div>

                        <?php if ($topbarAlertTotal > 0): ?>
                            <section class="topbar-alerts__section topbar-alerts__section--danger">
                                <div class="topbar-alerts__section-head">
                                    <div>
                                        <strong>Riepilogo notifiche operative</strong>
                                        <span>La top bar resta sintetica. Dettagli, ultimi casi e filtri stanno nella pagina Clienti; le azioni tecniche restano in Fatture.</span>
                                    </div>
                                    <span class="topbar-alerts__pill"><?php echo $topbarAlertTotal; ?></span>
                                </div>

                                <div class="topbar-alerts__bucket-grid">
                                    <div class="topbar-alerts__bucket">
                                        <strong><?php echo number_format((int)($topbarFattureAlerts['issue_count'] ?? 0), 0, ',', '.'); ?></strong>
                                        <span>fatture con invio SDI bloccato o incompleto</span>
                                    </div>
                                    <div class="topbar-alerts__bucket">
                                        <strong><?php echo number_format((int)($topbarFattureAlerts['in_progress_count'] ?? 0), 0, ',', '.'); ?></strong>
                                        <span>fatture con invio SDI gia in lavorazione</span>
                                    </div>
                                </div>

                                <div class="topbar-alerts__actions">
                                    <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarClientIssuesUrl); ?>">Apri clienti bloccati</a>
                                    <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarIssuesUrl); ?>">Apri criticita SDI</a>
                                    <?php if ((int)($topbarFattureAlerts['in_progress_count'] ?? 0) > 0): ?>
                                        <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarSentUrl); ?>">Apri invii SDI in corso</a>
                                    <?php endif; ?>
                                    <a class="topbar-alerts__action" href="<?php echo htmlspecialchars($topbarExportUrl); ?>">Esporta report criticita</a>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($topbarAlertTotal <= 0): ?>
                            <div class="topbar-alerts__empty">
                                Tutte le fatture monitorate risultano senza criticita SDI aperte. La campanella continuera a segnalare duplicati FIC, invii bloccati e tentativi in corso appena compaiono.
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
            <?php if ($user): ?>
                <?php
                $name = $user['nome'] ?? '';
                $initials = trim(preg_replace('/\s+/', ' ', $name));
                $initials = $initials !== '' ? strtoupper(substr($initials, 0, 1)) : 'U';
                ?>
                <details class="user-menu" id="userMenu">
                    <summary class="user-menu__btn" aria-haspopup="menu" aria-controls="userMenuDropdown">
                        <span class="user-menu__avatar"><?php echo htmlspecialchars($initials); ?></span>
                        <span class="user-menu__name"><?php echo htmlspecialchars($user['nome']); ?></span>
                        <span class="user-menu__caret" aria-hidden="true">â–¾</span>
                    </summary>
                    <div class="user-menu__dropdown" id="userMenuDropdown" role="menu">
                        <?php if (has_admin_access()): ?>
                            <a href="<?php echo $config['app']['base_url']; ?>/modules/utenti/index.php">Utenti</a>
                            <a href="<?php echo $config['app']['base_url']; ?>/modules/impostazioni/index.php">Impostazioni</a>
                        <?php endif; ?>
                        <a href="<?php echo $config['app']['base_url']; ?>/logout.php">Logout</a>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="app-main">


