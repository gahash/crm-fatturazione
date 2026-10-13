<?php
/**
 * Configurazione principale dell'applicazione.
 *
 * Tutti i valori sensibili vengono letti da variabili d'ambiente (o dal file
 * .env nella root del progetto, vedi .env.example). Eventuali override locali
 * possono essere definiti in config/config.local.php (non versionato).
 */
require_once __DIR__ . '/env.php';

$config = [
    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'crm_fatturazione'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],
    'app' => [
        // Esempio: 'https://crm.example.com' oppure 'http://localhost:8000'
        'base_url' => rtrim((string)env('APP_URL', 'http://localhost:8000'), '/'),
        'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
    ],
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'public_key' => env('STRIPE_PUBLIC_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'public_url' => rtrim((string)env('APP_URL', 'http://localhost:8000'), '/'),
    ],
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $local = require $localConfig;
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;
