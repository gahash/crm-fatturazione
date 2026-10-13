<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id > 0 && (int)$id !== (int)($user['id'] ?? 0)) {
    $stmt = $pdo->prepare("DELETE FROM utenti WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

header('Location: index.php?success=1');
exit;
