<?php
$q = $q ?? '';
$autoSendFilter = $autoSendFilter ?? '';
$sdiFlow = $sdiFlow ?? '';
$sdiSentCount = isset($sdiSentCount) ? (int)$sdiSentCount : 0;
$sdiSentCountLocal = isset($sdiSentCountLocal) ? (int)$sdiSentCountLocal : $sdiSentCount;
$sdiCountSource = isset($sdiCountSource) ? (string)$sdiCountSource : 'local';
$sdiCountError = isset($sdiCountError) ? (string)$sdiCountError : null;
$sdiCountCachedAt = isset($sdiCountCachedAt) ? (int)$sdiCountCachedAt : null;
$ficLinkedCount = isset($ficLinkedCount) ? (int)$ficLinkedCount : 0;
$ficLinkedCountLocal = isset($ficLinkedCountLocal) ? (int)$ficLinkedCountLocal : $ficLinkedCount;
$ficLinkedMissingCount = isset($ficLinkedMissingCount) ? (int)$ficLinkedMissingCount : 0;
$ficLinkedCountSource = isset($ficLinkedCountSource) ? (string)$ficLinkedCountSource : 'local';
$ficLinkedCountError = isset($ficLinkedCountError) ? (string)$ficLinkedCountError : null;
$ficLinkedCountCachedAt = isset($ficLinkedCountCachedAt) ? (int)$ficLinkedCountCachedAt : null;

$refreshSdiParams = $_GET;
$refreshSdiParams['refresh_sdi'] = '1';
$refreshSdiUrl = 'index.php?' . http_build_query($refreshSdiParams);
$refreshLinksParams = $_GET;
$refreshLinksParams['refresh_links'] = '1';
$refreshLinksUrl = 'index.php?' . http_build_query($refreshLinksParams);
$refreshAllUrl = 'index.php?' . http_build_query(array_merge($_GET, [
    'refresh_sdi' => '1',
    'refresh_links' => '1',
]));

require_once __DIR__ . '/../actions/fic_api.php';
$ficToken = fic_get_access_token();
$ficConnected = ($ficToken !== '');
$ficConnectionError = null;
if ($ficToken !== '' && ((isset($_GET['refresh_sdi']) && (string)$_GET['refresh_sdi'] === '1') || (isset($_GET['refresh_links']) && (string)$_GET['refresh_links'] === '1'))) {
    try {
        $ping = fic_request('GET', '/user/info');
        $ficConnected = (($ping['status'] ?? 0) >= 200 && ($ping['status'] ?? 0) < 300);
        if (!$ficConnected) {
            $ficConnectionError = 'HTTP ' . (int)($ping['status'] ?? 0);
        }
    } catch (Throwable $e) {
        $ficConnected = false;
        $ficConnectionError = substr((string)$e->getMessage(), 0, 140);
    }
}

$ficIssueCount = max(0, $ficLinkedMissingCount);
$sdiLocalDiff = abs($sdiSentCountLocal - $sdiSentCount);
$statusNote = $ficConnected ? 'Connessione attiva' : 'Connessione da verificare';
if ($ficConnected && $ficIssueCount > 0) {
    $statusNote = 'Da riallineare';
}
?>

