<?php
require __DIR__ . '/../../../auth.php';
require_login();

header('Content-Type: application/json');

try {
    $pdo = require __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/fic_api.php';
    require_once __DIR__ . '/fic_mail_helpers.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore caricamento moduli: ' . $e->getMessage()]);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID fattura non valido']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT f.*, c.nome FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
    $stmt->execute([':id' => $id]);
    $fattura = $stmt->fetch();

    if (!$fattura) {
        http_response_code(404);
        echo json_encode(['error' => 'Fattura non trovata']);
        exit;
    }

    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }

    if ($companyId === '') {
        http_response_code(500);
        echo json_encode(['error' => 'company_id FIC mancante']);
        exit;
    }

    $result = [
        'fattura' => [
            'id' => $fattura['id'],
            'numero' => $fattura['numero'] ?? null,
            'fic_document_id' => $fattura['fic_document_id'] ?? null,
            'fic_status' => $fattura['fic_status'] ?? null,
            'fic_synced_at' => $fattura['fic_synced_at'] ?? null,
        ],
        'fic' => [
            'companyId' => $companyId,
            'documentStatus' => null,
            'error' => null,
        ],
    ];

    // Controlla stato documento su FIC
    if (!empty($fattura['fic_document_id'])) {
        $docStatus = fic_get_document_status($companyId, (string)$fattura['fic_document_id']);
        if ($docStatus) {
            $result['fic']['documentStatus'] = $docStatus;
        } else {
            $result['fic']['error'] = 'Documento non trovato su FIC (possibile cancellazione o account diverso)';
        }
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
