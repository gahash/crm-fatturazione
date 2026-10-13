<?php
require __DIR__ . '/../../auth.php';
require_login();
if (!has_admin_access()) {
    http_response_code(403);
    die('Accesso non autorizzato.');
}

$pdo = require __DIR__ . '/../../config/db.php';

function verifica_norm_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return is_string($value) ? $value : '';
}

function verifica_norm_name(string $value): string
{
    return strtolower(verifica_norm_text($value));
}

function verifica_norm_piva(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value);
    return is_string($value) ? $value : '';
}

function verifica_norm_sdi(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value);
    return is_string($value) ? $value : '';
}

function verifica_norm_pec(string $value): string
{
    return strtolower(trim($value));
}

function verifica_split_columns(string $line): array
{
    if (strpos($line, "\t") !== false) {
        return array_map('trim', explode("\t", $line));
    }
    if (substr_count($line, ';') >= 2) {
        return array_map('trim', str_getcsv($line, ';'));
    }
    if (substr_count($line, ',') >= 2) {
        return array_map('trim', str_getcsv($line, ','));
    }
    return [trim($line)];
}

function verifica_norm_header(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['_', '-', '.', '/', '\\', '(', ')'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function verifica_detect_field(string $header): string
{
    $h = verifica_norm_header($header);
    if ($h === '') {
        return '';
    }

    if (in_array($h, ['id', 'stripe id', 'stripe customer id', 'customer id', 'stripe_customer_id'], true)) {
        return 'stripe_customer_id';
    }
    if (in_array($h, ['email', 'e mail', 'mail'], true)) {
        return 'email';
    }
    if (in_array($h, ['nome', 'name', 'cliente', 'ragione sociale', 'azienda', 'company'], true)) {
        return 'nome';
    }
    if (in_array($h, ['piva', 'partita iva', 'vat', 'vat number', 'partitaiva'], true)) {
        return 'piva';
    }
    if (in_array($h, ['codice sdi', 'sdi', 'codice destinatario', 'codice univoco'], true)) {
        return 'codice_sdi';
    }
    if (in_array($h, ['pec', 'posta certificata', 'email pec'], true)) {
        return 'pec';
    }

    return '';
}

function verifica_col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?: '';
    $num = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $num = ($num * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $num - 1);
}

function verifica_parse_csv_file(string $path): array
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
        $rows[] = array_map(static function ($v) {
            return trim((string)$v);
        }, $data);
    }
    fclose($fh);

    return $rows;
}

function verifica_parse_xlsx_file(string $path): array
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
            $idx = verifica_col_index($ref);
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

function verifica_parse_matrix_rows(array $matrix): array
{
    if (!$matrix) {
        return [];
    }

    $rows = [];
    $header = array_map(static function ($v) {
        return trim((string)$v);
    }, (array)($matrix[0] ?? []));

    $headerMap = [];
    foreach ($header as $idx => $colName) {
        $field = verifica_detect_field($colName);
        if ($field !== '') {
            $headerMap[(int)$idx] = $field;
        }
    }

    $useHeader = !empty($headerMap);
    $start = $useHeader ? 1 : 0;

    for ($rIdx = $start; $rIdx < count($matrix); $rIdx++) {
        $cols = array_map(static function ($v) {
            return trim((string)$v);
        }, (array)$matrix[$rIdx]);

        if (!$cols || count(array_filter($cols, static function ($v) { return $v !== ''; })) === 0) {
            continue;
        }

        $item = [
            'line_no' => $rIdx + 1,
            'stripe_customer_id' => '',
            'nome' => '',
            'email' => '',
            'piva' => '',
            'codice_sdi' => '',
            'pec' => '',
        ];

        if ($useHeader) {
            foreach ($headerMap as $idx => $field) {
                $item[$field] = (string)($cols[$idx] ?? '');
            }
        } else {
            $cleanCols = array_values(array_filter($cols, static function ($v) {
                return $v !== '';
            }));

            $item['nome'] = (string)($cleanCols[0] ?? '');
            if (count($cleanCols) >= 5 && preg_match('/^cus_/i', (string)$cleanCols[0]) && filter_var((string)$cleanCols[1], FILTER_VALIDATE_EMAIL)) {
                $item['stripe_customer_id'] = (string)$cleanCols[0];
                $item['email'] = (string)$cleanCols[1];
                $item['nome'] = (string)$cleanCols[2];
                $item['piva'] = (string)$cleanCols[4];
            } elseif (count($cleanCols) >= 5) {
                $item['email'] = (string)$cleanCols[1];
                $item['piva'] = (string)$cleanCols[2];
                $item['codice_sdi'] = (string)$cleanCols[3];
                $item['pec'] = (string)$cleanCols[4];
            } elseif (count($cleanCols) === 4) {
                if (filter_var((string)$cleanCols[1], FILTER_VALIDATE_EMAIL)) {
                    $item['email'] = (string)$cleanCols[1];
                    $item['piva'] = (string)$cleanCols[2];
                    $item['codice_sdi'] = (string)$cleanCols[3];
                } else {
                    $item['piva'] = (string)$cleanCols[1];
                    $item['codice_sdi'] = (string)$cleanCols[2];
                    $item['pec'] = (string)$cleanCols[3];
                }
            } elseif (count($cleanCols) === 3) {
                $item['piva'] = (string)$cleanCols[1];
                $item['codice_sdi'] = (string)$cleanCols[2];
            } elseif (count($cleanCols) === 2) {
                if (filter_var((string)$cleanCols[1], FILTER_VALIDATE_EMAIL)) {
                    $item['email'] = (string)$cleanCols[1];
                } else {
                    $item['piva'] = (string)$cleanCols[1];
                }
            }
        }

        $rows[] = [
            'line_no' => (int)$item['line_no'],
            'stripe_customer_id' => verifica_norm_text((string)$item['stripe_customer_id']),
            'nome' => verifica_norm_text((string)$item['nome']),
            'email' => verifica_norm_text((string)$item['email']),
            'piva' => verifica_norm_piva((string)$item['piva']),
            'codice_sdi' => verifica_norm_sdi((string)$item['codice_sdi']),
            'pec' => verifica_norm_pec((string)$item['pec']),
        ];
    }

    return $rows;
}

