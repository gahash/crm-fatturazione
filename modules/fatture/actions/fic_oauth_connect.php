<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
require ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3)) . '/auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..';
$secretPath = $root . '/config/secret.php';
if (!file_exists($secretPath)) {
    $tmpDir = $root . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    @file_put_contents($tmpDir . '/fic_oauth_error.log', "Missing secret.php at: {$secretPath}\n", FILE_APPEND);
    http_response_code(500);
    echo 'Fatture in Cloud: configurazione mancante (secret.php).';
    exit;
}
$secret = require $secretPath;
$fic = $secret['fattureincloud'] ?? [];

$clientId = $fic['client_id'] ?? '';
$redirectUri = $fic['oauth_redirect'] ?? '';
$oauthScope = $fic['oauth_scope'] ?? '';

if ($clientId === '' || $redirectUri === '') {
    $referer = $_SERVER['HTTP_REFERER'] ?? 'modules/impostazioni/';
    header('Location: ' . $referer . '?fic_error=config');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['fic_oauth_state'] = $state;
setcookie('fic_oauth_state', $state, [
    'expires' => time() + 600,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

$params = [
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'state' => $state,
];

if ($oauthScope !== '') {
    $params['scope'] = $oauthScope;
}

$authorizeUrl = 'https://api-v2.fattureincloud.it/oauth/authorize?' . http_build_query($params);
header('Location: ' . $authorizeUrl);
exit;
