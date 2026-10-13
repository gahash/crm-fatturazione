<?php
// Sincronizza ordini da pagamenti Stripe
// Crea ordini nella tabella fatture per tutti i pagamenti Stripe succeeded che non hanno ancora un ordine associato

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../auth.php';
require_login();

// Pulisci qualsiasi output buffer precedente
if (ob_get_level()) ob_clean();

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accesso non autorizzato']);
    exit;
}

$pdo = require __DIR__ . '/../../config/db.php';

function stripe_invoice_auto_send_enabled_from_orders(PDO $pdo): bool
{
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_send_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            send_mode VARCHAR(20) NOT NULL DEFAULT "manuale",
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $stmt = $pdo->prepare('SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute([':setting_key' => 'fatture_send']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['send_mode' => 'manuale', 'enabled' => 0];

        return ((string)($row['send_mode'] ?? 'manuale') === 'automatico') && ((int)($row['enabled'] ?? 0) === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function stripe_set_invoice_mail_pending_from_orders(PDO $pdo, int $fatturaId, string $reason): void
{
    try {
        $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
        $upd->execute([
            ':status' => 'pending',
            ':err' => substr($reason, 0, 1000),
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
    }
}

function stripe_try_auto_send_invoice_from_orders(PDO $pdo, int $fatturaId, bool $autoSendEnabled): void
{
    if (!$autoSendEnabled || $fatturaId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1");
    $stmt->execute([':id' => $fatturaId]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        return;
    }

    if ((string)($fattura['document_type'] ?? '') !== 'invoice') {
        return;
    }

    $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
    if (in_array($eiStatus, ['sent', 'processing', 'accepted'], true)) {
        return;
    }

    $piva = trim((string)($fattura['piva'] ?? ''));
    $codiceSdi = trim((string)($fattura['codice_sdi'] ?? ''));
    $pec = trim((string)($fattura['pec'] ?? ''));
    $anagrafica = trim((string)($fattura['azienda'] ?? '')) !== '' || trim((string)($fattura['nome'] ?? '')) !== '';
    $hasVatData = $piva !== '';
    $hasRecipientData = ($codiceSdi !== '') || ($pec !== '' && filter_var($pec, FILTER_VALIDATE_EMAIL));

    if (!$hasVatData || !$hasRecipientData || !$anagrafica) {
        $missing = [];
        if (!$hasVatData) {
            $missing[] = 'partita IVA';
        }
        if (!$hasRecipientData) {
            $missing[] = 'codice SDI o PEC valida';
        }
        if (!$anagrafica) {
            $missing[] = 'anagrafica (nome/azienda)';
        }
        stripe_set_invoice_mail_pending_from_orders($pdo, $fatturaId, 'Pagamento Stripe registrato: invio automatico non eseguito (anagrafica incompleta: ' . implode(', ', $missing) . ').');
        return;
    }

    require_once __DIR__ . '/../fatture/actions/fic_mail_helpers.php';
    require_once __DIR__ . '/../fatture/actions/fic_audit.php';

    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }

    if ($companyId === '') {
        stripe_set_invoice_mail_pending_from_orders($pdo, $fatturaId, 'Pagamento Stripe registrato: invio automatico non eseguito (company_id FIC mancante).');
        return;
    }

    $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
    $righeStmt->execute([':id' => $fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'auto_stripe_import');

        $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err, mail_sent_at = NOW() WHERE id = :id");
        $upd->execute([
            ':status' => ((string)($fattura['mail_status'] ?? '') !== '' ? (string)$fattura['mail_status'] : 'pending'),
            ':err' => null,
            ':id' => $fatturaId,
        ]);

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'stripe_auto_send_sync', 'ok', 'Invio automatico e-fattura (SDI) da import pagamenti Stripe completato.');
        }
    } catch (Throwable $e) {
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'error',
                ':err' => substr((string)$e->getMessage(), 0, 1000),
                ':id' => $fatturaId,
            ]);
        } catch (Throwable $ignore) {
        }

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'stripe_auto_send_sync', 'error', 'Errore invio automatico da import pagamenti Stripe: ' . substr((string)$e->getMessage(), 0, 180));
        }
    }
}

