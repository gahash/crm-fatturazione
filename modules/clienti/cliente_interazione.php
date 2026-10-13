<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$clienteId = (int)($_POST['cliente_id'] ?? 0);
$tipo = trim($_POST['tipo'] ?? 'altro');
$descrizione = trim($_POST['descrizione'] ?? '');
$data = $_POST['data_interazione'] ?? '';

if ($clienteId && $descrizione !== '' && $data !== '') {
    $stmt = $pdo->prepare("INSERT INTO clienti_interazioni (cliente_id, tipo, descrizione, data_interazione) VALUES (:cliente_id, :tipo, :descrizione, :data_interazione)");
    $stmt->execute([
        ':cliente_id' => $clienteId,
        ':tipo' => $tipo,
        ':descrizione' => $descrizione,
        ':data_interazione' => $data,
    ]);
}

header('Location: cliente_scheda.php?id=' . $clienteId);
exit;