function verifica_parse_input_rows(string $raw): array
{
    $matrix = [];
    $lines = preg_split('/\R/u', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $matrix[] = verifica_split_columns($line);
    }
    return verifica_parse_matrix_rows($matrix);
}

function verifica_find_match(PDO $pdo, array $row): array
{
    $stripeCustomerId = trim((string)($row['stripe_customer_id'] ?? ''));
    $email = strtolower(trim((string)($row['email'] ?? '')));
    $piva = verifica_norm_piva((string)($row['piva'] ?? ''));
    $nome = verifica_norm_name((string)($row['nome'] ?? ''));

    if ($stripeCustomerId !== '') {
        $stmt = $pdo->prepare("SELECT c.id, c.nome, c.azienda, c.email, c.piva, c.codice_sdi, c.pec
            FROM stripe_sync ss
            INNER JOIN clienti c ON c.id = ss.cliente_id
            WHERE ss.stripe_customer_id = :sid
            LIMIT 2");
        $stmt->execute([':sid' => $stripeCustomerId]);
        $m = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($m) === 1) {
            return ['type' => 'stripe_id', 'cliente' => $m[0]];
        }
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, nome, azienda, email, piva, codice_sdi, pec FROM clienti WHERE LOWER(TRIM(email)) = :email LIMIT 2");
        $stmt->execute([':email' => $email]);
        $m = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($m) === 1) {
            return ['type' => 'email', 'cliente' => $m[0]];
        }
    }

    if ($piva !== '') {
        $stmt = $pdo->prepare("SELECT id, nome, azienda, email, piva, codice_sdi, pec
            FROM clienti
            WHERE REPLACE(REPLACE(UPPER(TRIM(COALESCE(piva,''))), '.', ''), ' ', '') = :piva
            LIMIT 2");
        $stmt->execute([':piva' => $piva]);
        $m = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($m) === 1) {
            return ['type' => 'piva', 'cliente' => $m[0]];
        }
    }

    if ($nome !== '') {
        $stmt = $pdo->prepare("SELECT id, nome, azienda, email, piva, codice_sdi, pec
            FROM clienti
            WHERE LOWER(TRIM(COALESCE(nome, ''))) = :n
               OR LOWER(TRIM(COALESCE(azienda, ''))) = :n
            ORDER BY id DESC
            LIMIT 2");
        $stmt->execute([':n' => $nome]);
        $m = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($m) === 1) {
            return ['type' => 'nome', 'cliente' => $m[0]];
        }
        if (count($m) > 1) {
            return ['type' => 'ambiguous', 'cliente' => null];
        }
    }

    return ['type' => 'none', 'cliente' => null];
}

$action = (string)($_POST['action'] ?? '');
$rawInput = '';
$previewRows = [];
$messages = [];
$uploadedFileName = '';

