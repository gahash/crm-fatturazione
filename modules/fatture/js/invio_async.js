/**
 * invio_async.js - Gestione invio fatture con barra di caricamento reale
 */

(function() {
    'use strict';

    const config = {
        asyncEndpoint: '/modules/fatture/actions/fic_send_mail_async.php',
        pollInterval: 800, // ms tra controlli stato
        maxPollAttempts: 120 // max ~96 secondi
    };

    /**
     * Mostra modal con barra di caricamento
     */
    function showLoadingModal(fatturNumero) {
        const html = `
            <div class="modal" id="modal-invio-fattura" aria-hidden="false" style="display:flex;">
                <div class="modal-card" style="max-width:400px;">
                    <div class="modal-header">
                        <h3 style="margin:0;">Invio fattura ${escapeHtml(fatturNumero)}</h3>
                    </div>
                    <div style="padding:20px; text-align:center;">
                        <div style="margin-bottom:20px;">
                            <div style="background:#e5e7eb; height:8px; border-radius:4px; overflow:hidden;">
                                <div id="progress-bar" style="background:#10b981; height:100%; width:0%; transition:width 0.3s ease;"></div>
                            </div>
                        </div>
                        <p id="status-text" style="margin:0; color:#6b7280; font-size:14px;">Elaborazione in corso...</p>
                        <p id="progress-pct" style="margin:8px 0 0 0; font-weight:bold; color:#374151; font-size:13px;">0%</p>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', html);
        return document.getElementById('modal-invio-fattura');
    }

    /**
     * Mostra modal di successo
     */
    function showSuccessModal(fatturNumero, email) {
        const html = `
            <div class="modal" id="modal-invio-success" aria-hidden="false" style="display:flex;">
                <div class="modal-card" style="max-width:450px;">
                    <div class="modal-header">
                        <h3 style="margin:0;">✅ Invio completato</h3>
                        <button type="button" class="icon-btn" onclick="closeSuccessModal()" aria-label="Chiudi">&times;</button>
                    </div>
                    <div style="padding:20px;">
                        <p style="margin:0 0 12px 0;"><strong>Fattura ${escapeHtml(fatturNumero)}</strong> inviata con successo.</p>
                        <p style="margin:0; color:#6b7280; font-size:14px;">
                            Email inviata a: <strong>${escapeHtml(email)}</strong>
                        </p>
                        <div style="display:flex; gap:8px; margin-top:16px;">
                            <button class="btn" type="button" onclick="closeSuccessModal()">Chiudi</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Mostra modal di errore
     */
    function showErrorModal(fatturNumero, errorMsg, faturaId = null) {
        const isDocNotFound = errorMsg.toLowerCase().includes('documento non trovato');
        const fallbackButtons = isDocNotFound && faturaId ? `
                                <a class="btn secondary" type="button" href="/modules/fatture/actions/fic_pdf_fallback.php?id=${parseInt(faturaId)}" download>Scarica fallback</a>
                                <a class="btn secondary" type="button" href="/modules/fatture/dettaglio_fattura.php?id=${parseInt(faturaId)}">Dettaglio</a>
                            ` : '';
        
        const html = `
            <div class="modal" id="modal-invio-error" aria-hidden="false" style="display:flex;">
                <div class="modal-card" style="max-width:500px;">
                    <div class="modal-header">
                        <h3 style="margin:0;">❌ Invio fallito</h3>
                        <button type="button" class="icon-btn" onclick="closeErrorModal()" aria-label="Chiudi">&times;</button>
                    </div>
                    <div style="padding:20px;">
                        <p style="margin:0 0 12px 0;"><strong>Fattura ${escapeHtml(fatturNumero)}</strong></p>
                        <div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:4px; padding:12px; margin-bottom:16px;">
                            <p style="margin:0; color:#7f1d1d; font-size:13px; word-break:break-word;">
                                ${escapeHtml(errorMsg)}
                            </p>
                        </div>
                        ${isDocNotFound ? '<p style="margin:0 0 12px 0; font-size:12px; color:#6b7280;"><strong>Soluzione:</strong> Scarica la fattura in fallback locale oppure riprova il PDF dal dettaglio.</p>' : ''}
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button class="btn danger" type="button" onclick="closeErrorModal()">Chiudi</button>
                            <button class="btn secondary" type="button" onclick="location.reload();">Ricarica</button>
                            ${fallbackButtons}
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Aggiorna barra di caricamento
     */
    function updateProgress(percent, message) {
        const bar = document.getElementById('progress-bar');
        const text = document.getElementById('status-text');
        const pct = document.getElementById('progress-pct');
        if (bar) bar.style.width = Math.min(percent, 99) + '%';
        if (text) text.textContent = message || 'Elaborazione in corso...';
        if (pct) pct.textContent = Math.min(percent, 99) + '%';
    }

    /**
     * Poll stato invio
     */
    async function pollStatus(faturaId, attempt = 0) {
        if (attempt >= config.maxPollAttempts) {
            return { error: 'Timeout: invio non completato entro il tempo limite.' };
        }

        const progressStep = Math.floor((attempt / config.maxPollAttempts) * 90) + 5;
        updateProgress(progressStep, 'Controllo stato...');

        try {
            const response = await fetch(config.asyncEndpoint + '?action=status&id=' + parseInt(faturaId), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return { error: 'Errore HTTP ' + response.status };
            }

            const data = await response.json();
            const status = data.status || 'pending';

            if (status === 'sent') {
                return { success: true, data };
            }

            if (status === 'error') {
                return { error: data.error || 'Errore sconosciuto' };
            }

            // pending: continua polling
            await new Promise(resolve => setTimeout(resolve, config.pollInterval));
            return pollStatus(faturaId, attempt + 1);

        } catch (err) {
            return { error: 'Errore rete: ' + err.message };
        }
    }

    /**
     * Esegui invio asincrono
     */
    async function executeAsyncSend(faturaId, fatturNumero, email) {
        try {
            const response = await fetch(config.asyncEndpoint + '?action=execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: 'id=' + parseInt(faturaId)
            });

            const data = await response.json();

            if (!response.ok) {
                return { error: data.error || 'Errore invio (HTTP ' + response.status + ')' };
            }

            return { success: true, data };

        } catch (err) {
            return { error: 'Errore rete: ' + err.message };
        }
    }

    /**
     * Entry point: avvia sequenza invio
     */
    window.invioFatturaAsync = async function(faturaId, fatturNumero, email) {
        const modal = showLoadingModal(fatturNumero);

        try {
            updateProgress(5, 'Preparazione invio...');

            // Step 1: Marca come pending
            const startResp = await fetch(config.asyncEndpoint + '?action=start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: 'id=' + parseInt(faturaId)
            });
            
            if (!startResp.ok) {
                // Leggi il vero errore dal body
                let errorMsg = 'Errore preparazione';
                try {
                    const errorData = await startResp.json();
                    if (errorData.error) {
                        errorMsg = errorData.error;
                    }
                } catch (e) {
                    // Se non è JSON, prova a leggere come testo
                    const errorText = await startResp.text();
                    if (errorText) {
                        errorMsg += ': ' + errorText.substring(0, 200);
                    }
                }
                throw new Error(errorMsg);
            }

            updateProgress(15, 'Invio in corso...');

            // Step 2: Esegui invio
            const execResp = await executeAsyncSend(faturaId, fatturNumero, email);
            if (!execResp.success) {
                modal.remove();
                showErrorModal(fatturNumero, execResp.error, faturaId);
                return;
            }

            updateProgress(95, 'Finalizzazione...');
            updateProgress(100, 'Completato!');

            await new Promise(resolve => setTimeout(resolve, 600));
            modal.remove();
            showSuccessModal(fatturNumero, email);

        } catch (err) {
            modal.remove();
            showErrorModal(fatturNumero, 'Errore non atteso: ' + err.message, faturaId);
        }
    };

    window.closeSuccessModal = function() {
        const m = document.getElementById('modal-invio-success');
        if (m) m.remove();
    };

    window.closeErrorModal = function() {
        const m = document.getElementById('modal-invio-error');
        if (m) m.remove();
    };

    /**
     * Debug FIC status
     */
    window.debugFicStatus = async function(faturaId) {
        try {
            const response = await fetch('/modules/fatture/actions/fic_debug_status.php?id=' + parseInt(faturaId));
            if (!response.ok) throw new Error('HTTP ' + response.status);

            const data = await response.json();
            
            const html = `
                <div class="modal" id="modal-debug-fic" aria-hidden="false" style="display:flex;">
                    <div class="modal-card" style="max-width:500px;">
                        <div class="modal-header">
                            <h3 style="margin:0;">🔍 Debug Fatture in Cloud</h3>
                            <button type="button" class="icon-btn" onclick="closeDebugModal()" aria-label="Chiudi">&times;</button>
                        </div>
                        <div style="padding:20px; font-size:13px;">
                            <p><strong>Fattura:</strong> ${escapeHtml(data.fattura.numero || 'N/A')}</p>
                            <p><strong>FIC Document ID:</strong> <code>${escapeHtml(data.fattura.fic_document_id || 'null')}</code></p>
                            <p><strong>Company ID:</strong> <code>${escapeHtml(data.fic.companyId)}</code></p>
                            
                            ${data.fic.error ? '<p style="color:#991b1b; background:#fee2e2; padding:8px; border-radius:4px;"><strong>❌ Errore:</strong> ' + escapeHtml(data.fic.error) + '</p>' : ''}
                            
                            ${data.fic.documentStatus ? `
                                <div style="background:#f3f4f6; padding:12px; border-radius:4px; margin-top:12px;">
                                    <p style="margin:0 0 8px 0;"><strong>✅ Documento trovato:</strong></p>
                                    <p style="margin:4px 0;">Status: <code>${escapeHtml(data.fic.documentStatus.status || 'unknown')}</code></p>
                                    <p style="margin:4px 0;">Subject: ${escapeHtml(data.fic.documentStatus.visible_subject || 'N/A')}</p>
                                </div>
                            ` : ''}
                            
                            <div style="margin-top:16px; display:flex; gap:8px;">
                                <button class="btn secondary" type="button" onclick="closeDebugModal()">Chiudi</button>
                                <button class="btn danger" type="button" onclick="resetFicDocument(${parseInt(faturaId)})">Reset & Ricrea</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', html);
        } catch (err) {
            alert('Errore: ' + err.message);
        }
    };

    window.closeDebugModal = function() {
        const m = document.getElementById('modal-debug-fic');
        if (m) m.remove();
    };

    /**
     * Reset documento FIC (azzera IDs)
     */
    window.resetFicDocument = async function(faturaId) {
        if (!confirm('Azzerare i dati FIC? La fattura verrà ricreata al prossimo invio.')) return;
        
        try {
            const response = await fetch('/modules/fatture/actions/fic_reset_document.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: 'id=' + parseInt(faturaId)
            });

            if (!response.ok) throw new Error('HTTP ' + response.status);
            
            alert('✅ Dati FIC azzerati. Ricrea la fattura facendo clic su Invia.');
            closeDebugModal();
            location.reload();
        } catch (err) {
            alert('❌ Errore: ' + err.message);
        }
    };

    /**
     * Helper: escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
