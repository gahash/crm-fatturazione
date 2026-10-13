<?php
require __DIR__ . '/../../../auth.php';
require_login();
require_once __DIR__ . '/fic_api.php';

$config = require __DIR__ . '/../../../config/config.php';
$secret = require __DIR__ . '/../../../config/secret.php';
$cfg = $secret['fattureincloud'] ?? [];

$clientId = $cfg['client_id'] ?? '';
$authBaseUrl = rtrim((string)($cfg['base_url'] ?? 'https://api-v2.fattureincloud.it'), '/');
$redirectUri = $cfg['oauth_redirect'] ?? '';
$returnPath = (string)($_GET['return'] ?? '/modules/fatture/index.php');
if ($returnPath === '' || strpos($returnPath, '://') !== false || strpos($returnPath, '//') === 0 || strpos($returnPath, '..') !== false) {
    $returnPath = '/modules/fatture/index.php';
}
if ($returnPath[0] !== '/') {
    $returnPath = '/' . $returnPath;
}
$_SESSION['fic_oauth_return'] = $returnPath;
$stateSecret = (string)($cfg['client_secret'] ?? ($cfg['client_id'] ?? 'fic_oauth_state'));
$statePayload = [
    'nonce' => bin2hex(random_bytes(16)),
    'ts' => time(),
];
$stateEncoded = rtrim(strtr(base64_encode((string)json_encode($statePayload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
$stateSig = hash_hmac('sha256', $stateEncoded, $stateSecret !== '' ? $stateSecret : 'fic_oauth_state');
$state = $stateEncoded . '.' . $stateSig;
$_SESSION['fic_oauth_state'] = $state;
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('fic_oauth_state', $state, [
    'expires' => time() + 600,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Backup server-side dello state (evita mismatch se sessione/cookie si perdono)
try {
    $root = fic_project_root();
    $stateFile = $root . '/tmp/fic_oauth_states.json';
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $store = [];
    if (is_file($stateFile)) {
        $raw = @file_get_contents($stateFile);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $store = $decoded;
        }
    }

    $now = time();
    foreach ($store as $k => $row) {
        $ts = (int)($row['ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > 3600) {
            unset($store[$k]);
        }
    }

    $store[$state] = [
        'ts' => $now,
        'uid' => (int)($_SESSION['user_id'] ?? 0),
    ];
    @file_put_contents($stateFile, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    // non bloccare OAuth start
}

if ($clientId === '') {
    http_response_code(500);
    echo 'Fatture in Cloud: client_id mancante.';
    exit;
}

if ($redirectUri === '') {
    $appBase = rtrim($config['app']['base_url'] ?? '', '/');
    if ($appBase !== '') {
        $redirectUri = $appBase . '/modules/fatture/actions/fic_oauth_callback.php';
    }
}

if ($redirectUri === '') {
    http_response_code(500);
    echo 'Fatture in Cloud: redirect_uri mancante.';
    exit;
}

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'state' => $state,
];

$scope = trim((string)($cfg['oauth_scope'] ?? ''));
if ($scope === '') {
    // Scope minimo utile per CRM -> FIC (clienti + fatture emesse + settings/info).
    $scope = 'situation:r entity.clients:a issued_documents.invoices:a settings:r';
}
$params['scope'] = $scope;

$authUrl = $authBaseUrl . '/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $authUrl);
exit;
