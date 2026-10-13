<?php
require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$secretPath = __DIR__ . '/../../config/secret.php';
if (!file_exists($secretPath)) {
    header('Location: index.php?mail_error=missing_secret');
    exit;
}

$mailPath = __DIR__ . '/../../config/mail.php';
$existingMail = file_exists($mailPath) ? (require $mailPath) : [];

$smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
$smtpPort = (int)($_POST['smtp_port'] ?? 587);
$smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
$smtpPass = (string)($_POST['smtp_pass'] ?? '');
$smtpSecure = trim((string)($_POST['smtp_secure'] ?? 'tls'));
$fromEmail = trim((string)($_POST['from_email'] ?? ''));
$fromName = trim((string)($_POST['from_name'] ?? ''));
$replyTo = trim((string)($_POST['reply_to'] ?? ''));

if ($smtpHost === '' || $fromEmail === '' || $smtpPort <= 0) {
    header('Location: index.php?mail_error=missing_fields');
    exit;
}

$allowedSecure = ['tls', 'ssl', 'none'];
if (!in_array($smtpSecure, $allowedSecure, true)) {
    $smtpSecure = 'tls';
}

if ($smtpPass === '' && !empty($existingMail['smtp_pass'])) {
    $smtpPass = (string)$existingMail['smtp_pass'];
}

$mailCfg = [
    'smtp_host' => $smtpHost,
    'smtp_port' => $smtpPort,
    'smtp_user' => $smtpUser,
    'smtp_pass' => $smtpPass,
    'smtp_secure' => $smtpSecure,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
    'reply_to' => $replyTo,
];

$payload = "<?php\nreturn " . var_export($mailCfg, true) . ";\n";

if (file_put_contents($mailPath, $payload, LOCK_EX) === false) {
    header('Location: index.php?mail_error=write_failed');
    exit;
}

header('Location: index.php?mail_saved=1');
exit;
