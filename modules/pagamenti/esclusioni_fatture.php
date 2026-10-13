<?php
require_once __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

function exclusion_storage_path(): string
{
    return __DIR__ . '/../../tmp/fatture_exclusions.json';
}

function exclusion_load_data(): array
{
    $path = exclusion_storage_path();
    if (!is_file($path)) {
        return [
            'updated_at' => null,
            'updated_by' => null,
            'source' => null,
            'items' => [],
        ];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [
            'updated_at' => null,
            'updated_by' => null,
            'source' => null,
            'items' => [],
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'updated_at' => null,
            'updated_by' => null,
            'source' => null,
            'items' => [],
        ];
    }

    if (!isset($decoded['items']) || !is_array($decoded['items'])) {
        $decoded['items'] = [];
    }

    return $decoded;
}

function exclusion_save_data(array $payload): bool
{
    $dir = __DIR__ . '/../../tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return false;
    }

    return @file_put_contents(exclusion_storage_path(), $body, LOCK_EX) !== false;
}

function exclusion_header_norm(string $txt): string
{
    $txt = strtolower(trim($txt));
    $txt = str_replace(['\r', '\n', '\t'], ' ', $txt);
    $txt = str_replace(['-', '/', '.', ':'], ' ', $txt);
    $txt = preg_replace('/\s+/', ' ', $txt) ?: $txt;
    return trim($txt);
}

function exclusion_detect_field(string $header): string
{
    $h = exclusion_header_norm($header);
    $map = [
        'cliente_id' => ['cliente id', 'id cliente', 'cliente_id', 'id_cliente', 'id'],
        'email' => ['email', 'e mail', 'mail'],
        'piva' => ['piva', 'partita iva', 'partita_iva', 'vat', 'vat number', 'vat_number'],
        'nome' => ['nome', 'cliente', 'ragione sociale', 'ragione_sociale', 'nominativo'],
        'azienda' => ['azienda', 'company', 'impresa'],
        'stripe_id' => ['stripe id', 'stripe_id', 'payment_intent', 'payment intent'],
        'motivo' => ['motivo', 'note', 'reason', 'causale'],
    ];

    foreach ($map as $field => $aliases) {
        foreach ($aliases as $alias) {
            if ($h === $alias) {
                return $field;
            }
        }
    }

    return '';
}

function exclusion_parse_csv(string $path): array
{
    $rows = [];
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }

    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return [];
    }

    $delim = ';';
    if (substr_count($first, ';') < substr_count($first, ',')) {
        $delim = ',';
    }

    rewind($fh);
    while (($data = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = $data;
    }
    fclose($fh);

    return $rows;
}

function exclusion_col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
    $num = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $num = ($num * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $num - 1);
}

