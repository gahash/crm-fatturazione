<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    header('Location: index.php?error=upload');
    exit;
}

$handle = fopen($_FILES['file']['tmp_name'], 'r');
if (!$handle) {
    header('Location: index.php?error=upload');
    exit;
}

$header = fgetcsv($handle);
$expected = ['nome','cognome','email','telefono','citta','data_ricontatto','esito','note','servizio_acquistato','costo_servizio','destinazione'];
$aliases = [
    'nome' => ['nome','first_name','firstname','name'],
    'cognome' => ['cognome','last_name','lastname','surname'],
    'email' => ['email','e-mail'],
    'telefono' => ['telefono','phone','phone_number','cellulare'],
    'citta' => ['citta','città','city','localita','località'],
    'data_ricontatto' => ['data_ricontatto','data','date','data_contatto','data_followup'],
    'esito' => ['esito','stato','status','outcome'],
    'note' => ['note','notes'],
    'servizio_acquistato' => ['servizio_acquistato','servizio','prodotto','product'],
    'costo_servizio' => ['costo_servizio','costo','amount','prezzo','price'],
    'destinazione' => ['destinazione','destination'],
];

$map = [];
if ($header) {
    foreach ($header as $idx => $col) {
        $key = strtolower(trim((string)$col));
        foreach ($aliases as $field => $names) {
            if (in_array($key, $names, true)) {
                $map[$field] = $idx;
            }
        }
    }
}

$stmt = $pdo->prepare("INSERT INTO lead (nome, cognome, email, telefono, citta, data_ricontatto, esito, note, servizio_acquistato, costo_servizio, destinazione) VALUES (:nome, :cognome, :email, :telefono, :citta, :data_ricontatto, :esito, :note, :servizio_acquistato, :costo_servizio, :destinazione)");

$imported = 0;
while (($row = fgetcsv($handle)) !== false) {
    $data = [];
    foreach ($expected as $key) {
        $idx = $map[$key] ?? null;
        $data[$key] = $idx !== null ? trim((string)$row[$idx]) : '';
    }
    if ($data['nome'] === '' || $data['data_ricontatto'] === '') {
        continue;
    }
    $stmt->execute([
        ':nome' => $data['nome'],
        ':cognome' => $data['cognome'],
        ':email' => $data['email'],
        ':telefono' => $data['telefono'],
        ':citta' => $data['citta'],
        ':data_ricontatto' => $data['data_ricontatto'],
        ':esito' => $data['esito'],
        ':note' => $data['note'],
        ':servizio_acquistato' => $data['servizio_acquistato'],
        ':costo_servizio' => $data['costo_servizio'] !== '' ? (float)$data['costo_servizio'] : null,
        ':destinazione' => $data['destinazione'],
    ]);
    $imported++;
}

fclose($handle);
header('Location: index.php?success=1&import=' . $imported);
exit;
