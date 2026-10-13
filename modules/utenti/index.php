<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$stmt = $pdo->query("SELECT id, nome, email, ruolo, attivo, creato_il FROM utenti ORDER BY creato_il DESC");
$utenti = $stmt->fetchAll();

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <?php if (isset($_GET['success'])): ?>
        <div class="alert success">✅ Operazione completata.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert error">⚠️ Operazione non completata.</div>
    <?php endif; ?>

    <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Utenti</h2>
        <button class="btn" type="button" data-modal-open="modal-create">+ Nuovo utente</button>
    </div>

    <div class="card">
        <h3>Lista utenti</h3>
        <table class="clienti-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Ruolo</th>
                    <th>Attivo</th>
                    <th>Creato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($utenti as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['nome']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['ruolo']); ?></td>
                    <td><?php echo (int)$u['attivo'] === 1 ? 'Sì' : 'No'; ?></td>
                    <td><?php echo htmlspecialchars($u['creato_il']); ?></td>
                    <td>
                        <?php if ((int)$u['id'] !== (int)($user['id'] ?? 0)): ?>
                            <a class="btn danger" href="utenti_delete.php?id=<?php echo $u['id']; ?>" onclick="return confirm('Eliminare questo utente?')">Elimina</a>
                        <?php else: ?>
                            <span class="muted">(tu)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$utenti): ?>
                <tr><td colspan="6" class="muted">Nessun utente.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="modal-create" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Nuovo utente</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="utenti_store.php">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" required>
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div>
                    <label>Ruolo</label>
                    <select name="ruolo" required>
                        <option value="admin">Admin</option>
                        <option value="ragioneria">Ragioneria</option>
                    </select>
                </div>
                <div>
                    <label>Attivo</label>
                    <select name="attivo">
                        <option value="1">Sì</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Salva</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
