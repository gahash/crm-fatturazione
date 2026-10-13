<?php
$fatture = $fatture ?? [];

function fatture_extract_auto_send_skip_reason(array $fattura): ?string
{
    $mailStatus = (string)($fattura['mail_status'] ?? '');
    $mailError = trim((string)($fattura['mail_error'] ?? ''));
    if ($mailStatus !== 'pending' || $mailError === '') {
        return null;
    }

    if (strpos($mailError, 'Pagamento Stripe registrato: invio automatico non eseguito') !== 0) {
        return null;
    }

    if (stripos($mailError, 'anagrafica incompleta') !== false) {
        if (stripos($mailError, 'partita IVA') !== false && stripos($mailError, 'email valida') !== false) {
            return 'Skip auto-send: manca P.IVA + email';
        }
        if (stripos($mailError, 'partita IVA') !== false) {
            return 'Skip auto-send: manca P.IVA';
        }
        if (stripos($mailError, 'email valida') !== false) {
            return 'Skip auto-send: email non valida';
        }
        return 'Skip auto-send: anagrafica incompleta';
    }

    if (stripos($mailError, 'automazione disattivato') !== false || stripos($mailError, 'automazione disattivata') !== false) {
        return 'Skip auto-send: automazione off';
    }
    if (stripos($mailError, 'SMTP') !== false) {
        return 'Skip auto-send: SMTP mancante';
    }
    if (stripos($mailError, 'company_id FIC mancante') !== false) {
        return 'Skip auto-send: FIC non configurato';
    }

    return 'Skip auto-send';
}

function fatture_is_sdi_done(array $fattura): bool
{
    $status = strtolower(trim((string)($fattura['ei_status'] ?? '')));
    return in_array($status, ['sent', 'processing', 'accepted', 'ok'], true);
}

function fatture_is_paid_stripe_invoice(array $fattura): bool
{
    return strtolower(trim((string)($fattura['stato'] ?? ''))) === 'pagata'
        && strtolower(trim((string)($fattura['metodo_pagamento'] ?? ''))) === 'stripe'
        && strtolower(trim((string)($fattura['document_type'] ?? 'invoice'))) === 'invoice';
}

function fatture_auto_sdi_status(array $fattura, bool $sdiDone, ?string $autoSendSkipReason): array
{
    $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
    $eiError = trim((string)($fattura['ei_error'] ?? ''));
    $lastOutcome = strtolower(trim((string)($fattura['auto_sdi_last_event_outcome'] ?? '')));
    $lastMsg = trim((string)($fattura['auto_sdi_last_event_msg'] ?? ''));

    if (!fatture_is_paid_stripe_invoice($fattura)) {
        return ['label' => null, 'class' => 'bozza', 'detail' => ''];
    }

    if ($sdiDone) {
        return ['label' => 'Auto SDI completato', 'class' => 'ok', 'detail' => $lastMsg];
    }

    if ($autoSendSkipReason !== null) {
        return ['label' => 'Auto SDI bloccato', 'class' => 'fatture-warning-badge', 'detail' => $autoSendSkipReason];
    }

    if ($eiError !== '' || $lastOutcome === 'error') {
        return ['label' => 'Auto SDI errore', 'class' => 'ko', 'detail' => $eiError !== '' ? $eiError : $lastMsg];
    }

    if (!empty($fattura['fic_document_id'])) {
        return ['label' => 'Auto SDI in attesa', 'class' => 'bozza', 'detail' => $lastMsg !== '' ? $lastMsg : 'Atteso entro 5 minuti dal pagamento Stripe'];
    }

    return ['label' => 'Auto SDI da creare', 'class' => 'bozza', 'detail' => $lastMsg !== '' ? $lastMsg : 'Documento FIC non ancora collegato'];
}

