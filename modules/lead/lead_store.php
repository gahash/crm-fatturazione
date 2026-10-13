<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$nome = trim($_POST['nome'] ?? '');
$dataRicontatto = $_POST['data_ricontatto'] ?? '';
if ($nome === '' || $dataRicontatto === '') {
    header('Location: index.php?error=missing');
    exit;
}

$stmt = $pdo->prepare("INSERT INTO lead (nome, cognome, email, telefono, citta, data_ricontatto, esito, note, servizio_acquistato, costo_servizio, destinazione) VALUES (:nome, :cognome, :email, :telefono, :citta, :data_ricontatto, :esito, :note, :servizio_acquistato, :costo_servizio, :destinazione)");
$stmt->execute([
    ':nome' => $nome,
    ':cognome' => trim($_POST['cognome'] ?? ''),
    ':email' => trim($_POST['email'] ?? ''),
    ':telefono' => trim($_POST['telefono'] ?? ''),
    ':citta' => trim($_POST['citta'] ?? ''),
    ':data_ricontatto' => $dataRicontatto,
    ':esito' => trim($_POST['esito'] ?? ''),
    ':note' => trim($_POST['note'] ?? ''),
    ':servizio_acquistato' => trim($_POST['servizio_acquistato'] ?? ''),
    ':costo_servizio' => ($_POST['costo_servizio'] ?? '') !== '' ? (float)$_POST['costo_servizio'] : null,
    ':destinazione' => trim($_POST['destinazione'] ?? ''),
]);

header('Location: index.php?success=1');
exit;
