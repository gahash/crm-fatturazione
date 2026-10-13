<?php
/**
 * Fallback solo Fatture in Cloud:
 * risolve il documento su FIC e reindirizza alla sua pagina.
 */

require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'ID non valido.';
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
    http_response_code(404);
    echo 'Fattura non trovata.';
    exit;
}

$cfg = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}
if ($companyId === '') {
    http_response_code(500);
    echo 'Fatture in Cloud: company_id mancante.';
    exit;
}

$docId = (string)($fattura['fic_document_id'] ?? '');
if ($docId === '') {
    $docType = $fattura['document_type'] ?? 'invoice';
    [$foundDocId, $foundStatus] = fic_find_existing_document_by_number($companyId, $docType, $fattura);
    if ($foundDocId) {
        $docId = (string)$foundDocId;
        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
        $upd->execute([
            ':doc_id' => $docId,
            ':status' => $foundStatus,
            ':id' => $id,
        ]);
    }
}

if ($docId === '') {
    http_response_code(409);
    echo 'Documento Fatture in Cloud non trovato. Sincronizza prima la fattura su FIC.';
    exit;
}

$ficUrl = "https://secure.fattureincloud.it/azienda-{$companyId}/documenti/fatture/emesse/{$docId}";
header('Location: ' . $ficUrl);
exit;
