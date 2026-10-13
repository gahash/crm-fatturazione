<?php
require_once __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: index.php?fic_recovery_error=unauthorized');
    exit;
}

$root = dirname(__DIR__, 2);
$secretPath = $root . '/config/secret.php';
if (!is_file($secretPath)) {
    header('Location: index.php?fic_recovery_error=secret_missing');
    exit;
}

$secret = require $secretPath;
if (!is_array($secret)) {
    header('Location: index.php?fic_recovery_error=secret_invalid');
    exit;
}

if (!isset($secret['fattureincloud']) || !is_array($secret['fattureincloud'])) {
    $secret['fattureincloud'] = [];
}

$numeration = trim((string)($_POST['duplicate_recovery_numeration'] ?? ''));
$secret['fattureincloud']['duplicate_recovery_numeration'] = $numeration;

$phpContent = "<?php\n// ATTENZIONE: non committare questo file con chiavi reali.\n// Compila i valori con le tue credenziali.\nreturn " . var_export($secret, true) . ";\n";
if (@file_put_contents($secretPath, $phpContent) === false) {
    header('Location: index.php?fic_recovery_error=write_failed');
    exit;
}

header('Location: index.php?fic_recovery_saved=1');
exit;