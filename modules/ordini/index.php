<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$docType = 'order';
$docLabel = 'Ordini';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPageOptions = [10, 20, 30, 50, 100];
$perPage = (isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions, true))
    ? (int)$_GET['per_page']
    : 10;
$params = [':docType' => $docType];

$baseSql = "FROM fatture f JOIN clienti c ON c.id = f.cliente_id";
$where = " WHERE f.document_type = :docType";
if ($q !== '') {
    $where .= " AND (f.numero LIKE :q OR c.nome LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = "SELECT f.*, c.nome $baseSql $where ORDER BY f.data_emissione DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$documenti = $stmt->fetchAll();

$clienti = $pdo->query("SELECT id, nome FROM clienti ORDER BY nome ASC")->fetchAll();
$returnUrl = $_SERVER['REQUEST_URI'] ?? '';

$totDocumentiStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type = :docType");
$totDocumentiStmt->execute([':docType' => $docType]);
$totDocumenti = (int)$totDocumentiStmt->fetchColumn();

$totValoreStmt = $pdo->prepare("SELECT COALESCE(SUM(totale),0) FROM fatture WHERE document_type = :docType");
$totValoreStmt->execute([':docType' => $docType]);
$totValore = (float)$totValoreStmt->fetchColumn();

$totBozzeStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type = :docType AND stato = 'bozza'");
$totBozzeStmt->execute([':docType' => $docType]);
$totBozze = (int)$totBozzeStmt->fetchColumn();

$totEmesseStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type = :docType AND stato = 'emessa'");
$totEmesseStmt->execute([':docType' => $docType]);
$totEmesse = (int)$totEmesseStmt->fetchColumn();

// Ordini pagati con Stripe (hanno stripe_payment_intent_id popolato)
$totStripe = 0;
$totValoreStripe = 0.0;
try {
    $totStripeStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type = :docType AND stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id != ''");
    $totStripeStmt->execute([':docType' => $docType]);
    $totStripe = (int)$totStripeStmt->fetchColumn();

    $totValoreStripeStmt = $pdo->prepare("SELECT COALESCE(SUM(totale),0) FROM fatture WHERE document_type = :docType AND stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id != ''");
    $totValoreStripeStmt->execute([':docType' => $docType]);
    $totValoreStripe = (float)$totValoreStripeStmt->fetchColumn();
} catch (PDOException $e) {
    // Campo stripe_payment_intent_id potrebbe non esistere in vecchie installazioni
    error_log("Errore query Stripe: " . $e->getMessage());
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <?php require __DIR__ . '/partials/filtri.php'; ?>

    <?php if (isset($_GET['sync_ok'])): ?>
        <div class="alert success">✅ Ordini sincronizzati: <?php echo (int)$_GET['created']; ?> creati, <?php echo (int)$_GET['skipped']; ?> saltati.</div>
    <?php elseif (isset($_GET['sync_error'])): ?>
        <div class="alert error">⚠️ Errore sincronizzazione: <?php echo htmlspecialchars($_GET['sync_error']); ?></div>
    <?php endif; ?>

    <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;"><?php echo htmlspecialchars($docLabel); ?></h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn secondary" type="button" id="btn-sync-stripe" title="Importa ordini dai pagamenti Stripe">
                🔄 Sincronizza Stripe
            </button>
            <button class="btn" type="button" data-modal-open="modal-create">+ Nuovo ordine</button>
        </div>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div class="card">
            <h3>Totali</h3>
            <p><strong><?php echo number_format($totDocumenti, 0, ',', '.'); ?></strong> ordini</p>
        </div>
        <div class="card">
            <h3>Valore</h3>
            <p><strong>€<?php echo number_format($totValore, 2, ',', '.'); ?></strong></p>
        </div>
        <div class="card">
            <h3>Bozze</h3>
            <p><strong><?php echo number_format($totBozze, 0, ',', '.'); ?></strong></p>
        </div>
        <div class="card">
            <h3>Emesse</h3>
            <p><strong><?php echo number_format($totEmesse, 0, ',', '.'); ?></strong></p>
        </div>
        <div class="card" style="background:#f0f9ff;border-color:#bfdbfe;">
            <h3 style="color:#1e40af;">Pagati Stripe</h3>
            <p><strong style="color:#1e40af;"><?php echo number_format($totStripe, 0, ',', '.'); ?></strong> ordini</p>
        </div>
        <div class="card" style="background:#f0fdf4;border-color:#bbf7d0;">
            <h3 style="color:#15803d;">Valore Stripe</h3>
            <p><strong style="color:#15803d;">€<?php echo number_format($totValoreStripe, 2, ',', '.'); ?></strong></p>
        </div>
    </div>

    <div class="card">
        <h3>Lista <?php echo strtolower($docLabel); ?></h3>
        <div style="margin-bottom:10px;">
            <a class="btn secondary" href="esporta_csv.php">Esporta CSV</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Data</th>
                    <th>Stato</th>
                    <th>Pagamento</th>
                    <th>Totale</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documenti as $f): ?>
                <tr>
                    <td><?php echo htmlspecialchars($f['numero']); ?></td>
                    <td><?php echo htmlspecialchars($f['nome']); ?></td>
                    <td><?php echo htmlspecialchars($f['data_emissione']); ?></td>
                    <td><span class="badge <?php echo $f['stato']; ?>"><?php echo htmlspecialchars($f['stato']); ?></span></td>
                    <td>
                        <?php if (!empty($f['stripe_payment_intent_id'])): ?>
                            <span class="badge" style="background:#635bff;color:white;font-size:11px;">Stripe</span>
                        <?php elseif (!empty($f['metodo_pagamento'])): ?>
                            <span class="muted" style="font-size:12px;"><?php echo htmlspecialchars($f['metodo_pagamento']); ?></span>
                        <?php else: ?>
                            <span class="muted" style="font-size:12px;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format((float)$f['totale'], 2, ',', '.'); ?></td>
                    <td>
                        <a class="btn secondary" href="dettaglio.php?id=<?php echo $f['id']; ?>">Dettaglio</a>
                        <a class="btn" href="modifica.php?id=<?php echo $f['id']; ?>">Modifica</a>
                        <?php if (empty($f['fic_document_id'])): ?>
                            <a class="btn" href="../fatture/actions/fic_push.php?id=<?php echo $f['id']; ?>&return=<?php echo urlencode($returnUrl); ?>">Sincronizza FIC</a>
                        <?php else: ?>
                            <a class="btn secondary" href="../fatture/actions/fic_pdf.php?id=<?php echo $f['id']; ?>">PDF</a>
                            <?php if (!empty($f['fic_status'])): ?>
                                <span class="badge <?php echo htmlspecialchars($f['fic_status']); ?>"><?php echo htmlspecialchars($f['fic_status']); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="btn danger" href="../fatture/elimina_fattura.php?id=<?php echo $f['id']; ?>&return=<?php echo urlencode($returnUrl); ?>" onclick="return confirm('Eliminare?')">Elimina</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$documenti): ?>
                <tr><td colspan="7" class="muted">Nessun ordine.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/partials/paginazione.php'; ?>
