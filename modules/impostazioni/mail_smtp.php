<?php

function smtp_send_mail(array $cfg, string $to, string $subject, string $body, array $attachments = [], ?string $htmlBody = null): void
{
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 0);
    $secure = trim((string)($cfg['smtp_secure'] ?? 'tls'));
    $fromEmail = trim((string)($cfg['from_email'] ?? ''));
    $fromName = trim((string)($cfg['from_name'] ?? ''));
    $replyTo = trim((string)($cfg['reply_to'] ?? ''));
    $userName = trim((string)($cfg['smtp_user'] ?? ''));
    $password = (string)($cfg['smtp_pass'] ?? '');

    if ($host === '' || $port <= 0 || $fromEmail === '') {
        throw new RuntimeException('SMTP: configurazione incompleta.');
    }

    $secure = in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls';

    $headers = [];
    $fromHeader = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subject;
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $hasHtmlBody = $htmlBody !== null && trim($htmlBody) !== '';
    $hasAttachments = !empty($attachments);

    $headers[] = 'MIME-Version: 1.0';
    if ($hasAttachments) {
        $boundary = 'tg_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $message = [];
        $message[] = '--' . $boundary;
        $message[] = 'Content-Type: ' . ($hasHtmlBody ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $message[] = 'Content-Transfer-Encoding: 8bit';
        $message[] = '';
        $message[] = $hasHtmlBody ? (string)$htmlBody : $body;

        foreach ($attachments as $att) {
            $filename = $att['filename'] ?? 'allegato.pdf';
            $content = $att['content'] ?? '';
            $mime = $att['mime'] ?? 'application/pdf';
            $encoded = chunk_split(base64_encode($content));

            $message[] = '--' . $boundary;
            $message[] = 'Content-Type: ' . $mime . '; name="' . $filename . '"';
            $message[] = 'Content-Transfer-Encoding: base64';
            $message[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
            $message[] = '';
            $message[] = $encoded;
        }

        $message[] = '--' . $boundary . '--';
        $bodyContent = implode("\r\n", $message);
    } else {
        $headers[] = 'Content-Type: ' . ($hasHtmlBody ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $bodyContent = $hasHtmlBody ? (string)$htmlBody : $body;
    }

    $bodySafe = str_replace("\n.", "\n..", $bodyContent);
    $fullMessage = implode("\r\n", $headers) . "\r\n\r\n" . $bodySafe . "\r\n.";

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

    smtp_write($fp, $fullMessage);
    smtp_expect($fp, [250]);

    smtp_write($fp, 'QUIT');
    fclose($fp);
}

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
