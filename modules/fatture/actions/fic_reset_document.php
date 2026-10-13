<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_audit.php';

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID fattura non valido']);
    exit;
}

try {
    $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_pdf_url = NULL, fic_synced_at = NULL WHERE id = :id");
    $upd->execute([':id' => $id]);

    fatture_log($pdo, $id, 'fic_reset', 'info', 'Dati FIC azzerati manualmente (debug).');

    echo json_encode([
        'success' => true,
        'message' => 'Dati FIC azzerati. La fattura sarà ricreata al prossimo invio.'
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
