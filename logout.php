<?php
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/config/config.php';
$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

// Track logout and deactivate session
_tdg_track_activity('logout');
try {
    $pdo = require __DIR__ . '/config/db.php';
    $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE session_id = :sid')
        ->execute([':sid' => session_id()]);
} catch (Throwable $e) {}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ' . $baseUrl . '/login.php');
exit;
