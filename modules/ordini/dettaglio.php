<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT f.*, c.nome AS cliente FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id AND f.document_type = 'order'");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
    http_response_code(404);
    echo 'Ordine non trovato';
    exit;
}

$righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
$righeStmt->execute([':id' => $id]);
$righe = $righeStmt->fetchAll();

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <div style="margin-bottom:16px;">
        <a href="index.php" class="btn secondary">← Torna agli ordini</a>
    </div>

    <div class="card">
        <h2>Dettaglio ordine</h2>
        <p><strong>Numero:</strong> <?php echo htmlspecialchars($fattura['numero']); ?></p>
        <p><strong>Cliente:</strong> <?php echo htmlspecialchars($fattura['cliente']); ?></p>
        <p><strong>Data:</strong> <?php echo htmlspecialchars($fattura['data_emissione']); ?></p>
        <p><strong>Stato:</strong> <?php echo htmlspecialchars($fattura['stato']); ?></p>
        <p><strong>Totale:</strong> €<?php echo number_format((float)$fattura['totale'], 2, ',', '.'); ?></p>
        <?php if (!empty($fattura['metodo_pagamento'])): ?>
            <p><strong>Metodo pagamento:</strong> <?php echo htmlspecialchars($fattura['metodo_pagamento']); ?></p>
        <?php endif; ?>
        <?php if (!empty($fattura['data_pagamento'])): ?>
            <p><strong>Data pagamento:</strong> <?php echo htmlspecialchars($fattura['data_pagamento']); ?></p>
        <?php endif; ?>
        <?php if (!empty($fattura['stripe_payment_intent_id'])): ?>
            <p><strong>Stripe Payment ID:</strong> <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;"><?php echo htmlspecialchars($fattura['stripe_payment_intent_id']); ?></code></p>
            <p style="color:#635bff;font-size:13px;">🟣 Ordine pagato tramite Stripe</p>
        <?php endif; ?>
        <?php if (!empty($fattura['note'])): ?>
            <p><strong>Note:</strong> <?php echo nl2br(htmlspecialchars($fattura['note'])); ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Righe</h3>
        <table>
            <thead>
                <tr>
                    <th>Descrizione</th>
                    <th>Quantità</th>
                    <th>Prezzo</th>
                    <th>IVA</th>
                    <th>Totale</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($righe as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['descrizione']); ?></td>
                    <td><?php echo number_format((float)$r['quantita'], 2, ',', '.'); ?></td>
                    <td><?php echo number_format((float)$r['prezzo_unitario'], 2, ',', '.'); ?></td>
                    <td><?php echo number_format((float)$r['iva'], 2, ',', '.'); ?>%</td>
                    <td><?php echo number_format((float)$r['totale_riga'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$righe): ?>
                <tr><td colspan="5" class="muted">Nessuna riga.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
