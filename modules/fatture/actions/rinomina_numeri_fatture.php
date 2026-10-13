<?php
// modules/fatture/actions/rinomina_numeri_fatture.php
// Rinomina i numeri delle fatture non ancora sincronizzate su FIC
// per evitare errori di duplicato durante la sincronizzazione.

require_once __DIR__ . '/../../../auth.php';
require_login();

if (ob_get_level()) ob_clean();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accesso non autorizzato']);
    exit;
}

$pdo = require __DIR__ . '/../../../config/db.php';

try {
    // Recupera tutte le fatture non ancora sincronizzate su FIC
    // (fic_document_id null/vuoto e stato non duplicate_unresolved)
    $stmt = $pdo->query(
        "SELECT id, numero FROM fatture
         WHERE (fic_document_id IS NULL OR TRIM(fic_document_id) = '')
           AND COALESCE(fic_status, '') <> 'duplicate_unresolved'
         ORDER BY id ASC"
    );
    $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$fatture) {
        echo json_encode([
            'ok' => true,
            'renamed' => 0,
            'message' => 'Nessuna fattura non sincronizzata trovata.'
        ]);
        exit;
    }

    $anno = date('Y');
    $renamed = 0;
    $skipped = 0;
    $errors = [];

    $upd = $pdo->prepare(
        "UPDATE fatture SET numero = :nuovo_numero WHERE id = :id LIMIT 1"
    );

    foreach ($fatture as $f) {
        $id = (int)$f['id'];
        $vecchioNumero = (string)($f['numero'] ?? '');

        // Formato: STR-{ANNO}-{ID} — garantisce unicità assoluta
        $nuovoNumero = 'STR-' . $anno . '-' . $id;

        // Salta se il numero è già nel formato corretto
        if ($vecchioNumero === $nuovoNumero) {
            $skipped++;
            continue;
        }

        try {
            $upd->execute([
                ':nuovo_numero' => $nuovoNumero,
                ':id' => $id,
            ]);
            $renamed++;
        } catch (Throwable $e) {
            $errors[] = 'Fattura #' . $id . ': ' . substr($e->getMessage(), 0, 120);
        }
    }

    $msg = "Rinominate: {$renamed}";
    if ($skipped > 0) {
        $msg .= ", già corrette: {$skipped}";
    }
    if ($errors) {
        $msg .= ', errori: ' . count($errors);
    }

    echo json_encode([
        'ok' => true,
        'renamed' => $renamed,
        'skipped' => $skipped,
        'errors' => $errors,
        'message' => $msg,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Errore: ' . substr($e->getMessage(), 0, 300),
    ]);
}
