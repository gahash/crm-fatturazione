<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$sample409 = "HTTP 409 - Hai superato il limite di documenti inclusi nella tua licenza per l'anno 2026";
$sampleApi = 'you have reached your api usage limit';
$retryCronPath = realpath(__DIR__ . '/retry_usage_limited_invoices.php');
$retryCronSource = $retryCronPath !== false ? (string)@file_get_contents($retryCronPath) : '';

echo json_encode([
    'ok' => true,
    'helper_recognizes_409_license_limit' => function_exists('fic_is_usage_limit_error_message')
        ? fic_is_usage_limit_error_message($sample409)
        : null,
    'helper_recognizes_api_limit' => function_exists('fic_is_usage_limit_error_message')
        ? fic_is_usage_limit_error_message($sampleApi)
        : null,
    'retry_cron_has_409_query_match' => $retryCronSource !== ''
        && strpos(strtolower($retryCronSource), 'limite di documenti inclusi') !== false,
    'retry_cron_has_shared_helper_call' => $retryCronSource !== ''
        && strpos($retryCronSource, 'fic_is_usage_limit_error_message') !== false,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);