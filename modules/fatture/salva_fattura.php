<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../dashboard/cache_utils.php';

header('Content-Type: application/json; charset=utf-8');

function fatture_json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fatture_normalize_email(string $email): string
{
    $value = trim($email);
    if ($value === '') {
        return '';
    }

    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

function fatture_parse_address_parts(string $value): array
{
    $raw = trim($value);
    if ($raw === '') {
        return ['postal_code' => '', 'city' => '', 'province' => ''];
    }

    $postalCode = '';
    $city = '';
    $province = '';

    if (preg_match('/\b(\d{5})\b/', $raw, $m)) {
        $postalCode = (string)$m[1];
    }

    if (preg_match('/(?:\(|,\s*|\s+)([A-Za-z]{2})\)?\s*$/', trim($raw), $m)) {
        $province = strtoupper((string)$m[1]);
    }

    if ($postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\s+(.+?)\s*\(?([A-Za-z]{2})\)?(?:\s*,|$)/i', $raw, $m)) {
        $city = trim((string)$m[1]);
        if ($province === '') {
            $province = strtoupper((string)$m[2]);
        }
    }

    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\b\s*,?\s*([^,]+?)(?:\s*,\s*[A-Za-z]{2})?(?:\s*,|$)/i', $raw, $m)) {
        $city = trim((string)$m[1]);
    }

    return [
        'postal_code' => preg_match('/^\d{5}$/', $postalCode) ? $postalCode : '',
        'city' => trim($city),
        'province' => preg_match('/^[A-Z]{2}$/', $province) ? $province : '',
    ];
}

function fatture_is_valid_city_name(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    return (bool)preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u", $value);
}

function fatture_validate_einvoice_address_or_throw(array $postData): void
{
    $vat = trim((string)($postData['cliente_piva'] ?? ''));
    $sdi = trim((string)($postData['cliente_codice_sdi'] ?? ''));
    $pec = fatture_normalize_email((string)($postData['cliente_pec'] ?? ''));

    $isEInvoiceCandidate = ($vat !== '') && ($sdi !== '' || $pec !== '');
    if (!$isEInvoiceCandidate) {
        return;
    }

    $address = trim((string)($postData['cliente_indirizzo'] ?? ''));
    if ($address === '') {
        throw new InvalidArgumentException('Indirizzo cliente obbligatorio per e-fattura (formato: Via Roma 10, 20100 Milano MI).');
    }

    $parts = fatture_parse_address_parts($address);
    $missing = [];
    if ($parts['postal_code'] === '') {
        $missing[] = 'CAP valido (5 cifre)';
    }
    if (!fatture_is_valid_city_name($parts['city'])) {
        $missing[] = 'Comune valido';
    }
    if ($parts['province'] === '') {
        $missing[] = 'Provincia valida (2 lettere)';
    }

    if ($missing) {
        throw new InvalidArgumentException('Indirizzo cliente non valido per e-fattura: manca ' . implode(' + ', $missing) . '. Usa formato: Via Roma 10, 20100 Milano MI.');
    }
}