function exclusion_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                    continue;
                }
                $text = '';
                if (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)($r->t ?? '');
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (is_string($workbookXml) && is_string($relsXml)) {
        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $attrs = $wb->sheets->sheet[0]->attributes('r', true);
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            if ($rid !== '') {
                foreach ($rels->Relationship as $rel) {
                    $a = $rel->attributes();
                    if ((string)($a['Id'] ?? '') === $rid) {
                        $target = (string)($a['Target'] ?? 'worksheets/sheet1.xml');
                        $sheetPath = 'xl/' . ltrim($target, '/');
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if (!is_string($sheetXml) || $sheetXml === '') {
        return [];
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        $row = [];
        foreach ($rowNode->c as $cell) {
            $ref = (string)($cell['r'] ?? '');
            $idx = exclusion_col_index($ref);
            $type = (string)($cell['t'] ?? '');
            $val = '';

            if ($type === 's') {
                $sharedIdx = (int)($cell->v ?? -1);
                $val = $sharedStrings[$sharedIdx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($cell->is->t ?? '');
            } else {
                $val = (string)($cell->v ?? '');
            }

            $row[$idx] = trim($val);
        }

        if (!empty($row)) {
            ksort($row);
            $max = (int)max(array_keys($row));
            $flat = [];
            for ($i = 0; $i <= $max; $i++) {
                $flat[] = (string)($row[$i] ?? '');
            }
            $rows[] = $flat;
        }
    }

    return $rows;
}

function exclusion_build_items(array $rows, string $defaultReason): array
{
    if (empty($rows)) {
        return [];
    }

    $headers = array_shift($rows);
    $headerMap = [];
    foreach ($headers as $idx => $header) {
        $field = exclusion_detect_field((string)$header);
        if ($field !== '') {
            $headerMap[(int)$idx] = $field;
        }
    }

    $items = [];
    $seen = [];

    foreach ($rows as $row) {
        $item = [
            'cliente_id' => 0,
            'email' => '',
            'piva' => '',
            'nome' => '',
            'azienda' => '',
            'stripe_id' => '',
            'motivo' => $defaultReason,
        ];

        foreach ($headerMap as $idx => $field) {
            $val = trim((string)($row[$idx] ?? ''));
            if ($field === 'cliente_id') {
                $item['cliente_id'] = (int)$val;
            } elseif ($field === 'motivo') {
                if ($val !== '') {
                    $item['motivo'] = $val;
                }
            } else {
                $item[$field] = $val;
            }
        }

        $item['email'] = strtolower(trim($item['email']));
        $item['piva'] = strtoupper(preg_replace('/\s+/', '', (string)$item['piva']) ?: (string)$item['piva']);
        $item['nome'] = trim($item['nome']);
        $item['azienda'] = trim($item['azienda']);
        $item['stripe_id'] = trim($item['stripe_id']);
        $item['motivo'] = trim($item['motivo']) !== '' ? trim($item['motivo']) : $defaultReason;

        if ($item['cliente_id'] <= 0 && $item['email'] === '' && $item['piva'] === '' && $item['nome'] === '' && $item['azienda'] === '' && $item['stripe_id'] === '') {
            continue;
        }

        $signature = implode('|', [
            (string)$item['cliente_id'],
            $item['email'],
            $item['piva'],
            strtolower($item['nome']),
            strtolower($item['azienda']),
            strtolower($item['stripe_id']),
        ]);
        if (isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;
        $items[] = $item;
    }

    return $items;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'clear') {
        $payload = [
            'updated_at' => date('c'),
            'updated_by' => (string)($user['email'] ?? $user['nome'] ?? 'admin'),
            'source' => 'manual_clear',
            'items' => [],
        ];
        if (exclusion_save_data($payload)) {
            $message = 'Lista esclusioni azzerata.';
        } else {
            $error = 'Impossibile salvare il file di esclusione.';
        }
    }

    if ($action === 'upload') {
        $reason = trim((string)($_POST['motivo_default'] ?? 'Esclusione eccezionale da upload'));
        $file = $_FILES['file_excel'] ?? null;

        if (!$file || !isset($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            $error = 'File non valido.';
        } else {
            $name = (string)($file['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $rows = [];

            if ($ext === 'csv') {
                $rows = exclusion_parse_csv((string)$file['tmp_name']);
            } elseif ($ext === 'xlsx') {
                $rows = exclusion_parse_xlsx((string)$file['tmp_name']);
            } else {
                $error = 'Formato non supportato. Usa .xlsx o .csv';
            }

            if ($error === '') {
                $items = exclusion_build_items($rows, $reason);
                if (empty($items)) {
                    $error = 'Nessuna riga valida trovata nel file. Controlla intestazioni (es: email, piva, cliente_id, nome, azienda, stripe_id).';
                } else {
                    $payload = [
                        'updated_at' => date('c'),
                        'updated_by' => (string)($user['email'] ?? $user['nome'] ?? 'admin'),
                        'source' => $name,
                        'items' => $items,
                    ];

                    if (exclusion_save_data($payload)) {
                        $message = 'Lista esclusioni aggiornata. Righe valide importate: ' . count($items);
                    } else {
                        $error = 'Errore salvataggio file esclusioni.';
                    }
                }
            }
        }
    }
}

$data = exclusion_load_data();
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

$page_title = 'Esclusioni invio fatture';
require __DIR__ . '/../../layouts/header.php';
?>

<div class="wrap" style="max-width:1100px;margin:0 auto;">
    <div class="card" style="margin-bottom:16px;">
        <h2 style="margin:0 0 8px 0;">Esclusioni invio fatture (upload file)</h2>
        <p class="muted" style="margin:0;">Pagina separata per gestire clienti da escludere dall'invio automatico fatture in sincronizzazione pagamenti.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="card" style="border-left:4px solid #10b981;margin-bottom:12px;">
            <strong><?php echo htmlspecialchars($message); ?></strong>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="card" style="border-left:4px solid #ef4444;margin-bottom:12px;">
            <strong><?php echo htmlspecialchars($error); ?></strong>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">Carica file Excel/CSV</h3>
        <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px;max-width:760px;">
            <input type="hidden" name="action" value="upload">
            <label>
                <div style="font-weight:600;margin-bottom:4px;">File</div>
                <input type="file" name="file_excel" accept=".xlsx,.csv" required>
            </label>
            <label>
                <div style="font-weight:600;margin-bottom:4px;">Motivo di default</div>
                <input type="text" name="motivo_default" value="Esclusione eccezionale da upload" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
            </label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" class="btn">Importa esclusioni</button>
            </div>
        </form>
        <p class="muted" style="margin-top:10px;">Intestazioni consigliate: cliente_id, email, piva, nome, azienda, stripe_id, motivo.</p>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">Stato lista corrente</h3>
        <p class="muted" style="margin:0 0 8px 0;">Totale esclusioni: <strong><?php echo number_format(count($items), 0, ',', '.'); ?></strong></p>
        <p class="muted" style="margin:0 0 8px 0;">Ultimo aggiornamento: <?php echo htmlspecialchars((string)($data['updated_at'] ?? '-')); ?></p>
        <p class="muted" style="margin:0 0 8px 0;">Aggiornato da: <?php echo htmlspecialchars((string)($data['updated_by'] ?? '-')); ?></p>
        <p class="muted" style="margin:0 0 12px 0;">Sorgente: <?php echo htmlspecialchars((string)($data['source'] ?? '-')); ?></p>

        <form method="post" onsubmit="return confirm('Confermi azzeramento lista esclusioni?');">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn secondary">Azzera lista</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Anteprima righe (max 200)</h3>
        <?php if (empty($items)): ?>
            <p class="muted">Nessuna esclusione caricata.</p>
        <?php else: ?>
            <div style="overflow:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Cliente ID</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Email</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">P.IVA</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Nome</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Azienda</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Stripe ID</th>
                            <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;">Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($items, 0, 200) as $row): ?>
                            <tr>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo (int)($row['cliente_id'] ?? 0); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['email'] ?? '')); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['piva'] ?? '')); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['nome'] ?? '')); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['azienda'] ?? '')); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['stripe_id'] ?? '')); ?></td>
                                <td style="border-bottom:1px solid #f1f5f9;padding:8px;"><?php echo htmlspecialchars((string)($row['motivo'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
