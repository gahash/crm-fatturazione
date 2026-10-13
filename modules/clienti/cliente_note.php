<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$clienteId = (int)($_POST['cliente_id'] ?? 0);
$contenuto = trim($_POST['contenuto'] ?? '');

if ($clienteId && $contenuto !== '') {
    $stmt = $pdo->prepare("INSERT INTO clienti_note (cliente_id, contenuto) VALUES (:cliente_id, :contenuto)");
    $stmt->execute([
        ':cliente_id' => $clienteId,
        ':contenuto' => $contenuto,
    ]);
}

header('Location: cliente_scheda.php?id=' . $clienteId);
exit;