function fatture_resolve_cliente_id(PDO $pdo, array $postData): int
{
    $clienteId = (int)($postData['cliente_id'] ?? 0);
    if ($clienteId > 0) {
        return $clienteId;
    }

    $nome = trim((string)($postData['cliente_nome'] ?? ''));
    if ($nome === '') {
        throw new InvalidArgumentException('Nome cliente obbligatorio.');
    }

    fatture_validate_einvoice_address_or_throw($postData);

    $email = fatture_normalize_email((string)($postData['cliente_email'] ?? ''));
    $telefono = trim((string)($postData['cliente_telefono'] ?? ''));
    $azienda = trim((string)($postData['cliente_azienda'] ?? ''));
    $indirizzo = trim((string)($postData['cliente_indirizzo'] ?? ''));
    $piva = trim((string)($postData['cliente_piva'] ?? ''));
    $codiceSdi = trim((string)($postData['cliente_codice_sdi'] ?? ''));
    $pec = fatture_normalize_email((string)($postData['cliente_pec'] ?? ''));
    $note = trim((string)($postData['cliente_note'] ?? ''));

    $existingId = 0;

    if ($piva !== '') {
        $stmtByPiva = $pdo->prepare("SELECT id FROM clienti WHERE piva = :piva ORDER BY id DESC LIMIT 1");
        $stmtByPiva->execute([':piva' => $piva]);
        $existingId = (int)($stmtByPiva->fetchColumn() ?: 0);
    }

    if ($existingId === 0 && $email !== '') {
        $stmtByEmail = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(email) = LOWER(:email) ORDER BY id DESC LIMIT 1");
        $stmtByEmail->execute([':email' => $email]);
        $existingId = (int)($stmtByEmail->fetchColumn() ?: 0);
    }

    if ($existingId > 0) {
        $updateStmt = $pdo->prepare("
            UPDATE clienti
            SET
                nome = :nome,
                email = CASE WHEN :email <> '' THEN :email ELSE email END,
                telefono = CASE WHEN :telefono <> '' THEN :telefono ELSE telefono END,
                azienda = CASE WHEN :azienda <> '' THEN :azienda ELSE azienda END,
                indirizzo = CASE WHEN :indirizzo <> '' THEN :indirizzo ELSE indirizzo END,
                piva = CASE WHEN :piva <> '' THEN :piva ELSE piva END,
                codice_sdi = CASE WHEN :codice_sdi <> '' THEN :codice_sdi ELSE codice_sdi END,
                pec = CASE WHEN :pec <> '' THEN :pec ELSE pec END,
                note = CASE WHEN :note <> '' THEN :note ELSE note END
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':id' => $existingId,
            ':nome' => $nome,
            ':email' => $email,
            ':telefono' => $telefono,
            ':azienda' => $azienda,
            ':indirizzo' => $indirizzo,
            ':piva' => $piva,
            ':codice_sdi' => $codiceSdi,
            ':pec' => $pec,
            ':note' => $note,
        ]);

        return $existingId;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note)
        VALUES (:nome, :email, :telefono, :azienda, :indirizzo, :piva, :codice_sdi, :pec, :note)
    ");
    $insertStmt->execute([
        ':nome' => $nome,
        ':email' => $email !== '' ? $email : null,
        ':telefono' => $telefono !== '' ? $telefono : null,
        ':azienda' => $azienda !== '' ? $azienda : null,
        ':indirizzo' => $indirizzo !== '' ? $indirizzo : null,
        ':piva' => $piva !== '' ? $piva : null,
        ':codice_sdi' => $codiceSdi !== '' ? $codiceSdi : null,
        ':pec' => $pec !== '' ? $pec : null,
        ':note' => $note !== '' ? $note : null,
    ]);

    return (int)$pdo->lastInsertId();
}

function fatture_auto_send_enabled(PDO $pdo): bool
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
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        return (string)($row['send_mode'] ?? 'manuale') === 'automatico' && (int)($row['enabled'] ?? 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function try_auto_send_invoice(PDO $pdo, int $fatturaId): void
{
    try {
        if (!fatture_auto_send_enabled($pdo)) {
            return;
        }

        $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1");
        $stmt->execute([':id' => $fatturaId]);
        $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fattura) {
            return;
        }

        $piva = trim((string)($fattura['piva'] ?? ''));
        $email = trim((string)($fattura['email'] ?? ''));
        if ($piva === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $mailPath = __DIR__ . '/../../config/mail.php';
        if (!file_exists($mailPath)) {
            return;
        }

        require_once __DIR__ . '/actions/fic_mail_helpers.php';
        $cfgMail = require $mailPath;
        $cfg = fic_config();
        $companyId = (string)($cfg['company_id'] ?? '');
        if ($companyId === '') {
            $companyId = (string)fic_get_company_id();
        }
        if ($companyId === '') {
            return;
        }

        $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
        $righeStmt->execute([':id' => $fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

        fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale');

        $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err, mail_sent_at = NOW() WHERE id = :id");
        $upd->execute([
            ':status' => 'sent',
            ':err' => null,
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'error',
                ':err' => substr((string)$e->getMessage(), 0, 1000),
                ':id' => $fatturaId,
            ]);
        } catch (Throwable $ignored) {
        }
    }
}

function try_sync_invoice_to_fic(PDO $pdo, int $fatturaId): array
{
    try {
        require_once __DIR__ . '/actions/fic_mail_helpers.php';
        require_once __DIR__ . '/actions/fic_audit.php';

        $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1");
        $stmt->execute([':id' => $fatturaId]);
        $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fattura) {
            return ['ok' => false, 'error' => 'Fattura non trovata dopo il salvataggio.'];
        }

        $cfg = fic_config();
        $companyId = (string)($cfg['company_id'] ?? '');
        if ($companyId === '') {
            $companyId = (string)fic_get_company_id();
        }
        if ($companyId === '') {
            return ['ok' => false, 'error' => 'company_id Fatture in Cloud mancante.'];
        }

        $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
        $righeStmt->execute([':id' => $fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

        [$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
        if (!$docId) {
            return ['ok' => false, 'error' => 'Documento FIC non creato.'];
        }

        fic_publish_document_if_draft($companyId, (string)$docId);
        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'fic_sync', 'ok', 'Sincronizzazione automatica completata in creazione fattura.', [
                'fic_document_id' => $docId,
                'fic_status' => $status,
            ]);
        }

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        $error = substr((string)$e->getMessage(), 0, 400);
        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'fic_sync', 'error', 'Errore sincronizzazione automatica in creazione fattura: ' . $error);
        }
        return ['ok' => false, 'error' => $error];
    }
}

