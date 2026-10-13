<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);

if ($id) {
    $stmt = $pdo->prepare("DELETE FROM lead WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

header('Location: index.php?success=1');
exit;
