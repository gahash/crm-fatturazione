<?php
$q = $q ?? '';
require_once __DIR__ . '/../../fatture/actions/fic_api.php';
$ficToken = fic_get_access_token();
$ficConnected = $ficToken !== '';
?>
<form method="get" class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <h3 style="margin:0;">Filtri</h3>
        <span class="badge <?php echo $ficConnected ? 'ok' : 'ko'; ?>">
            <?php echo $ficConnected ? 'Connesso' : 'Non connesso'; ?>
        </span>
    </div>
    <div class="grid" style="margin-top:12px;">
        <div>
            <label>Ricerca</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Numero, cliente">
        </div>
        <div style="align-self:end;">
            <button class="btn" type="submit">Applica</button>
            <a class="btn secondary" href="index.php">Reset</a>
        </div>
        <div style="align-self:end;">
            <a class="btn" href="../fatture/actions/fic_oauth_start.php">Connetti Fatture in Cloud</a>
        </div>
    </div>
</form>
