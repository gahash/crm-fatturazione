<?php
/**
 * Rigenera fatture bloccate per duplicato FIC:
 * intercetta sia duplicate_unresolved sia i vecchi sync_error con firma duplicate,
 * assegna un nuovo numero univoco e riprova creazione su FIC + invio SDI.
 */
ob_start();
require_once __DIR__ . '/../../../auth.php';
require_login();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/fic_mail_helpers.php';

$pdo = require __DIR__ . '/../../../config/db.php';
$singleInvoiceId = max(0, (int)($_GET['fattura_id'] ?? $_POST['fattura_id'] ?? 0));

try {

$cfg = fic_config();
$cfg['allow_duplicate_force_create'] = true;
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}
if ($companyId === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'company_id FIC mancante']);
    exit;
}

$sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
     FROM fatture f
     JOIN clienti c ON c.id = f.cliente_id
     WHERE f.document_type = 'invoice'
       AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
       AND (
            LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'
            OR LOWER(COALESCE(f.mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(f.ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
       )";

if ($singleInvoiceId > 0) {
    $sql .= ' AND f.id = :fattura_id';
}

$sql .= ' ORDER BY f.id ASC';

$stmt = $pdo->prepare($sql);
if ($singleInvoiceId > 0) {
    $stmt->bindValue(':fattura_id', $singleInvoiceId, PDO::PARAM_INT);
}
$stmt->execute();
$fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$fatture) {
    $message = $singleInvoiceId > 0
        ? 'La fattura selezionata non risulta attualmente bloccata per duplicato FIC.'
        : 'Nessuna fattura bloccata per duplicato FIC trovata.';
    echo json_encode(['ok' => true, 'fixed' => 0, 'failed' => 0, 'message' => $message]);
    exit;
}

$fixed = 0;
$failed = 0;
$errors = [];

foreach ($fatture as $fattura) {
    $fatturaId = (int)$fattura['id'];

    // Genera un numero realmente univoco anche su riesecuzioni successive.
    $newNumero = fic_generate_duplicate_retry_number($pdo, $fattura);
    if ($newNumero === null || $newNumero === '') {
        $datePart = date('Ymd-His');
        $newNumero = 'STR-INV-' . $datePart . '-R' . $fatturaId;
    }

    // Aggiorna numero locale e azzera lo stato FIC per riprovare.
    // Non tocchiamo fatture gia collegate su FIC: sono escluse a monte dalla query.
    $pdo->prepare(
        "UPDATE fatture SET numero = :num, fic_status = NULL, fic_document_id = NULL, ei_status = NULL, ei_error = NULL, mail_error = NULL WHERE id = :id"
    )->execute([':num' => $newNumero, ':id' => $fatturaId]);

    // Ricarica fattura aggiornata
    $stmtF = $pdo->prepare(
        "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
         FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1"
    );
    $stmtF->execute([$fatturaId]);
    $fattura = $stmtF->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        $failed++;
        $errors[] = "ID $fatturaId: fattura non trovata dopo aggiornamento";
        continue;
    }
    $targetNumeration = fic_duplicate_recovery_numeration($cfg, ['fic_forced_number' => '1'] + $fattura);
    if ($targetNumeration !== '') {
        $fattura['fic_target_numeration'] = $targetNumeration;
    }
    $forcedNumber = $targetNumeration !== ''
        ? fic_build_smart_recovery_number($fattura)
        : fic_pick_chronological_number($companyId, (string)($fattura['document_type'] ?? 'invoice'), $fattura, 15, [], $targetNumeration);
    if ($forcedNumber === null && $targetNumeration !== '') {
        $forcedNumber = 1;
    }
    if ($forcedNumber !== null) {
        $fattura['fic_forced_number'] = (string)$forcedNumber;
    }

    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC');
    $righeStmt->execute([$fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        // Crea documento su FIC
        [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);

        if ($docId) {
            // Prova invio SDI
            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'manual_rigenera');
        }
        $fixed++;
    } catch (Throwable $e) {
        $failed++;
        $errors[] = 'Fattura #' . $fattura['numero'] . ': ' . substr($e->getMessage(), 0, 200);
    }
}

echo json_encode([
    'ok'      => true,
    'fixed'   => $fixed,
    'failed'  => $failed,
    'errors'  => $errors,
    'single'  => $singleInvoiceId > 0,
    'message' => "Rigenerate: $fixed, errori: $failed",
]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
