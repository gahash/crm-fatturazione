<?php
require __DIR__ . '/../../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
$stato = (string)($_POST['stato'] ?? '');
$allowed = ['bozza', 'emessa', 'pagata', 'scaduta'];

if ($id <= 0 || !in_array($stato, $allowed, true)) {
    http_response_code(400);
    echo 'Dati non validi.';
    exit;
}

$stmt = $pdo->prepare("UPDATE fatture SET stato = :stato WHERE id = :id");
$stmt->execute([
    ':stato' => $stato,
    ':id' => $id,
]);

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');
header("Location: {$baseUrl}/modules/fatture/dettaglio_fattura.php?id={$id}&stato=ok");
exit;

