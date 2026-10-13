<?php
/**
 * Async Stripe data loader for dashboard
 * Responde con JSON dei dati Stripe con caching
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/cache_helper.php';

$config = require __DIR__ . '/../../config/config.php';
$user = current_user();

if (!$user || ($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$meseSelezionato = isset($_GET['mese']) ? (int)$_GET['mese'] : 0;
$annoSelezionato = isset($_GET['anno']) ? (int)$_GET['anno'] : 0;
$meseSelezionato = max(0, min(12, $meseSelezionato));
$annoSelezionato = max(0, $annoSelezionato);
$nocache = isset($_GET['nocache']) && $_GET['nocache'] === '1';

$cacheKey = "stripe_metrics_{$meseSelezionato}_{$annoSelezionato}";
$cacheData = $nocache ? null : cache_get($cacheKey, 600);

if ($cacheData !== null) {
    echo json_encode(['ok' => true, 'data' => $cacheData, 'cached' => true]);
    exit;
}

// Se non in cache, ritorna placeholder e task di background
echo json_encode([
    'ok' => true,
    'data' => ['loading' => true],
    'cached' => false,
    'message' => 'Dati in caricamento...'
]);
exit;
