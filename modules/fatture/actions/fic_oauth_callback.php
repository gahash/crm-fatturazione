<?php
require __DIR__ . '/../../../auth.php';
require_login();
require_once __DIR__ . '/fic_api.php';

$root = fic_project_root();
$config = require $root . '/config/config.php';
$secret = require $root . '/config/secret.php';
$cfg = $secret['fattureincloud'] ?? [];

$oauthLogFile = $root . '/tmp/fic_oauth_debug.log';
$oauthLog = static function (string $msg) use ($oauthLogFile): void {
    @file_put_contents($oauthLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
};
$oauthLog('Callback hit. query=' . json_encode($_GET));

function fic_oauth_validate_signed_state(string $state, string $secret, int $maxAgeSeconds = 1800): bool
{
    if ($state === '' || strpos($state, '.') === false) {
        return false;
    }

    [$encoded, $sig] = explode('.', $state, 2);
    if ($encoded === '' || $sig === '' || $secret === '') {
        return false;
    }

    $expectedSig = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expectedSig, $sig)) {
        return false;
    }

    $b64 = strtr($encoded, '-_', '+/');
    $padLen = strlen($b64) % 4;
    if ($padLen > 0) {
        $b64 .= str_repeat('=', 4 - $padLen);
    }

    $json = base64_decode($b64, true);
    if ($json === false) {
        return false;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return false;
    }

    $ts = (int)($payload['ts'] ?? 0);
    $nonce = (string)($payload['nonce'] ?? '');
    if ($ts <= 0 || $nonce === '') {
        return false;
    }

    $age = time() - $ts;
    if ($age < -60 || $age > $maxAgeSeconds) {
        return false;
    }

    return true;
}

function fic_oauth_validate_file_state(string $root, string $state): bool
{
    if ($state === '') {
        return false;
    }

    $stateFile = $root . '/tmp/fic_oauth_states.json';
    if (!is_file($stateFile)) {
        return false;
    }

    $raw = @file_get_contents($stateFile);
    $store = json_decode((string)$raw, true);
    if (!is_array($store)) {
        return false;
    }

    $now = time();
    $valid = false;
    foreach ($store as $k => $row) {
        $ts = (int)($row['ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > 3600) {
            unset($store[$k]);
            continue;
        }
        if ($k === $state) {
            $valid = true;
            unset($store[$k]); // consume one-time
        }
    }

    @file_put_contents($stateFile, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $valid;
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$oauthError = trim((string)($_GET['error'] ?? ''));
$oauthErrorDesc = trim((string)($_GET['error_description'] ?? ''));
$expectedState = $_SESSION['fic_oauth_state'] ?? '';
$cookieState = $_COOKIE['fic_oauth_state'] ?? '';
$returnPath = (string)($_SESSION['fic_oauth_return'] ?? '/modules/fatture/index.php');
if ($returnPath === '' || strpos($returnPath, '://') !== false || strpos($returnPath, '//') === 0 || strpos($returnPath, '..') !== false) {
    $returnPath = '/modules/fatture/index.php';
}
if ($returnPath[0] !== '/') {
    $returnPath = '/' . $returnPath;
}
unset($_SESSION['fic_oauth_state']);
unset($_SESSION['fic_oauth_return']);
setcookie('fic_oauth_state', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if ($oauthError !== '') {
    $oauthLog('OAuth provider error. error=' . $oauthError . ' desc=' . $oauthErrorDesc . ' state=' . $state);
    $base = rtrim($config['app']['base_url'] ?? '', '/');
    $redirect = $base . $returnPath;
    if ($base === '') {
        $redirect = $returnPath;
    }
    $sep = (strpos($redirect, '?') === false) ? '?' : '&';
    $redirect .= $sep . 'fic_oauth_error=' . urlencode($oauthError);
    if ($oauthErrorDesc !== '') {
        $redirect .= '&fic_oauth_error_desc=' . urlencode($oauthErrorDesc);
    }
    header('Location: ' . $redirect);
    exit;
}

$stateFromSession = ($state !== '' && ($state === $expectedState || $state === $cookieState));
$stateFromSignature = fic_oauth_validate_signed_state($state, trim((string)($cfg['client_secret'] ?? '')));
$stateFromFile = fic_oauth_validate_file_state($root, $state);
if ($code === '' || $state === '' || !($stateFromSession || $stateFromSignature || $stateFromFile)) {
    $oauthLog('State mismatch. state=' . $state . ' expected=' . $expectedState . ' cookie=' . $cookieState . ' signed=' . ($stateFromSignature ? '1' : '0') . ' file=' . ($stateFromFile ? '1' : '0'));
    http_response_code(400);
    echo 'Fatture in Cloud: autorizzazione non valida (state mismatch).';
    exit;
}

$clientId = $cfg['client_id'] ?? '';
$clientSecret = $cfg['client_secret'] ?? '';
$baseUrl = rtrim($cfg['base_url'] ?? 'https://api-v2.fattureincloud.it', '/');
$redirectUri = $cfg['oauth_redirect'] ?? '';

if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    echo 'Fatture in Cloud: credenziali mancanti.';
    exit;
}

if ($redirectUri === '') {
    $appBase = rtrim($config['app']['base_url'] ?? '', '/');
    if ($appBase !== '') {
        $redirectUri = $appBase . '/modules/fatture/actions/fic_oauth_callback.php';
    }
}

$payload = http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
]);

$ch = curl_init($baseUrl . '/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json',
]);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo 'Fatture in Cloud: errore cURL: ' . $err;
    exit;
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status < 200 || $status >= 300) {
    $oauthLog('Token exchange failed. status=' . $status . ' body=' . substr((string)$response, 0, 500));
    http_response_code(502);
    echo 'Fatture in Cloud: risposta non valida. ' . $response;
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['access_token'])) {
    $oauthLog('Token response incomplete. body=' . substr((string)$response, 0, 500));
    http_response_code(502);
    echo 'Fatture in Cloud: risposta incompleta.';
    exit;
}

