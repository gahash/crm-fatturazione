<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');
$ruolo = $_POST['ruolo'] ?? 'ragioneria';
$attivo = (int)($_POST['attivo'] ?? 1);

if ($nome === '' || $email === '' || $password === '') {
    header('Location: index.php?error=missing');
    exit;
}

try {
    $hash = hash('sha256', $password);
    $stmt = $pdo->prepare("INSERT INTO utenti (nome, email, ruolo, password_hash, attivo) VALUES (:nome, :email, :ruolo, :password_hash, :attivo)");
    $stmt->execute([
        ':nome' => $nome,
        ':email' => $email,
        ':ruolo' => $ruolo,
        ':password_hash' => $hash,
        ':attivo' => $attivo ? 1 : 0,
    ]);
} catch (Throwable $e) {
    header('Location: index.php?error=db');
    exit;
}

header('Location: index.php?success=1');
exit;
