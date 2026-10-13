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

$enabled = isset($_POST['enabled']) && (string)$_POST['enabled'] === '1' ? 1 : 0;
$interval = (int)($_POST['interval_minutes'] ?? 60);
$allowedIntervals = [5, 10, 15, 30, 60, 120, 240, 720, 1440];
if (!in_array($interval, $allowedIntervals, true)) {
    $interval = 60;
}

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_scheduler_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sync_key VARCHAR(100) NOT NULL UNIQUE,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
        last_run_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $stmt = $pdo->prepare('INSERT INTO sync_scheduler_settings (sync_key, enabled, interval_minutes, updated_at)
        VALUES (:sync_key, :enabled, :interval_minutes, NOW())
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), interval_minutes = VALUES(interval_minutes), updated_at = NOW()');

    $stmt->execute([
        ':sync_key' => 'pagamenti_stripe',
        ':enabled' => $enabled,
        ':interval_minutes' => $interval,
    ]);

    header('Location: index.php?sync_saved=1');
    exit;
} catch (Throwable $e) {
    header('Location: index.php?sync_error=1');
    exit;
}
