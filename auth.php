<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = $GLOBALS['_tdg_app_config'] ?? null;
if (!is_array($config)) {
    $config = require __DIR__ . '/config/config.php';
    $GLOBALS['_tdg_app_config'] = $config;
}
$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    global $baseUrl;
    if (!current_user()) {
        $return = $_SERVER['REQUEST_URI'] ?? '';
        $target = $baseUrl . '/login.php';
        if ($return !== '') {
            $target .= '?return=' . urlencode($return);
        }
        header('Location: ' . $target);
        exit;
    }
    // Track page view
    _tdg_track_activity('pageview');
}

/**
 * Email dell'account "fatturazione" (variabile d'ambiente BILLING_USER_EMAIL).
 */
function billing_user_email(): string
{
    require_once __DIR__ . '/config/env.php';
    return strtolower(trim((string)env('BILLING_USER_EMAIL', '')));
}

/**
 * Check if user is the "fatturazione" account (has admin-level access but no monitor).
 */
function is_fatturazione_user(): bool
{
    $u = current_user();
    $billingEmail = billing_user_email();
    return $u && $billingEmail !== '' && strtolower(trim($u['email'] ?? '')) === $billingEmail;
}

/**
 * Check if current user has admin-level privileges (admin role OR fatturazione).
 */
function has_admin_access(): bool
{
    $u = current_user();
    if (!$u) return false;
    return strtolower($u['ruolo'] ?? '') === 'admin' || is_fatturazione_user();
}

/**
 * Check if the current session was killed by admin.
 */
function _tdg_check_session_alive(): void
{
    if (!current_user()) return;
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = require __DIR__ . '/config/db.php';
        $sid = session_id();
        $stmt = $pdo->prepare('SELECT is_active FROM user_sessions WHERE session_id = :sid LIMIT 1');
        $stmt->execute([':sid' => $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['is_active'] === 0) {
            // Session killed by admin
            $_SESSION = [];
            session_destroy();
            global $baseUrl;
            header('Location: ' . $baseUrl . '/login.php?kicked=1');
            exit;
        }
    } catch (Throwable $e) {
        // Silently ignore — tracking tables may not exist yet
    }
}

/**
 * Log user activity (pageview, login, logout).
 */
function _tdg_track_activity(string $azione = 'pageview'): void
{
    $user = current_user();
    if (!$user) return;

    static $tracked = false;
    if ($azione === 'pageview' && $tracked) return;
    $tracked = true;

    _tdg_check_session_alive();

    try {
        $pdo = require __DIR__ . '/config/db.php';
        $sid = session_id();
        $page = $_SERVER['REQUEST_URI'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        // Upsert session
        $sql = 'INSERT INTO user_sessions (user_id, user_nome, user_email, session_id, ip, user_agent, last_seen_at, last_page)
                VALUES (:uid, :nome, :email, :sid, :ip, :ua, NOW(), :page)
                ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_page = VALUES(last_page), ip = VALUES(ip), is_active = 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $user['id'], ':nome' => $user['nome'], ':email' => $user['email'],
            ':sid' => $sid, ':ip' => $ip, ':ua' => $ua, ':page' => $page
        ]);

        // Log login/logout only (skip pageview to reduce DB writes)
        if ($azione !== 'pageview') {
            $sql2 = 'INSERT INTO user_activity (user_id, user_nome, user_email, user_ruolo, azione, pagina, ip, user_agent, session_id)
                     VALUES (:uid, :nome, :email, :ruolo, :azione, :page, :ip, :ua, :sid)';
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute([
                ':uid' => $user['id'], ':nome' => $user['nome'], ':email' => $user['email'],
                ':ruolo' => $user['ruolo'] ?? '', ':azione' => $azione, ':page' => $page,
                ':ip' => $ip, ':ua' => $ua, ':sid' => $sid
            ]);
        }
    } catch (Throwable $e) {
        // Silently ignore — tables may not exist yet
    }
}
