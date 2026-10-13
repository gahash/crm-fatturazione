<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_row') {
        $quantita = (float)($_POST['quantita'] ?? 1);
        $prezzo = (float)($_POST['prezzo_unitario'] ?? 0);
        $iva = (float)($_POST['iva'] ?? 0);
        $totale = $quantita * $prezzo * (1 + $iva / 100);

        $stmt = $pdo->prepare("INSERT INTO fatture_righe (fattura_id, descrizione, quantita, prezzo_unitario, iva, totale_riga) VALUES (:fattura_id, :descrizione, :quantita, :prezzo_unitario, :iva, :totale_riga)");
        $stmt->execute([
            ':fattura_id' => $id,
            ':descrizione' => trim($_POST['descrizione'] ?? ''),
            ':quantita' => $quantita,
            ':prezzo_unitario' => $prezzo,
            ':iva' => $iva,
            ':totale_riga' => $totale,
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE fatture SET numero=:numero, data_emissione=:data_emissione, data_scadenza=:data_scadenza, stato=:stato, totale=:totale, note=:note WHERE id=:id AND document_type='quote'");
        $stmt->execute([
            ':numero' => trim($_POST['numero'] ?? ''),
            ':data_emissione' => $_POST['data_emissione'] ?? '',
            ':data_scadenza' => ($_POST['data_scadenza'] ?? '') !== '' ? $_POST['data_scadenza'] : null,
            ':stato' => $_POST['stato'] ?? 'bozza',
            ':totale' => (float)($_POST['totale'] ?? 0),
            ':note' => trim($_POST['note'] ?? ''),
            ':id' => $id,
        ]);
    }
    header('Location: modifica.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id AND f.document_type = 'quote'");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
    http_response_code(404);
    echo 'Preventivo non trovato';
    exit;
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <div class="card">
        <h2>Modifica preventivo</h2>
        <form method="post">
            <div class="grid">
                <div>
                    <label>Numero</label>
                    <input type="text" name="numero" value="<?php echo htmlspecialchars($fattura['numero']); ?>" required>
                </div>
                <div>
                    <label>Data emissione</label>
                    <input type="date" name="data_emissione" value="<?php echo htmlspecialchars($fattura['data_emissione']); ?>" required>
                </div>
                <div>
                    <label>Data scadenza</label>
                    <input type="date" name="data_scadenza" value="<?php echo htmlspecialchars($fattura['data_scadenza']); ?>">
                </div>
                <div>
                    <label>Stato</label>
                    <select name="stato">
                        <option value="bozza" <?php echo $fattura['stato'] === 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                        <option value="emessa" <?php echo $fattura['stato'] === 'emessa' ? 'selected' : ''; ?>>Emessa</option>
                        <option value="pagata" <?php echo $fattura['stato'] === 'pagata' ? 'selected' : ''; ?>>Pagata</option>
                        <option value="scaduta" <?php echo $fattura['stato'] === 'scaduta' ? 'selected' : ''; ?>>Scaduta</option>
                    </select>
                </div>
                <div>
                    <label>Totale</label>
                    <input type="number" step="0.01" name="totale" value="<?php echo htmlspecialchars($fattura['totale']); ?>">
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="3"><?php echo htmlspecialchars($fattura['note']); ?></textarea>
                </div>
            </div>
            <button class="btn" type="submit">Salva</button>
        </form>
    </div>

    <div class="card">
        <h3>Aggiungi riga</h3>
        <form method="post">
            <input type="hidden" name="action" value="add_row">
            <div class="grid">
                <div>
                    <label>Descrizione</label>
                    <input type="text" name="descrizione" required>
                </div>
                <div>
                    <label>Quantita</label>
                    <input type="number" step="0.01" name="quantita" value="1">
                </div>
                <div>
                    <label>Prezzo unitario</label>
                    <input type="number" step="0.01" name="prezzo_unitario" value="0">
                </div>
                <div>
                    <label>IVA %</label>
                    <input type="number" step="0.01" name="iva" value="0">
                </div>
            </div>
            <button class="btn" type="submit">Aggiungi</button>
        </form>
        <p class="muted">Aggiorna il totale manualmente se vuoi usare le righe come dettaglio.</p>
    </div>
</div>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
