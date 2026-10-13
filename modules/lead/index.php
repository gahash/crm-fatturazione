<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/facebook_api.php';

$fbToken = fb_get_access_token();
$fbConnected = $fbToken !== '';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$data_da = isset($_GET['data_da']) ? trim((string)$_GET['data_da']) : '';
$data_a = isset($_GET['data_a']) ? trim((string)$_GET['data_a']) : '';
$stato = isset($_GET['stato']) ? strtolower(trim((string)$_GET['stato'])) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 25;
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'data_ricontatto';
$dir = isset($_GET['dir']) ? strtolower((string)$_GET['dir']) : 'asc';

if ($perPage < 10) $perPage = 10;
if ($perPage > 100) $perPage = 100;
if ($page < 1) $page = 1;

$sortableColumns = ['nome', 'citta', 'email', 'telefono', 'data_ricontatto', 'esito'];
if (!in_array($sort, $sortableColumns, true)) {
    $sort = 'data_ricontatto';
}
$dir = $dir === 'desc' ? 'DESC' : 'ASC';

$valid_da = null;
$valid_a = null;
if ($data_da !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $data_da);
    if ($d && $d->format('Y-m-d') === $data_da) $valid_da = $data_da;
}
if ($data_a !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $data_a);
    if ($d && $d->format('Y-m-d') === $data_a) $valid_a = $data_a;
}

function leadScoring(array $row): array
{
    $esito = strtolower(trim((string)($row['esito'] ?? '')));
    if (in_array($esito, ['acquistato', 'chiuso', 'convertito'], true)) {
        $score = 85;
    } elseif (in_array($esito, ['in valutazione', 'richiamare', 'contattato'], true)) {
        $score = 55;
    } else {
        $score = 25;
    }

    if (!empty($row['email'])) $score += 10;
    if (!empty($row['telefono'])) $score += 10;
    if (!empty($row['citta'])) $score += 5;

    $lastEventType = strtolower(trim((string)($row['last_event_type'] ?? '')));
    $lastEventAt = $row['last_event_at'] ?? null;
    $lastEventChannel = strtolower(trim((string)($row['last_event_channel'] ?? '')));
    if (in_array($lastEventType, ['reply', 'meeting', 'call', 'purchase'], true)) {
        $score += 25;
    } elseif (in_array($lastEventType, ['click', 'open'], true)) {
        $score += 15;
    }

    if (in_array($lastEventChannel, ['whatsapp', 'sms'], true)) {
        $score += 8;
    } elseif (in_array($lastEventChannel, ['email'], true)) {
        $score += 4;
    }

    $days = null;
    if ($lastEventAt) {
        $d = new DateTime($lastEventAt);
        $days = (int)$d->diff(new DateTime('today'))->format('%a');
    } else {
        $rawDate = trim((string)($row['data_ricontatto'] ?? ''));
        if ($rawDate !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $rawDate);
            if ($d) {
                $days = (int)$d->diff(new DateTime('today'))->format('%a');
            }
        }
    }

    if ($days !== null) {
        if ($days <= 7) $score += 20;
        elseif ($days <= 30) $score += 10;
        elseif ($days <= 90) $score += 5;
    }

    $score = max(0, min(100, $score));
    if ($score >= 80) {
        $label = 'Caldo';
        $rowClass = 'row-hot';
        $badge = 'badge-hot';
        $action = $lastEventType === 'purchase' ? 'Post-vendita' : ($score >= 90 ? 'Offerta e chiusura' : 'Follow-up di chiusura');
    } elseif ($score >= 50) {
        $label = 'Tiepido';
        $rowClass = 'row-warm';
        $badge = 'badge-warm';
        $action = in_array($lastEventType, ['reply', 'meeting', 'call'], true) ? 'Qualifica bisogni' : 'Demo / Richiamo';
    } else {
        $label = 'Freddo';
        $rowClass = 'row-cold';
        $badge = 'badge-cold';
        $action = (!empty($row['email']) || !empty($row['telefono'])) ? 'Primo contatto' : 'Completa dati';
    }

    return [
        'label' => $label,
        'score' => $score,
        'rowClass' => $rowClass,
        'badge' => $badge,
        'action' => $action,
    ];
}

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(l.nome LIKE :search OR l.citta LIKE :search OR l.email LIKE :search OR l.telefono LIKE :search OR l.esito LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($valid_da !== null && $valid_a !== null) {
    $conditions[] = "l.data_ricontatto BETWEEN :da AND :a";
    $params[':da'] = $valid_da;
    $params[':a'] = $valid_a;
} elseif ($valid_da !== null) {
    $conditions[] = "l.data_ricontatto >= :da";
    $params[':da'] = $valid_da;
} elseif ($valid_a !== null) {
    $conditions[] = "l.data_ricontatto <= :a";
    $params[':a'] = $valid_a;
}

if ($stato === 'caldo') {
    $conditions[] = "LOWER(l.esito) IN ('acquistato','chiuso','convertito')";
} elseif ($stato === 'tiepido') {
    $conditions[] = "LOWER(l.esito) IN ('in valutazione','richiamare','contattato')";
} elseif ($stato === 'freddo') {
    $conditions[] = "(l.esito IS NULL OR TRIM(l.esito) = '' OR LOWER(l.esito) NOT IN ('acquistato','chiuso','convertito','in valutazione','richiamare','contattato'))";
}

$where = $conditions ? "WHERE " . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) AS total FROM lead l $where";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT l.id, l.nome, l.cognome, l.citta, l.email, l.telefono, l.data_ricontatto, l.esito,
    ev.type AS last_event_type,
    ev.channel AS last_event_channel,
    ev.created_at AS last_event_at
    FROM lead l
    LEFT JOIN (
        SELECT lead_id, MAX(id) AS last_event_id
        FROM lead_events
        GROUP BY lead_id
    ) ev_last ON ev_last.lead_id = l.id
    LEFT JOIN lead_events ev ON ev.id = ev_last.last_event_id
    $where
    ORDER BY l.$sort $dir
    LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$lead = $stmt->fetchAll(PDO::FETCH_ASSOC);

