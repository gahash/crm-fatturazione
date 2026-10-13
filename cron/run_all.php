<?php
/**
 * CRON WRAPPER: esegue tutti i job in sequenza.
 *
 * Cron di sistema (ogni 5 minuti), ad esempio:
 *   */5 * * * * /usr/bin/php /percorso/progetto/cron/run_all.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 300);

require_once __DIR__ . '/cron_secret.php';

// Se chiamato via web, verifica la chiave
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(CRON_KEY, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
        exit;
    }
}

$appConfig = require __DIR__ . '/../config/config.php';
$baseUrl = rtrim((string)($appConfig['app']['base_url'] ?? ''), '/') . '/cron/';
$cronKey = CRON_KEY;

$jobs = [
    'sync_stripe.php',
    'sync_customers.php',
    'retry_pending_invoices.php',
    'retry_transient_sync_errors.php',
    'sync_fic_invoices.php',
    'retry_usage_limited_invoices.php',
    'cron_send_einvoice_paid.php',
];

$results = [];

foreach ($jobs as $job) {
    $url = $baseUrl . $job . '?key=' . urlencode($cronKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $results[$job] = [
        'http_code' => $httpCode,
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'error' => $error ?: null,
    ];
}

if (php_sapi_name() !== 'cli') {
    echo json_encode(['ok' => true, 'jobs' => $results], JSON_PRETTY_PRINT);
} else {
    foreach ($results as $job => $r) {
        $status = $r['ok'] ? 'OK' : 'FAIL';
        echo "[{$status}] {$job} (HTTP {$r['http_code']})" . ($r['error'] ? " - {$r['error']}" : '') . "\n";
    }
}
