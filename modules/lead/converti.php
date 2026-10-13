<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?error=missing');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM lead WHERE id = :id");
$stmt->execute([':id' => $id]);
$lead = $stmt->fetch();

if (!$lead) {
    header('Location: index.php?error=missing');
    exit;
}

$nome = trim(($lead['nome'] ?? '') . ' ' . ($lead['cognome'] ?? ''));
$note = 'Lead convertito in cliente (ID lead: ' . $id . ').';

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, note) VALUES (:nome, :email, :telefono, :azienda, :indirizzo, :piva, :note)");
    $ins->execute([
        ':nome' => $nome !== '' ? $nome : ($lead['nome'] ?? 'Lead'),
        ':email' => $lead['email'] ?? '',
        ':telefono' => $lead['telefono'] ?? '',
        ':azienda' => null,
        ':indirizzo' => $lead['citta'] ?? null,
        ':piva' => null,
        ':note' => $note,
    ]);

    $upd = $pdo->prepare("UPDATE lead SET esito = 'convertito' WHERE id = :id");
    $upd->execute([':id' => $id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: index.php?error=db');
    exit;
}

header('Location: index.php?success=converted');
exit;
