<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/actions/fic_audit.php';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email AS cliente_email FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
    http_response_code(404);
    echo 'Fattura non trovata';
    exit;
}

$righe = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id");
$righe->execute([':id' => $id]);
$rows = $righe->fetchAll();
$logs = fatture_recent_logs($pdo, 20, $id);

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <div class="card">
        <h2>Fattura <?php echo htmlspecialchars($fattura['numero']); ?></h2>
        <?php if (isset($_GET['stato']) && $_GET['stato'] === 'ok'): ?>
            <div class="alert success">✅ Stato fattura aggiornato.</div>
        <?php elseif (isset($_GET['mail']) && $_GET['mail'] === 'ok'): ?>
            <div class="alert success">✅ Fattura inviata via email.</div>
        <?php elseif (isset($_GET['mail']) && $_GET['mail'] === 'error'): ?>
            <div class="alert error">⚠️ Invio email fallito. Verifica i dati cliente e la configurazione SMTP.</div>
        <?php endif; ?>
        <p><strong>Cliente:</strong> <?php echo htmlspecialchars($fattura['nome']); ?></p>
        <?php if (!empty($fattura['cliente_email'])): ?>
            <p><strong>Email cliente:</strong> <?php echo htmlspecialchars($fattura['cliente_email']); ?></p>
        <?php endif; ?>
        <p><strong>Data:</strong> <?php echo htmlspecialchars($fattura['data_emissione']); ?></p>
        <p><strong>Stato:</strong> <?php echo htmlspecialchars($fattura['stato']); ?></p>
        <p><strong>Totale:</strong> <?php echo number_format((float)$fattura['totale'], 2, ',', '.'); ?></p>
        <p><strong>Note:</strong> <?php echo nl2br(htmlspecialchars($fattura['note'])); ?></p>
        <?php if (!empty($fattura['mail_sent_at'])): ?>
            <p><strong>Ultimo invio:</strong> <?php echo htmlspecialchars($fattura['mail_sent_at']); ?></p>
        <?php endif; ?>
        <?php if (!empty($fattura['mail_status'])): ?>
            <p><strong>Stato invio:</strong> <?php echo htmlspecialchars($fattura['mail_status']); ?></p>
        <?php endif; ?>
        <?php if (!empty($fattura['mail_error'])): ?>
            <p><strong>Errore invio:</strong> <?php echo htmlspecialchars($fattura['mail_error']); ?></p>
        <?php endif; ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px;">
            <a class="btn secondary" href="modifica_fattura.php?id=<?php echo $fattura['id']; ?>">Modifica</a>
            <button class="btn secondary" type="button" data-modal-open="modal-stato-fattura">Cambia stato</button>
            <a class="btn" href="actions/fic_pdf.php?id=<?php echo $fattura['id']; ?>">Visualizza PDF</a>
            <button type="button" class="btn" onclick="invioFatturaAsync(<?php echo (int)$fattura['id']; ?>, '<?php echo htmlspecialchars(addslashes($fattura['numero'])); ?>', '<?php echo htmlspecialchars(addslashes($fattura['cliente_email'] ?? 'N/A')); ?>');">Invia fattura</button>
            <?php if (!empty($fattura['fic_document_id'])): ?>
                <button type="button" class="btn secondary" style="font-size:0.85rem;" onclick="debugFicStatus(<?php echo (int)$fattura['id']; ?>);">Debug FIC</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal" id="modal-stato-fattura" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="margin:0;">Aggiorna stato fattura</h3>
                <button type="button" class="icon-btn" data-modal-close aria-label="Chiudi">&times;</button>
            </div>
            <form method="post" action="actions/fattura_update_status.php">
                <input type="hidden" name="id" value="<?php echo (int)$fattura['id']; ?>">
                <label>Stato</label>
                <select name="stato" required>
                    <option value="bozza" <?php echo $fattura['stato'] === 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                    <option value="emessa" <?php echo $fattura['stato'] === 'emessa' ? 'selected' : ''; ?>>Emessa</option>
                    <option value="pagata" <?php echo $fattura['stato'] === 'pagata' ? 'selected' : ''; ?>>Pagata</option>
                    <option value="scaduta" <?php echo $fattura['stato'] === 'scaduta' ? 'selected' : ''; ?>>Scaduta</option>
                </select>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="btn" type="submit">Salva</button>
                    <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Righe</h3>
        <table>
            <thead>
                <tr>
                    <th>Descrizione</th>
                    <th>Q.ta</th>
                    <th>Prezzo</th>
                    <th>IVA</th>
                    <th>Totale</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['descrizione']); ?></td>
                    <td><?php echo htmlspecialchars($r['quantita']); ?></td>
                    <td><?php echo number_format((float)$r['prezzo_unitario'], 2, ',', '.'); ?></td>
                    <td><?php echo number_format((float)$r['iva'], 2, ',', '.'); ?>%</td>
                    <td><?php echo number_format((float)$r['totale_riga'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted">Nessuna riga.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Traccia attività</h3>
        <?php if (!$logs): ?>
            <p class="muted">Nessuna attività registrata per questa fattura.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Azione</th>
                        <th>Esito</th>
                        <th>Messaggio</th>
                        <th>Utente</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$log['creato_il']); ?></td>
                        <td><?php echo htmlspecialchars((string)$log['azione']); ?></td>
                        <td><span class="badge <?php echo (($log['esito'] ?? '') === 'ok') ? 'ok' : ((($log['esito'] ?? '') === 'error') ? 'ko' : 'bozza'); ?>"><?php echo htmlspecialchars((string)$log['esito']); ?></span></td>
                        <td><?php echo htmlspecialchars((string)$log['messaggio']); ?></td>
                        <td><?php echo htmlspecialchars((string)($log['utente_nome'] ?: $log['utente_email'] ?: '-')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>

