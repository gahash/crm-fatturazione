<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clienti WHERE id = :id");
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    echo 'Cliente non trovato';
    exit;
}

$noteStmt = $pdo->prepare("SELECT * FROM clienti_note WHERE cliente_id = :id ORDER BY creato_il DESC");
$noteStmt->execute([':id' => $id]);
$note = $noteStmt->fetchAll();

$intStmt = $pdo->prepare("SELECT * FROM clienti_interazioni WHERE cliente_id = :id ORDER BY data_interazione DESC");
$intStmt->execute([':id' => $id]);
$interazioni = $intStmt->fetchAll();

$embedded = isset($_GET['embed']);
if ($embedded) {
    $config = require __DIR__ . '/../../config/config.php';
    ?>
    <!doctype html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Scheda cliente</title>
        <link rel="stylesheet" href="<?php echo $config['app']['base_url']; ?>/modules/clienti/css/clienti.css">
        <link rel="stylesheet" href="<?php echo $config['app']['base_url']; ?>/modules/fatture/css/fatture.css">
        <style>
            body { background: #f5f6f8; }
            .app-main { padding: 16px; }
        </style>
    </head>
    <body>
    <main class="app-main">
    <?php
} else {
    require __DIR__ . '/../../layouts/header.php';
}
?>
<div class="wrap">
    <div class="card">
        <h2>Scheda cliente</h2>
        <form method="post" action="clienti_store.php">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
            <div class="grid">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" required value="<?php echo htmlspecialchars($cliente['nome']); ?>">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($cliente['email']); ?>">
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono" value="<?php echo htmlspecialchars($cliente['telefono']); ?>">
                </div>
                <div>
                    <label>Azienda</label>
                    <input type="text" name="azienda" value="<?php echo htmlspecialchars($cliente['azienda']); ?>">
                </div>
                <div>
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo" value="<?php echo htmlspecialchars($cliente['indirizzo']); ?>">
                </div>
                <div>
                    <label>P.IVA</label>
                    <input type="text" name="piva" value="<?php echo htmlspecialchars($cliente['piva']); ?>">
                </div>
                <div>
                    <label>Codice SDI</label>
                    <input type="text" name="codice_sdi" value="<?php echo htmlspecialchars($cliente['codice_sdi'] ?? ''); ?>" maxlength="20">
                </div>
                <div>
                    <label>PEC</label>
                    <input type="email" name="pec" value="<?php echo htmlspecialchars($cliente['pec'] ?? ''); ?>">
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="3"><?php echo htmlspecialchars($cliente['note']); ?></textarea>
                </div>
            </div>
            <button class="btn" type="submit">Aggiorna</button>
        </form>
        <form method="post" action="clienti_store.php" style="margin-top:10px;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
            <button class="btn danger" type="submit" onclick="return confirm('Eliminare cliente?')">Elimina</button>
        </form>
    </div>

    <div class="card">
        <h3>Note</h3>
        <form method="post" action="cliente_note.php">
            <input type="hidden" name="cliente_id" value="<?php echo $cliente['id']; ?>">
            <textarea name="contenuto" rows="3" required></textarea>
            <button class="btn" type="submit">Aggiungi nota</button>
        </form>
        <ul>
            <?php foreach ($note as $n): ?>
                <li><?php echo htmlspecialchars($n['contenuto']); ?> <span class="muted">(<?php echo $n['creato_il']; ?>)</span></li>
            <?php endforeach; ?>
            <?php if (!$note): ?>
                <li class="muted">Nessuna nota.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="card">
        <h3>Interazioni</h3>
        <form method="post" action="cliente_interazione.php">
            <input type="hidden" name="cliente_id" value="<?php echo $cliente['id']; ?>">
            <div class="grid">
                <div>
                    <label>Tipo</label>
                    <select name="tipo">
                        <option value="chiamata">Chiamata</option>
                        <option value="email">Email</option>
                        <option value="meeting">Meeting</option>
                        <option value="altro">Altro</option>
                    </select>
                </div>
                <div>
                    <label>Data</label>
                    <input type="date" name="data_interazione" required>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Descrizione</label>
                    <textarea name="descrizione" rows="3" required></textarea>
                </div>
            </div>
            <button class="btn" type="submit">Aggiungi interazione</button>
        </form>
        <ul>
            <?php foreach ($interazioni as $i): ?>
                <li><?php echo htmlspecialchars($i['data_interazione']); ?> - <?php echo htmlspecialchars($i['tipo']); ?>: <?php echo htmlspecialchars($i['descrizione']); ?></li>
            <?php endforeach; ?>
            <?php if (!$interazioni): ?>
                <li class="muted">Nessuna interazione.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php if ($embedded): ?>
    </main>
    </body>
    </html>
<?php else: ?>
    <?php require __DIR__ . '/../../layouts/footer.php'; ?>
<?php endif; ?>