<section class="card fatture-status-card">
    <div class="fatture-status-head">
        <div>
            <h3>Stato Fatture in Cloud</h3>
            <p class="muted"><?php echo htmlspecialchars($statusNote); ?></p>
        </div>
        <div class="fatture-status-actions">
            <span class="badge <?php echo $ficConnected ? 'ok' : 'ko'; ?>">
                <?php echo $ficConnected ? 'FIC connesso' : 'FIC non connesso'; ?>
            </span>
            <a class="btn secondary btn-sm" href="<?php echo htmlspecialchars($refreshAllUrl); ?>">Aggiorna stato</a>
            <a class="btn btn-sm" href="actions/fic_oauth_start.php?return=%2Fmodules%2Ffatture%2Findex.php">
                <?php echo $ficConnected ? 'Ricollega FIC' : 'Connetti FIC'; ?>
            </a>
        </div>
    </div>

    <div class="fatture-status-grid">
        <a class="fatture-status-metric" href="index.php?sdi_flow=sent">
            <span>Fatture SDI inviate</span>
            <strong><?php echo number_format($sdiSentCount, 0, ',', '.'); ?></strong>
            <small><?php echo $sdiCountSource === 'fic' ? 'conteggio FIC' : 'conteggio locale'; ?></small>
        </a>
        <div class="fatture-status-metric">
            <span>Fatture presenti su FIC</span>
            <strong><?php echo number_format($ficLinkedCount, 0, ',', '.'); ?></strong>
            <small><?php echo $ficLinkedCountSource === 'fic' ? 'raggiungibili' : 'locali'; ?></small>
        </div>
        <div class="fatture-status-metric <?php echo $ficIssueCount > 0 ? 'is-warning' : 'is-ok'; ?>">
            <span>Da riallineare</span>
            <strong><?php echo number_format($ficIssueCount, 0, ',', '.'); ?></strong>
            <small><?php echo $ficIssueCount > 0 ? 'usa Invia a FIC' : 'nessuna anomalia FIC'; ?></small>
        </div>
    </div>

    <?php if ($sdiLocalDiff > 0 || $sdiCountError || $ficLinkedCountError || $ficConnectionError): ?>
        <details class="fatture-status-details">
            <summary>Dettagli tecnici</summary>
            <div class="fatture-status-detail-grid">
                <?php if ($sdiLocalDiff > 0): ?>
                    <p>SDI FIC: <b><?php echo number_format($sdiSentCount, 0, ',', '.'); ?></b> - locale: <b><?php echo number_format($sdiSentCountLocal, 0, ',', '.'); ?></b>.</p>
                <?php endif; ?>
                <?php if ($ficLinkedCountSource === 'fic' && ($ficLinkedCountLocal !== $ficLinkedCount || $ficLinkedMissingCount > 0)): ?>
                    <p>FIC raggiungibili: <b><?php echo number_format($ficLinkedCount, 0, ',', '.'); ?></b> - locali: <b><?php echo number_format($ficLinkedCountLocal, 0, ',', '.'); ?></b> - non trovate: <b><?php echo number_format($ficLinkedMissingCount, 0, ',', '.'); ?></b>.</p>
                <?php endif; ?>
                <?php if ($sdiCountCachedAt): ?>
                    <p>Cache SDI: <?php echo date('d/m H:i', $sdiCountCachedAt); ?>.</p>
                <?php endif; ?>
                <?php if ($ficLinkedCountCachedAt): ?>
                    <p>Cache collegamenti FIC: <?php echo date('d/m H:i', $ficLinkedCountCachedAt); ?>.</p>
                <?php endif; ?>
                <?php if ($sdiCountError): ?>
                    <p>Conteggio SDI non disponibile: <?php echo htmlspecialchars($sdiCountError); ?>.</p>
                <?php endif; ?>
                <?php if ($ficLinkedCountError): ?>
                    <p>Verifica collegamenti non disponibile: <?php echo htmlspecialchars($ficLinkedCountError); ?>.</p>
                <?php endif; ?>
                <?php if ($ficConnectionError): ?>
                    <p>Connessione FIC: <?php echo htmlspecialchars($ficConnectionError); ?>.</p>
                <?php endif; ?>
                <p>
                    <a href="<?php echo htmlspecialchars($refreshSdiUrl); ?>">Aggiorna solo SDI</a>
                    <span> · </span>
                    <a href="<?php echo htmlspecialchars($refreshLinksUrl); ?>">Aggiorna solo collegamenti</a>
                </p>
            </div>
        </details>
    <?php endif; ?>
</section>

<form method="get" class="card fatture-filter-card">
    <div class="fatture-filter-head">
        <h3>Filtra fatture</h3>
        <div class="fatture-filter-actions">
            <button class="btn" type="submit">Applica</button>
            <a class="btn secondary" href="index.php">Reset</a>
        </div>
    </div>

    <div class="fatture-filter-grid">
        <div>
            <label>Ricerca</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Numero, cliente o email">
        </div>
        <div>
            <label>Stato SDI</label>
            <select name="sdi_flow">
                <option value="" <?php echo $sdiFlow === '' ? 'selected' : ''; ?>>Tutte</option>
                <option value="ready" <?php echo $sdiFlow === 'ready' ? 'selected' : ''; ?>>Pronte per SDI</option>
                <option value="sent" <?php echo $sdiFlow === 'sent' ? 'selected' : ''; ?>>Inviate allo SDI</option>
                <option value="issues" <?php echo $sdiFlow === 'issues' ? 'selected' : ''; ?>>Con errori SDI</option>
            </select>
        </div>
        <div>
            <label>Invio automatico</label>
            <select name="auto_send">
                <option value="" <?php echo $autoSendFilter === '' ? 'selected' : ''; ?>>Tutte</option>
                <option value="stripe_skipped" <?php echo $autoSendFilter === 'stripe_skipped' ? 'selected' : ''; ?>>Stripe saltate</option>
            </select>
        </div>
    </div>
</form>