// Recupera info utente e company_id
$ch = curl_init($baseUrl . '/user/info');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $data['access_token'],
]);

$userResp = curl_exec($ch);
$userStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$companyId = null;
if ($userStatus >= 200 && $userStatus < 300) {
    $userData = json_decode($userResp, true);
    if (is_array($userData) && !empty($userData['data']['companies'])) {
        $companyId = (string)($userData['data']['companies'][0]['id'] ?? '');
    }
}

$tokenFile = $root . '/tmp/fic_token.json';
$payloadToSave = [
    'access_token' => $data['access_token'],
    'refresh_token' => $data['refresh_token'] ?? null,
    'token_type' => $data['token_type'] ?? 'Bearer',
    'expires_in' => $data['expires_in'] ?? null,
    'created_at' => time(),
    'company_id' => $companyId,
];
$tokenDir = dirname($tokenFile);
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0755, true);
}
$saved = @file_put_contents($tokenFile, json_encode($payloadToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if ($saved === false) {
    $oauthLog('Token save failed at ' . $tokenFile);
    http_response_code(500);
    echo 'Fatture in Cloud: impossibile salvare il token OAuth.';
    exit;
}
$oauthLog('Token saved. company_id=' . (string)$companyId . ' file=' . $tokenFile);

$base = rtrim($config['app']['base_url'] ?? '', '/');
$redirect = $base . $returnPath;
$sep = (strpos($redirect, '?') === false) ? '?' : '&';
$redirect .= $sep . 'fic_connected=1';
if ($companyId !== null && $companyId !== '') {
    $redirect .= '&fic_company=' . urlencode((string)$companyId);
}

if ($base === '') {
    $redirect = $returnPath . (strpos($returnPath, '?') === false ? '?' : '&') . 'fic_connected=1';
}

header('Location: ' . $redirect);
exit;
