<?php
require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: ../clienti/clienti.php');
    exit;
}

$mailPath = __DIR__ . '/../../config/mail.php';
if (!file_exists($mailPath)) {
    header('Location: index.php?mail_test_error=missing_config');
    exit;
}

$cfg = require $mailPath;
$to = trim((string)($_POST['test_email'] ?? ''));

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?mail_test_error=invalid_email');
    exit;
}

$host = trim((string)($cfg['smtp_host'] ?? ''));
$port = (int)($cfg['smtp_port'] ?? 0);
$secure = trim((string)($cfg['smtp_secure'] ?? 'tls'));
$fromEmail = trim((string)($cfg['from_email'] ?? ''));
$fromName = trim((string)($cfg['from_name'] ?? ''));
$replyTo = trim((string)($cfg['reply_to'] ?? ''));
$userName = trim((string)($cfg['smtp_user'] ?? ''));
$password = (string)($cfg['smtp_pass'] ?? '');

if ($host === '' || $port <= 0 || $fromEmail === '') {
    header('Location: index.php?mail_test_error=missing_fields');
    exit;
}

$secure = in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls';

$subject = 'Test invio fatture';
$body = "Questa e' una email di test inviata da CRM Fatturazione.";

function smtp_read($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_expect($fp, array $codes): void
{
    $resp = smtp_read($fp);
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($resp));
    }
}

function smtp_write($fp, string $cmd): void
{
    fwrite($fp, $cmd . "\r\n");
}

try {
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = stream_socket_client($target, $errno, $errstr, 10);
    if (!$fp) {
        throw new RuntimeException('Connessione SMTP fallita: ' . $errstr);
    }

    smtp_expect($fp, [220]);
    smtp_write($fp, 'EHLO localhost');
    smtp_expect($fp, [250]);

    if ($secure === 'tls') {
        smtp_write($fp, 'STARTTLS');
        smtp_expect($fp, [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS fallito.');
        }
        smtp_write($fp, 'EHLO localhost');
        smtp_expect($fp, [250]);
    }

    if ($userName !== '') {
        smtp_write($fp, 'AUTH LOGIN');
        smtp_expect($fp, [334]);
        smtp_write($fp, base64_encode($userName));
        smtp_expect($fp, [334]);
        smtp_write($fp, base64_encode($password));
        smtp_expect($fp, [235]);
    }

    smtp_write($fp, 'MAIL FROM:<' . $fromEmail . '>');
    smtp_expect($fp, [250]);

    smtp_write($fp, 'RCPT TO:<' . $to . '>');
    smtp_expect($fp, [250, 251]);

    smtp_write($fp, 'DATA');
    smtp_expect($fp, [354]);

    $headers = [];
    $fromHeader = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subject;
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $bodySafe = str_replace("\n.", "\n..", $body);
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodySafe . "\r\n.";
    smtp_write($fp, $message);
    smtp_expect($fp, [250]);

    smtp_write($fp, 'QUIT');
    fclose($fp);

    header('Location: index.php?mail_test_ok=1');
    exit;
} catch (Throwable $e) {
    $errPath = __DIR__ . '/../../config/mail_last_error.log';
    $msg = '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n";
    @file_put_contents($errPath, $msg, FILE_APPEND | LOCK_EX);
    header('Location: index.php?mail_test_error=send_failed');
    exit;
}
