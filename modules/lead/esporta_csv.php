<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$stmt = $pdo->query("SELECT nome, cognome, email, telefono, citta, data_ricontatto, esito, note, servizio_acquistato, costo_servizio, destinazione FROM lead ORDER BY data_ricontatto DESC");
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=lead.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['Nome', 'Cognome', 'Email', 'Telefono', 'Citta', 'Data ricontatto', 'Esito', 'Note', 'Servizio acquistato', 'Costo servizio', 'Destinazione']);
foreach ($rows as $r) {
    fputcsv($out, [$r['nome'], $r['cognome'], $r['email'], $r['telefono'], $r['citta'], $r['data_ricontatto'], $r['esito'], $r['note'], $r['servizio_acquistato'], $r['costo_servizio'], $r['destinazione']]);
}
fclose($out);
exit;
