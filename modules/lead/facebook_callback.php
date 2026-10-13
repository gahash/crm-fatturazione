<?php
require __DIR__ . '/../../auth.php';
require_login();
require __DIR__ . '/facebook_api.php';

$cfg = fb_config();
$appId = $cfg['app_id'] ?? '';
$appSecret = $cfg['app_secret'] ?? '';
$redirectUri = $cfg['redirect_uri'] ?? '';
$version = $cfg['api_version'] ?? 'v21.0';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$expected = $_SESSION['fb_oauth_state'] ?? '';
$cookieState = $_COOKIE['fb_oauth_state'] ?? '';
unset($_SESSION['fb_oauth_state']);
setcookie('fb_oauth_state', '', [
	'expires' => time() - 3600,
	'path' => '/',
	'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
	'httponly' => true,
	'samesite' => 'Lax',
]);

if ($code === '' || $state === '' || !($state === $expected || $state === $cookieState)) {
	http_response_code(400);
	echo 'Facebook: autorizzazione non valida.';
	exit;
}

if ($appId === '' || $appSecret === '' || $redirectUri === '') {
	http_response_code(500);
	echo 'Facebook: configurazione mancante.';
	exit;
}

$tokenUrl = 'https://graph.facebook.com/' . $version . '/oauth/access_token';
$params = [
	'client_id' => $appId,
	'client_secret' => $appSecret,
	'redirect_uri' => $redirectUri,
	'code' => $code,
];

$ch = curl_init($tokenUrl . '?' . http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
	$err = curl_error($ch);
	curl_close($ch);
	http_response_code(502);
	echo 'Facebook: errore cURL: ' . $err;
	exit;
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status < 200 || $status >= 300) {
	http_response_code(502);
	echo 'Facebook: risposta non valida. ' . $response;
	exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['access_token'])) {
	http_response_code(502);
	echo 'Facebook: token non valido.';
	exit;
}

// Scambio per long-lived token
$llParams = [
	'grant_type' => 'fb_exchange_token',
	'client_id' => $appId,
	'client_secret' => $appSecret,
	'fb_exchange_token' => $data['access_token'],
];

$ch = curl_init($tokenUrl . '?' . http_build_query($llParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$llResponse = curl_exec($ch);
if ($llResponse === false) {
	$err = curl_error($ch);
	curl_close($ch);
	http_response_code(502);
	echo 'Facebook: errore cURL: ' . $err;
	exit;
}
$llStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$llData = json_decode($llResponse, true);
$tokenToSave = $llStatus >= 200 && $llStatus < 300 && !empty($llData['access_token'])
	? $llData
	: $data;

fb_save_token([
	'access_token' => $tokenToSave['access_token'],
	'expires_in' => $tokenToSave['expires_in'] ?? null,
	'created_at' => time(),
]);

header('Location: index.php?success=1');
exit;
