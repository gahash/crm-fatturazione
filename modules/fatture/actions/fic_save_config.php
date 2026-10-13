<?php
require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../../impostazioni/?fic_error=unauthorized');
    exit;
}

// Leggi token da tmp/fic_token.json
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..';
$tokenPath = $root . '/tmp/fic_token.json';
if (!file_exists($tokenPath)) {
    header('Location: ../../impostazioni/?fic_error=no_token_file');
    exit;
}

$tokenRaw = file_get_contents($tokenPath);
$tokenData = json_decode($tokenRaw, true);

if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    header('Location: ../../impostazioni/?fic_error=invalid_token');
    exit;
}

$accessToken = $tokenData['access_token'];
$companyId = $tokenData['company_id'] ?? null;

// Leggi config/secret.php come array, modifica, riscrivi
$secretPath = $root . '/config/secret.php';
$secret = require $secretPath;

// Aggiorna access_token e company_id
$secret['fattureincloud']['access_token'] = $accessToken;
if ($companyId !== null) {
    $secret['fattureincloud']['company_id'] = $companyId;
}

// Riscrivi il file in formato PHP valido
$phpContent = "<?php\n// ATTENZIONE: non committare questo file con chiavi reali.\n// Compila i valori con le tue credenziali.\nreturn " . var_export($secret, true) . ";\n";

if (@file_put_contents($secretPath, $phpContent) === false) {
    header('Location: ../../impostazioni/?fic_error=write_failed');
    exit;
}

header('Location: ../../impostazioni/?fic_saved=1&company_id=' . urlencode((string)$companyId));
exit;