try {
    $documentType = trim((string)($_POST['document_type'] ?? 'invoice'));
    $allowedDocTypes = ['invoice', 'order', 'quote'];
    if (!in_array($documentType, $allowedDocTypes, true)) {
        $documentType = 'invoice';
    }

    $numero = trim((string)($_POST['numero'] ?? ''));
    if ($numero === '') {
        fatture_json_response(422, ['ok' => false, 'message' => 'Numero documento obbligatorio.']);
    }

    $dataEmissione = trim((string)($_POST['data_emissione'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEmissione)) {
        fatture_json_response(422, ['ok' => false, 'message' => 'Data emissione non valida.']);
    }

    // Se stato=pagata e c'è un pagamento Stripe collegato, allinea data_emissione alla data del pagamento
    $stripePaymentIntent = trim((string)($_POST['stripe_payment_intent_id'] ?? ''));
    if ($stripePaymentIntent !== '') {
        $stmtPag = $pdo->prepare("SELECT created_at FROM pagamenti WHERE stripe_id = :sid LIMIT 1");
        $stmtPag->execute([':sid' => $stripePaymentIntent]);
        $pagRow = $stmtPag->fetch(PDO::FETCH_ASSOC);
        if ($pagRow && !empty($pagRow['created_at'])) {
            $dataEmissione = date('Y-m-d', strtotime($pagRow['created_at']));
        }
    }

    $dataScadenzaRaw = trim((string)($_POST['data_scadenza'] ?? ''));
    $dataScadenza = null;
    if ($dataScadenzaRaw !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataScadenzaRaw)) {
            fatture_json_response(422, ['ok' => false, 'message' => 'Data scadenza non valida.']);
        }
        $dataScadenza = $dataScadenzaRaw;
    }

    $stato = trim((string)($_POST['stato'] ?? 'bozza'));
    $allowedStates = ['bozza', 'emessa', 'pagata', 'scaduta'];
    if (!in_array($stato, $allowedStates, true)) {
        $stato = 'bozza';
    }

    $totale = (float)($_POST['totale'] ?? 0);
    if ($totale < 0) {
        fatture_json_response(422, ['ok' => false, 'message' => 'Il totale non può essere negativo.']);
    }

    $note = trim((string)($_POST['note'] ?? ''));
    $syncFic = (int)($_POST['sync_fic'] ?? 0) === 1;

    $pdo->beginTransaction();
    $clienteId = fatture_resolve_cliente_id($pdo, $_POST);
    if ($clienteId <= 0) {
        throw new RuntimeException('Cliente non valido.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO fatture (cliente_id, numero, document_type, data_emissione, data_scadenza, stato, totale, note)
        VALUES (:cliente_id, :numero, :document_type, :data_emissione, :data_scadenza, :stato, :totale, :note)
    ");
    $stmt->execute([
        ':cliente_id' => $clienteId,
        ':numero' => $numero,
        ':document_type' => $documentType,
        ':data_emissione' => $dataEmissione,
        ':data_scadenza' => $dataScadenza,
        ':stato' => $stato,
        ':totale' => $totale,
        ':note' => $note,
    ]);

    $fatturaId = (int)$pdo->lastInsertId();
    $pdo->commit();
    tdg_dashboard_cache_clear();

    $ficSyncResult = ['ok' => false, 'error' => null];
    if ($syncFic) {
        $ficSyncResult = try_sync_invoice_to_fic($pdo, $fatturaId);
    }

    try_auto_send_invoice($pdo, $fatturaId);

    $message = 'Fattura salvata con successo.';
    if ($syncFic && $ficSyncResult['ok']) {
        $message = 'Fattura salvata e sincronizzata su Fatture in Cloud.';
    } elseif ($syncFic && !$ficSyncResult['ok']) {
        $message = 'Fattura salvata, ma sincronizzazione FIC non completata: ' . ($ficSyncResult['error'] ?: 'errore sconosciuto.');
    }

    fatture_json_response(200, [
        'ok' => true,
        'fattura_id' => $fatturaId,
        'fic_synced' => (bool)$ficSyncResult['ok'],
        'message' => $message,
    ]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fatture_json_response(422, ['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fatture_json_response(500, ['ok' => false, 'message' => 'Errore salvataggio fattura: ' . $e->getMessage()]);
}
