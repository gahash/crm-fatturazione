<?php
require __DIR__ . '/../../auth.php';
require_login();
require __DIR__ . '/facebook_api.php';

$cfg = fb_config();
$appId = $cfg['app_id'] ?? '';
$redirectUri = $cfg['redirect_uri'] ?? '';

if ($appId === '' || $redirectUri === '') {
	http_response_code(500);
	echo 'Facebook: configurazione mancante.';
	exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['fb_oauth_state'] = $state;
setcookie('fb_oauth_state', $state, [
	'expires' => time() + 600,
	'path' => '/',
	'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
	'httponly' => true,
	'samesite' => 'Lax',
]);

$scope = 'leads_retrieval,pages_read_engagement,pages_manage_metadata';
$params = [
	'client_id' => $appId,
	'redirect_uri' => $redirectUri,
	'state' => $state,
	'response_type' => 'code',
	'scope' => $scope,
];

$authUrl = 'https://www.facebook.com/' . ($cfg['api_version'] ?? 'v21.0') . '/dialog/oauth?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;
