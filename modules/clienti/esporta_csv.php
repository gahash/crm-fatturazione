<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$stmt = $pdo->query("SELECT nome, email, azienda, telefono, indirizzo, piva FROM clienti ORDER BY creato_il DESC");
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=clienti.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['Nome', 'Email', 'Azienda', 'Telefono', 'Indirizzo', 'P.IVA']);
foreach ($rows as $r) {
    fputcsv($out, [$r['nome'], $r['email'], $r['azienda'], $r['telefono'], $r['indirizzo'], $r['piva']]);
}
fclose($out);
exit;