if ($action === 'preview') {
    $rawInput = (string)($_POST['raw_input'] ?? '');
    $parsedRows = [];

    if (!empty($_FILES['data_file']['name'] ?? '')) {
        $uploadedFileName = basename((string)$_FILES['data_file']['name']);
        $tmpPath = (string)($_FILES['data_file']['tmp_name'] ?? '');
        $uploadError = (int)($_FILES['data_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        $ext = strtolower((string)pathinfo($uploadedFileName, PATHINFO_EXTENSION));

        if ($uploadError === UPLOAD_ERR_OK && $tmpPath !== '') {
            if ($ext === 'csv') {
                $matrix = verifica_parse_csv_file($tmpPath);
                $parsedRows = array_merge($parsedRows, verifica_parse_matrix_rows($matrix));
            } elseif ($ext === 'xlsx') {
                $matrix = verifica_parse_xlsx_file($tmpPath);
                $parsedRows = array_merge($parsedRows, verifica_parse_matrix_rows($matrix));
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Formato file non supportato. Carica un file .xlsx oppure .csv.'];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Upload file non riuscito. Riprova.'];
        }
    }

    if (trim($rawInput) !== '') {
        $parsedRows = array_merge($parsedRows, verifica_parse_input_rows($rawInput));
    }

    foreach ($parsedRows as $row) {
        $match = verifica_find_match($pdo, $row);
        $cliente = $match['cliente'];
        $previewRows[] = [
            'line_no' => (int)$row['line_no'],
            'stripe_customer_id' => (string)$row['stripe_customer_id'],
            'nome' => (string)$row['nome'],
            'email' => (string)$row['email'],
            'piva' => (string)$row['piva'],
            'codice_sdi' => (string)$row['codice_sdi'],
            'pec' => (string)$row['pec'],
            'match_type' => (string)$match['type'],
            'cliente_id' => (int)($cliente['id'] ?? 0),
            'cliente_nome' => (string)($cliente['nome'] ?? ''),
            'cliente_azienda' => (string)($cliente['azienda'] ?? ''),
            'cliente_email' => (string)($cliente['email'] ?? ''),
            'cliente_piva' => (string)($cliente['piva'] ?? ''),
            'cliente_sdi' => (string)($cliente['codice_sdi'] ?? ''),
            'cliente_pec' => (string)($cliente['pec'] ?? ''),
        ];
    }

    if ($previewRows) {
        $sourceText = $uploadedFileName !== '' ? ('File: ' . $uploadedFileName . '. ') : '';
        $messages[] = [
            'type' => 'success',
            'text' => $sourceText . 'Righe lette: ' . count($previewRows) . '. Verifica il matching e conferma il salvataggio.',
        ];
    } elseif (!$messages) {
        $messages[] = [
            'type' => 'error',
            'text' => 'Nessuna riga valida trovata. Incolla i dati oppure carica un file .xlsx/.csv.',
        ];
    }
}

if ($action === 'save') {
    $postedRows = $_POST['rows'] ?? [];
    if (!is_array($postedRows)) {
        $postedRows = [];
    }

    $updated = 0;
    $skipped = 0;
    $errors = 0;

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("
            UPDATE clienti
            SET
                piva = CASE WHEN :piva <> '' THEN :piva ELSE piva END,
                codice_sdi = CASE WHEN :codice_sdi <> '' THEN :codice_sdi ELSE codice_sdi END,
                pec = CASE WHEN :pec <> '' THEN :pec ELSE pec END
            WHERE id = :id
            LIMIT 1
        ");

        foreach ($postedRows as $r) {
            if (!is_array($r)) {
                $skipped++;
                continue;
            }
            $apply = (string)($r['apply'] ?? '') === '1';
            $clienteId = (int)($r['cliente_id'] ?? 0);
            if (!$apply || $clienteId <= 0) {
                $skipped++;
                continue;
            }

            $piva = verifica_norm_piva((string)($r['piva'] ?? ''));
            $sdi = verifica_norm_sdi((string)($r['codice_sdi'] ?? ''));
            $pec = verifica_norm_pec((string)($r['pec'] ?? ''));

            $upd->execute([
                ':id' => $clienteId,
                ':piva' => $piva,
                ':codice_sdi' => $sdi,
                ':pec' => $pec,
            ]);

            if ($upd->rowCount() >= 0) {
                $updated++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errors++;
        $messages[] = ['type' => 'error', 'text' => 'Errore salvataggio: ' . substr((string)$e->getMessage(), 0, 250)];
    }

    if ($errors === 0) {
        $messages[] = ['type' => 'success', 'text' => "Aggiornamento completato. Aggiornati: {$updated}. Saltati: {$skipped}."];
    }
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <div class="card" style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 style="margin:0;">Verifica anagrafiche e-fattura</h2>
            <a class="btn secondary" href="clienti.php">&larr; Torna ai clienti</a>
        </div>
        <p class="muted" style="margin-top:8px;">
            Carica file o incolla da Excel colonne: <strong>Cliente | Email | P.IVA | Codice SDI | PEC</strong>.
            Supporta anche tracciato Stripe con <strong>ID | Email | Name | PARTITA IVA</strong>.
        </p>
    </div>

    <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo $msg['type'] === 'error' ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars((string)$msg['text']); ?>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <label for="data_file"><strong>File Excel/CSV (opzionale)</strong></label>
            <input id="data_file" type="file" name="data_file" accept=".xlsx,.csv" style="display:block;margin:8px 0 12px;">
            <label for="raw_input"><strong>Dati da verificare</strong></label>
            <textarea id="raw_input" name="raw_input" rows="10" style="width:100%;margin-top:8px;font-family:monospace;" placeholder="Esempio:&#10;Rossi S.r.l.&#9;info@rossi.example.com&#9;01234567890&#9;ABC1234&#9;rossi@pec.example.com"><?php echo htmlspecialchars($rawInput); ?></textarea>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" class="btn">Verifica e prepara</button>
                <a class="btn secondary" href="verifica.php">Pulisci</a>
            </div>
        </form>
    </div>

    <?php if ($previewRows): ?>
        <div class="card" style="margin-top:12px;">
            <h3 style="margin-top:0;">Anteprima aggiornamento</h3>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <div style="overflow-x:auto;">
                    <table style="width:100%;">
                        <thead>
                            <tr>
                                <th>Applica</th>
                                <th>Riga</th>
                                <th>Stripe ID</th>
                                <th>Cliente input</th>
                                <th>Cliente trovato</th>
                                <th>P.IVA</th>
                                <th>Codice SDI</th>
                                <th>PEC</th>
                                <th>Match</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewRows as $i => $r): ?>
                            <?php
                            $canApply = (int)$r['cliente_id'] > 0;
                            $matchLabel = 'Nessuno';
                            if ($r['match_type'] === 'stripe_id') $matchLabel = 'Stripe ID';
                            if ($r['match_type'] === 'email') $matchLabel = 'Email';
                            if ($r['match_type'] === 'piva') $matchLabel = 'P.IVA';
                            if ($r['match_type'] === 'nome') $matchLabel = 'Nome/Azienda';
                            if ($r['match_type'] === 'ambiguous') $matchLabel = 'Ambiguo';
                            ?>
                            <tr style="<?php echo $canApply ? '' : 'background:#fff7ed;'; ?>">
                                <td style="text-align:center;">
                                    <?php if ($canApply): ?>
                                        <input type="checkbox" name="rows[<?php echo $i; ?>][apply]" value="1" checked>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                    <input type="hidden" name="rows[<?php echo $i; ?>][cliente_id]" value="<?php echo (int)$r['cliente_id']; ?>">
                                </td>
                                <td><?php echo (int)$r['line_no']; ?></td>
                                <td><?php echo htmlspecialchars((string)$r['stripe_customer_id']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)$r['nome']); ?></strong>
                                    <?php if ($r['email'] !== ''): ?>
                                        <div class="muted" style="font-size:12px;"><?php echo htmlspecialchars((string)$r['email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canApply): ?>
                                        <strong><?php echo htmlspecialchars((string)$r['cliente_nome']); ?></strong>
                                        <?php if ($r['cliente_azienda'] !== ''): ?>
                                            <div class="muted" style="font-size:12px;"><?php echo htmlspecialchars((string)$r['cliente_azienda']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['cliente_email'] !== ''): ?>
                                            <div class="muted" style="font-size:12px;"><?php echo htmlspecialchars((string)$r['cliente_email']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#b45309;">Cliente non trovato o ambiguo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" name="rows[<?php echo $i; ?>][piva]" value="<?php echo htmlspecialchars((string)$r['piva']); ?>" style="width:140px;">
                                </td>
                                <td>
                                    <input type="text" name="rows[<?php echo $i; ?>][codice_sdi]" value="<?php echo htmlspecialchars((string)$r['codice_sdi']); ?>" style="width:110px;">
                                </td>
                                <td>
                                    <input type="text" name="rows[<?php echo $i; ?>][pec]" value="<?php echo htmlspecialchars((string)$r['pec']); ?>" style="width:200px;">
                                </td>
                                <td><?php echo htmlspecialchars($matchLabel); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn">Salva aggiornamenti selezionati</button>
                    <a class="btn secondary" href="verifica.php">Nuova verifica</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
