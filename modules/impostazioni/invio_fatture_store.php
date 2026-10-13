<?php
require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$pdo = require __DIR__ . '/../../config/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$sendMode = (string)($_POST['send_mode'] ?? 'manuale');
if (!in_array($sendMode, ['manuale', 'automatico'], true)) {
    $sendMode = 'manuale';
}
$enabled = isset($_POST['enabled']) && (string)$_POST['enabled'] === '1' ? 1 : 0;

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_send_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        send_mode VARCHAR(20) NOT NULL DEFAULT "manuale",
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $stmt = $pdo->prepare('INSERT INTO invoice_send_settings (setting_key, send_mode, enabled, updated_at)
        VALUES (:setting_key, :send_mode, :enabled, NOW())
        ON DUPLICATE KEY UPDATE send_mode = VALUES(send_mode), enabled = VALUES(enabled), updated_at = NOW()');

    $stmt->execute([
        ':setting_key' => 'fatture_send',
        ':send_mode' => $sendMode,
        ':enabled' => $enabled,
    ]);

    header('Location: index.php?send_saved=1');
    exit;
} catch (Throwable $e) {
    header('Location: index.php?send_error=1');
    exit;
}