</div>

<div class="modal" id="modal-create" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Nuovo ordine</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form id="fattura-form" method="post" action="../fatture/salva_fattura.php">
            <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($docType); ?>">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Cliente</label>
                    <select name="cliente_id" required>
                        <option value="">Seleziona</option>
                        <?php foreach ($clienti as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Numero</label>
                    <input type="text" name="numero" required>
                </div>
                <div>
                    <label>Data emissione</label>
                    <input type="date" name="data_emissione" required>
                </div>
                <div>
                    <label>Data scadenza</label>
                    <input type="date" name="data_scadenza">
                </div>
                <div>
                    <label>Stato</label>
                    <select name="stato">
                        <option value="bozza">Bozza</option>
                        <option value="emessa">Emessa</option>
                        <option value="pagata">Pagata</option>
                        <option value="scaduta">Scaduta</option>
                    </select>
                </div>
                <div>
                    <label>Totale</label>
                    <input type="number" step="0.01" name="totale" value="0.00">
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="3"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Salva</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        function openModal(id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('active');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modal) {
            modal.classList.remove('active');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn.getAttribute('data-modal-open'));
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modal = btn.closest('.modal');
                if (modal) closeModal(modal);
            });
        });

        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal(modal);
            });
        });

        // Gestione sincronizzazione Stripe
        var btnSync = document.getElementById('btn-sync-stripe');
        if (btnSync) {
            btnSync.addEventListener('click', function () {
                if (!confirm('Importare gli ordini dai pagamenti Stripe?\n\nVerranno creati ordini per tutti i pagamenti succeeded che non hanno ancora un ordine associato.')) {
                    return;
                }
                
                btnSync.disabled = true;
                btnSync.textContent = '⏳ Sincronizzazione...';
                
                fetch('sincronizza_ordini_stripe.php', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.ok) {
                        window.location.href = '?sync_ok=1&created=' + (data.created || 0) + '&skipped=' + (data.skipped || 0);
                    } else {
                        window.location.href = '?sync_error=' + encodeURIComponent(data.error || 'Errore sconosciuto');
                    }
                })
                .catch(function (err) {
                    btnSync.disabled = false;
                    btnSync.textContent = '🔄 Sincronizza Stripe';
                    alert('Errore durante la sincronizzazione: ' + err.message);
                });
            });
        }
    })();
</script>
<script src="../fatture/js/fatture.js"></script>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