function q(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) unset($query[$k]);
    }
    return '?' . http_build_query($query);
}

function sortIcon(string $col, string $currentCol, string $dir): string
{
    if ($col !== $currentCol) return '↕︎';
    return ($dir === 'ASC') ? '↑' : '↓';
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <?php if (isset($_GET['success'])): ?>
        <div class="alert success">✅ Operazione completata.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert error">⚠️ Operazione non completata.</div>
    <?php endif; ?>

    <div class="card full-width">
        <div class="top-bar" style="gap:10px;">
            <h2>🧲 Lead</h2>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <form method="get" class="top-bar-form">
                    <input class="input-search" type="text" name="search" placeholder="🔍 Cerca nome, città, email, telefono, esito…" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="date" name="data_da" value="<?php echo htmlspecialchars($data_da, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="date" name="data_a" value="<?php echo htmlspecialchars($data_a, ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="per-page">
                        <span>Stato</span>
                        <select name="stato" onchange="this.form.submit()">
                            <option value="" <?php echo $stato === '' ? 'selected' : ''; ?>>Tutti</option>
                            <option value="freddo" <?php echo $stato === 'freddo' ? 'selected' : ''; ?>>Freddo</option>
                            <option value="tiepido" <?php echo $stato === 'tiepido' ? 'selected' : ''; ?>>Tiepido</option>
                            <option value="caldo" <?php echo $stato === 'caldo' ? 'selected' : ''; ?>>Caldo</option>
                        </select>
                    </label>
                    <label class="per-page">
                        <span>Per pagina</span>
                        <select name="perPage" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50, 100] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn" type="submit">Filtra</button>
                    <a class="btn secondary" href="index.php">Reset</a>
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($dir), ENT_QUOTES, 'UTF-8'); ?>">
                </form>
                <button class="btn" type="button" data-modal-open="modal-create">+ Nuovo</button>
                <a class="btn secondary" href="esporta_csv.php">⬇️ Esporta CSV</a>
                <button class="btn secondary" type="button" data-modal-open="modal-import">⬆️ Importa CSV</button>
                <a class="btn" href="facebook_connect.php">Connetti Facebook</a>
                <a class="btn secondary" href="facebook_sync.php">Sincronizza Facebook</a>
            </div>
        </div>

        <div class="list-meta" style="margin-top:8px;">
            <div>
                <span class="badge <?php echo $fbConnected ? 'ok' : 'ko'; ?>">
                    <?php echo $fbConnected ? 'Facebook connesso' : 'Facebook non connesso'; ?>
                </span>
            </div>
        </div>

        <div class="list-meta">
            <div>
                <strong><?php echo number_format($totalRows, 0, ',', '.'); ?></strong> risultati
                <?php if ($totalRows > 0): ?>
                    • Pagina <?php echo $page; ?> di <?php echo $totalPages; ?>
                <?php endif; ?>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="pager">
                    <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>
                    <a class="btn <?php echo $prevDisabled ? 'btn-disabled' : ''; ?>" href="<?php echo $prevDisabled ? '#' : q(['page' => $page - 1]); ?>" <?php echo $prevDisabled ? 'aria-disabled="true"' : ''; ?>>← Prec.</a>
                    <a class="btn <?php echo $nextDisabled ? 'btn-disabled' : ''; ?>" href="<?php echo $nextDisabled ? '#' : q(['page' => $page + 1]); ?>" <?php echo $nextDisabled ? 'aria-disabled="true"' : ''; ?>>Succ. →</a>
                </div>
            <?php endif; ?>
        </div>

        <table class="clienti-table">
            <thead>
                <tr>
                    <?php foreach ([
                        'nome' => 'Nome',
                        'citta' => 'Città',
                        'email' => 'Email',
                        'telefono' => 'Telefono',
                        'data_ricontatto' => 'Data Ricontatto',
                        'esito' => 'Esito'
                    ] as $col => $label):
                        $nextDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
                        ?>
                        <th>
                            <a href="<?php echo q(['sort' => $col, 'dir' => $nextDir, 'page' => 1]); ?>" style="text-decoration:none;">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                <span style="opacity:.7;margin-left:4px;"><?php echo sortIcon($col, $sort, $dir); ?></span>
                            </a>
                        </th>
                    <?php endforeach; ?>
                    <th>Stato</th>
                    <th>Score</th>
                    <th>Workflow</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$lead): ?>
                    <tr><td colspan="10" style="text-align:center;">Nessun lead trovato.</td></tr>
                <?php else: foreach ($lead as $l): ?>
                    <?php $status = leadScoring($l); ?>
                    <tr class="<?php echo htmlspecialchars($status['rowClass'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td><?php echo htmlspecialchars($l['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($l['citta'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($l['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($l['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($l['data_ricontatto'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($l['esito'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge-score <?php echo $status['badge']; ?>"><?php echo htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td class="score-num"><?php echo (int)$status['score']; ?></td>
                        <td class="workflow-tip"><?php echo htmlspecialchars($status['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn" data-modal-open="modal-event" data-lead-id="<?php echo (int)$l['id']; ?>" data-lead-name="<?php echo htmlspecialchars($l['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">⚡ Evento</button>
                            <?php if (strtolower((string)($l['esito'] ?? '')) === 'acquistato'): ?>
                                <a class="btn" href="converti.php?id=<?php echo (int)$l['id']; ?>" onclick="return confirm('Convertire questo lead in cliente?')">🔁 Converti</a>
                            <?php endif; ?>
                            <a href="elimina.php?id=<?php echo (int)$l['id']; ?>" class="btn danger" onclick="return confirm('Eliminare questo lead?')">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($totalRows > 0): ?>
            <div class="list-footer">
                <div>
                    Mostrati <?php echo number_format(count($lead), 0, ',', '.'); ?> di <?php echo number_format($totalRows, 0, ',', '.'); ?> risultati
                </div>
                <div class="pager">
                    <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>
                    <a class="btn <?php echo $prevDisabled ? 'btn-disabled' : ''; ?>" href="<?php echo $prevDisabled ? '#' : q(['page' => $page - 1]); ?>" <?php echo $prevDisabled ? 'aria-disabled="true"' : ''; ?>>← Prec.</a>
                    <a class="btn <?php echo $nextDisabled ? 'btn-disabled' : ''; ?>" href="<?php echo $nextDisabled ? '#' : q(['page' => $page + 1]); ?>" <?php echo $nextDisabled ? 'aria-disabled="true"' : ''; ?>>Succ. →</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="modal-create" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Aggiungi Lead</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="lead_store.php">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" required>
                </div>
                <div>
                    <label>Cognome</label>
                    <input type="text" name="cognome" required>
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono" required>
                </div>
                <div>
                    <label>Città</label>
                    <input type="text" name="citta">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div>
                    <label>Data Ricontatto</label>
                    <input type="date" name="data_ricontatto" required>
                </div>
                <div>
                    <label>Esito</label>
                    <select name="esito" required>
                        <option value="">Seleziona...</option>
                        <option value="interessato">Interessato</option>
                        <option value="acquistato">Acquistato</option>
                        <option value="non_interessato">Non interessato</option>
                    </select>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="2"></textarea>
                </div>
                <div>
                    <label>Servizio acquistato</label>
                    <input type="text" name="servizio_acquistato">
                </div>
                <div>
                    <label>Costo servizio</label>
                    <input type="number" name="costo_servizio" min="0" step="0.01">
                </div>
                <div>
                    <label>Destinazione</label>
                    <select name="destinazione">
                        <option value="preventivo">Preventivo</option>
                        <option value="proforma">Fattura Proforma</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Salva Lead</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-import" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Importa Lead da CSV</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="importa_csv.php" enctype="multipart/form-data">
            <div style="margin-top:12px;">
                <input type="file" name="file" accept=".csv" required>
                <p class="muted" style="margin-top:8px;">Header atteso: nome,cognome,email,telefono,citta,data_ricontatto,esito,note,servizio_acquistato,costo_servizio,destinazione</p>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Importa</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-event" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Registra evento lead</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="lead_event.php" id="form-lead-event">
            <input type="hidden" name="lead_id" value="">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Lead</label>
                    <input type="text" name="lead_name" value="" disabled>
                </div>
                <div>
                    <label>Tipo evento</label>
                    <select name="type">
                        <option value="open">Apertura</option>
                        <option value="click">Click</option>
                        <option value="reply">Risposta</option>
                        <option value="call">Telefonata</option>
                        <option value="meeting">Meeting</option>
                        <option value="purchase">Acquisto</option>
                        <option value="close">Chiusura</option>
                    </select>
                </div>
                <div>
                    <label>Canale</label>
                    <select name="channel">
                        <option value="email">Email</option>
                        <option value="sms">SMS</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="phone">Telefono</option>
                        <option value="web">Web</option>
                        <option value="altro">Altro</option>
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
                var target = btn.getAttribute('data-modal-open');
                if (target === 'modal-event') {
                    var form = document.getElementById('form-lead-event');
                    if (form) {
                        form.querySelector('input[name="lead_id"]').value = btn.getAttribute('data-lead-id') || '';
                        form.querySelector('input[name="lead_name"]').value = btn.getAttribute('data-lead-name') || '';
                    }
                }
                openModal(target);
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
    })();
</script>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
