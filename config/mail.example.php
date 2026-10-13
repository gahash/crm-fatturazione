<?php
/**
 * Configurazione SMTP/IMAP per l'invio delle fatture via email.
 *
 * Copiare in config/mail.php (non versionato):
 *     cp config/mail.example.php config/mail.php
 *
 * La pagina Impostazioni > Email puo' riscrivere config/mail.php con i valori
 * inseriti dall'interfaccia.
 */
require_once __DIR__ . '/env.php';

return [
    'smtp_host'   => env('SMTP_HOST', 'smtp.example.com'),
    'smtp_port'   => (int)env('SMTP_PORT', 465),
    'smtp_user'   => env('SMTP_USER', ''),
    'smtp_pass'   => env('SMTP_PASS', ''),
    'smtp_secure' => env('SMTP_SECURE', 'ssl'),
    'from_email'  => env('MAIL_FROM_EMAIL', 'fatturazione@example.com'),
    'from_name'   => env('MAIL_FROM_NAME', 'CRM Fatturazione'),
    'reply_to'    => env('MAIL_REPLY_TO', 'fatturazione@example.com'),
    'imap_host'   => env('IMAP_HOST', 'imap.example.com'),
    'imap_port'   => (int)env('IMAP_PORT', 993),
];
