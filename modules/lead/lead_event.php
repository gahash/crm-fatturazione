<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

$leadId = (int)($_POST['lead_id'] ?? 0);
$type = trim($_POST['type'] ?? '');
$channel = trim($_POST['channel'] ?? '');

header('Content-Type: application/json');

if ($leadId <= 0 || $type === '') {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO lead_events (lead_id, type, channel) VALUES (:lead_id, :type, :channel)");
$stmt->execute([
    ':lead_id' => $leadId,
    ':type' => $type,
    ':channel' => $channel,
]);

echo json_encode(['ok' => true]);
exit;