function fatture_auto_sdi_attention_class(array $fattura, array $autoSdi): string
{
    if (!fatture_is_paid_stripe_invoice($fattura)) {
        return '';
    }

    if (($autoSdi['label'] ?? '') === 'Auto SDI errore' || ($autoSdi['label'] ?? '') === 'Auto SDI bloccato') {
        return 'fatture-auto-sdi-row fatture-auto-sdi-row--critical';
    }

    if (($autoSdi['label'] ?? '') === 'Auto SDI in attesa' || ($autoSdi['label'] ?? '') === 'Auto SDI da creare') {
        return 'fatture-auto-sdi-row fatture-auto-sdi-row--pending';
    }

    return '';
}
?>
<div class="card fatture-list-card">
    <div class="fatture-list-header">
        <h3>Lista fatture</h3>
        <a class="btn secondary" href="esporta_csv.php">Esporta CSV</a>
    </div>

    <div class="fatture-table-wrapper">
        <table class="fatture-table">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Data</th>
                    <th>Stato</th>
                    <th>Totale</th>
                    <th>Stato FIC/SDI</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fatture as $f): ?>
                <?php
                $recipientEmail = (string)($f['cliente_email'] ?? $f['email'] ?? '');
                $autoSendSkipReason = fatture_extract_auto_send_skip_reason($f);
                $hasFicDocument = !empty($f['fic_document_id']);
                $sdiDone = fatture_is_sdi_done($f);
                $autoSdi = fatture_auto_sdi_status($f, $sdiDone, $autoSendSkipReason);
                $lastAutoSdiAt = trim((string)($f['auto_sdi_last_event_at'] ?? ''));
                $autoSdiAttentionClass = fatture_auto_sdi_attention_class($f, $autoSdi);
                ?>
                <tr class="<?php echo htmlspecialchars($autoSdiAttentionClass); ?>">
                    <td><strong><?php echo htmlspecialchars((string)$f['numero']); ?></strong></td>
                    <td>
                        <?php echo htmlspecialchars((string)$f['nome']); ?>
                        <?php if ($recipientEmail !== ''): ?>
                            <div class="muted" style="font-size:0.8rem;"><?php echo htmlspecialchars($recipientEmail); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$f['data_emissione']); ?></td>
                    <td><span class="badge <?php echo htmlspecialchars((string)$f['stato']); ?>"><?php echo htmlspecialchars((string)$f['stato']); ?></span></td>
                    <td><?php echo number_format((float)$f['totale'], 2, ',', '.'); ?></td>
                    <td class="<?php echo $autoSdiAttentionClass !== '' ? 'fatture-auto-sdi-cell' : ''; ?>">
                        <div class="fatture-status-stack">
                            <?php if ($hasFicDocument): ?>
                                <span class="badge ok">FIC collegata</span>
                            <?php else: ?>
                                <span class="badge bozza">FIC mancante</span>
                            <?php endif; ?>

                            <?php if (!empty($f['ei_status'])): ?>
                                <span class="badge <?php echo $sdiDone ? 'ok' : 'ko'; ?>">
                                    SDI: <?php echo htmlspecialchars((string)$f['ei_status']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bozza">SDI non inviato</span>
                            <?php endif; ?>

                            <?php if ($autoSendSkipReason !== null): ?>
                                <span class="badge fatture-warning-badge" title="<?php echo htmlspecialchars((string)($f['mail_error'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($autoSendSkipReason); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($autoSdi['label'] !== null): ?>
                                <span class="badge <?php echo htmlspecialchars($autoSdi['class']); ?>" title="<?php echo htmlspecialchars((string)$autoSdi['detail']); ?>">
                                    <?php echo htmlspecialchars((string)$autoSdi['label']); ?>
                                </span>
                                <?php if ($lastAutoSdiAt !== ''): ?>
                                    <div class="muted" style="font-size:0.78rem; margin-top:4px;">
                                        Ultimo evento: <?php echo htmlspecialchars($lastAutoSdiAt); ?>
                                    </div>
                                <?php elseif (fatture_is_paid_stripe_invoice($f)): ?>
                                    <div class="muted" style="font-size:0.78rem; margin-top:4px;">
                                        Atteso entro 5 minuti dal pagamento Stripe
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($autoSdi['detail']) && $autoSdi['label'] !== 'Auto SDI completato'): ?>
                                <div class="muted" style="font-size:0.78rem; margin-top:4px; max-width:280px;">
                                    <?php echo htmlspecialchars(mb_strimwidth((string)$autoSdi['detail'], 0, 90, '...')); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="fatture-row-actions">
                            <a class="btn secondary btn-sm" href="dettaglio_fattura.php?id=<?php echo (int)$f['id']; ?>">Dettaglio</a>
                            <a class="btn secondary btn-sm" href="modifica_fattura.php?id=<?php echo (int)$f['id']; ?>">Modifica</a>
                            <?php if (!$hasFicDocument): ?>
                                <a class="btn btn-sm" href="actions/fic_push.php?id=<?php echo (int)$f['id']; ?>&return=index.php">Invia FIC</a>
                            <?php else: ?>
                                <a class="btn secondary btn-sm" href="actions/fic_pdf.php?id=<?php echo (int)$f['id']; ?>" target="_blank">PDF</a>
                                <?php if ($sdiDone): ?>
                                    <span class="badge ok">SDI OK</span>
                                <?php else: ?>
                                    <a class="btn success btn-sm" href="actions/fic_send_einvoice.php?id=<?php echo (int)$f['id']; ?>&return=index.php" onclick="return confirm('Inviare questa fattura allo SDI?');">Invia SDI</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$fatture): ?>
                <tr><td colspan="7" class="muted">Nessuna fattura.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