function stripe_generate_invoice_number_from_payment(PDO $pdo, string $createdAt): string
{
    $ts = strtotime($createdAt);
    if ($ts === false) {
        $ts = time();
    }

    $dayKey = date('Ymd', $ts);
    $prefix = 'STR-INV-' . $dayKey . '-';

    $checkNumStmt = $pdo->prepare('
        SELECT numero
        FROM fatture
        WHERE document_type = "invoice"
          AND numero LIKE ?
        ORDER BY numero DESC
        LIMIT 1
    ');
    $checkNumStmt->execute([$prefix . '%']);
    $lastNum = $checkNumStmt->fetchColumn();

    if ($lastNum && preg_match('/STR-INV-\d{8}-(\d+)/', (string)$lastNum, $matches)) {
        $progressivo = (int)$matches[1] + 1;
    } else {
        $progressivo = 1;
    }

    return sprintf('STR-INV-%s-%03d', $dayKey, $progressivo);
}

$createdOrders = 0;
$convertedLegacy = 0;
$skipped = 0;
$errors = [];

try {
    $autoSendEnabled = stripe_invoice_auto_send_enabled_from_orders($pdo);
    // Verifica se la tabella pagamenti esiste
    $checkTable = $pdo->query("SHOW TABLES LIKE 'pagamenti'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'ok' => false,
            'error' => 'Tabella pagamenti non trovata. Sincronizza prima i pagamenti Stripe.'
        ]);
        exit;
    }
    
    // Recupera tutti i pagamenti Stripe succeeded che non hanno una fattura associata
    $stmt = $pdo->prepare('
        SELECT p.*, c.nome as cliente_nome, c.email as cliente_email
        FROM pagamenti p
        LEFT JOIN clienti c ON p.cliente_id = c.id
        WHERE p.stato = "succeeded" 
        AND p.stripe_id NOT IN (
            SELECT COALESCE(stripe_payment_intent_id, "") 
            FROM fatture 
            WHERE document_type = "invoice"
            AND stripe_payment_intent_id IS NOT NULL 
            AND stripe_payment_intent_id != ""
        )
        ORDER BY p.created_at DESC
    ');
    
    $stmt->execute();
    $pagamenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pagamenti)) {
        echo json_encode([
            'ok' => true,
            'created' => 0,
            'converted_legacy' => 0,
            'skipped' => 0,
            'message' => 'Nessuna nuova fattura da importare'
        ]);
        exit;
    }
    
    // Per ogni pagamento, crea una fattura
    foreach ($pagamenti as $pagamento) {
        try {
            $legacyStmt = $pdo->prepare('
                SELECT id FROM fatture
                WHERE stripe_payment_intent_id = ?
                  AND document_type = "order"
                LIMIT 1
            ');
            $legacyStmt->execute([$pagamento['stripe_id']]);
            $legacyOrderId = (int)($legacyStmt->fetchColumn() ?: 0);

            if ($legacyOrderId > 0) {
                $updLegacy = $pdo->prepare('
                    UPDATE fatture
                    SET document_type = "invoice",
                        note = CONCAT(IFNULL(note, ""), "\n\nConvertita automaticamente da ordine Stripe a fattura.")
                    WHERE id = :id
                ');
                $updLegacy->execute([':id' => $legacyOrderId]);
                $convertedLegacy++;
                stripe_try_auto_send_invoice_from_orders($pdo, $legacyOrderId, $autoSendEnabled);
                continue;
            }

            // Verifica che il cliente esista
            if (empty($pagamento['cliente_id'])) {
                $skipped++;
                $errors[] = "Pagamento {$pagamento['stripe_id']}: cliente non trovato";
                continue;
            }
            
            // Genera numero fattura Stripe: STR-INV-YYYYMMDD-XXX
            $dataFattura = date('Y-m-d', strtotime($pagamento['created_at']));
            $numeroFattura = stripe_generate_invoice_number_from_payment($pdo, (string)$pagamento['created_at']);
            
            // Converti importo da centesimi a euro
            $totale = (float)$pagamento['importo'] / 100;
            
            // Descrizione fattura
            $descrizione = $pagamento['descrizione'] ?? 'Fattura da pagamento Stripe';
            if (empty(trim($descrizione))) {
                $descrizione = 'Fattura da pagamento Stripe';
            }
            
            // Crea la fattura
            $insertStmt = $pdo->prepare('
                INSERT INTO fatture (
                    cliente_id,
                    numero,
                    document_type,
                    data_emissione,
                    stato,
                    totale,
                    note,
                    stripe_payment_intent_id,
                    metodo_pagamento,
                    data_pagamento,
                    creato_il
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $insertStmt->execute([
                $pagamento['cliente_id'],
                $numeroFattura,
                'invoice',
                $dataFattura,
                'pagata', // Se il pagamento è succeeded, la fattura è pagata
                $totale,
                "Fattura importata automaticamente da Stripe\n\n" . $descrizione,
                $pagamento['stripe_id'],
                'Stripe - ' . ($pagamento['metodo'] ?? 'card'),
                $pagamento['created_at'],
                $pagamento['created_at']
            ]);
            
            $fatturaId = (int)$pdo->lastInsertId();
            
            // Crea riga fattura generica
            $insertRigaStmt = $pdo->prepare('
                INSERT INTO fatture_righe (
                    fattura_id,
                    descrizione,
                    quantita,
                    prezzo_unitario,
                    iva,
                    totale_riga
                ) VALUES (?, ?, ?, ?, ?, ?)
            ');
            
            $insertRigaStmt->execute([
                $fatturaId,
                $descrizione,
                1,
                $totale,
                0,
                $totale
            ]);

            stripe_try_auto_send_invoice_from_orders($pdo, $fatturaId, $autoSendEnabled);
            
            $createdOrders++;
            
        } catch (Exception $e) {
            $skipped++;
            $errors[] = "Pagamento {$pagamento['stripe_id']}: " . $e->getMessage();
            error_log("Errore creazione fattura da Stripe: " . $e->getMessage());
        }
    }

    $response = [
        'ok' => true,
        'created' => $createdOrders,
        'converted_legacy' => $convertedLegacy,
        'skipped' => $skipped,
        'total' => count($pagamenti),
        'message' => sprintf(
            'Importazione completata: %d fatture create, %d convertite da ordini legacy, %d saltate',
            $createdOrders,
            $convertedLegacy,
            $skipped
        )
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
