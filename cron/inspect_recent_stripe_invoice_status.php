<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$limit = max(1, min(10, (int)($_GET['limit'] ?? 5)));
$pdo = require __DIR__ . '/../config/db.php';

try {
    $sql = "SELECT
                p.id AS pagamento_id,
                p.stripe_id,
                p.importo,
                p.valuta,
                p.stato AS pagamento_stato,
                p.metodo,
                p.created_at,
                c.id AS cliente_id,
                TRIM(COALESCE(NULLIF(c.azienda, ''), NULLIF(c.nome, ''), CONCAT('Cliente #', c.id))) AS cliente,
                f.id AS fattura_id,
                f.numero AS fattura_numero,
                f.stato AS fattura_stato,
                f.fic_document_id,
                f.fic_status,
                f.ei_status,
                f.ei_error,
                f.mail_status,
                f.mail_error,
                f.data_emissione,
                f.data_pagamento
            FROM pagamenti p
            LEFT JOIN clienti c ON c.id = p.cliente_id
            LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id
            WHERE p.stripe_id IS NOT NULL
              AND TRIM(p.stripe_id) <> ''
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT " . $limit;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'limit' => $limit,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}