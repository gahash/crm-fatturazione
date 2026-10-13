<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$stmt = $pdo->prepare("SELECT f.numero, f.data_emissione, f.stato, f.totale, c.nome AS cliente FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.document_type = :docType ORDER BY f.data_emissione DESC");
$stmt->execute([':docType' => 'order']);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=ordini.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['Numero', 'Data', 'Stato', 'Totale', 'Cliente']);
foreach ($rows as $r) {
    fputcsv($out, [$r['numero'], $r['data_emissione'], $r['stato'], $r['totale'], $r['cliente']]);
}
fclose($out);
exit;
