<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';

function clienti_retry_is_db_gone(string $msg): bool
{
    $normalized = strtolower(trim($msg));
    if ($normalized === '') {
        return false;
    }

    return strpos($normalized, 'server has gone away') !== false
        || strpos($normalized, 'general error: 2006') !== false
        || strpos($normalized, 'lost connection') !== false
        || strpos($normalized, 'gone away') !== false;
}

function clienti_refresh_pdo(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        unset($GLOBALS['_tdg_pdo']);
    }

    if (function_exists('get_db')) {
        $pdo = get_db(true);
        return;
    }

    $pdo = require __DIR__ . '/../../config/db.php';
}

function clienti_clear_transient_retry_errors(PDO &$pdo, int $clienteId): void
{
    if ($clienteId <= 0) {
        return;
    }

    clienti_refresh_pdo($pdo);

    $stmt = $pdo->prepare("UPDATE fatture
        SET
            ei_status = CASE
                WHEN LOWER(COALESCE(ei_status, '')) = 'error' THEN NULL
                ELSE ei_status
            END,
            ei_error = CASE
                WHEN LOWER(COALESCE(ei_error, '')) LIKE '%server has gone away%'
                  OR LOWER(COALESCE(ei_error, '')) LIKE '%general error: 2006%'
                  OR LOWER(COALESCE(ei_error, '')) LIKE '%lost connection%'
                  OR LOWER(COALESCE(ei_error, '')) LIKE '%gone away%'
                THEN NULL
                ELSE ei_error
            END,
            mail_error = CASE
                WHEN LOWER(COALESCE(mail_error, '')) LIKE '%server has gone away%'
                  OR LOWER(COALESCE(mail_error, '')) LIKE '%general error: 2006%'
                  OR LOWER(COALESCE(mail_error, '')) LIKE '%lost connection%'
                  OR LOWER(COALESCE(mail_error, '')) LIKE '%gone away%'
                THEN NULL
                ELSE mail_error
            END
        WHERE cliente_id = :cliente_id
          AND document_type = 'invoice'");
    $stmt->execute([':cliente_id' => $clienteId]);
}

function clienti_auto_send_enabled(PDO $pdo): bool
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

function clienti_try_send_pending_fatture(PDO $pdo, int $clienteId): void
{
    try {
        clienti_refresh_pdo($pdo);
        if (!clienti_auto_send_enabled($pdo)) {
            return;
        }

        clienti_refresh_pdo($pdo);
        $cStmt = $pdo->prepare('SELECT id, email, piva FROM clienti WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $clienteId]);
        $cliente = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cliente) {
            return;
        }

        require_once __DIR__ . '/../fatture/actions/fic_mail_helpers.php';

    clienti_clear_transient_retry_errors($pdo, $clienteId);

        $cfg = fic_config();
        $companyId = (string)($cfg['company_id'] ?? '');
        if ($companyId === '') {
            $companyId = (string)fic_get_company_id();
        }
        if ($companyId === '') {
            return;
        }

        $piva = trim((string)($cliente['piva'] ?? ''));
        $email = trim((string)($cliente['email'] ?? ''));
        $mailPath = __DIR__ . '/../../config/mail.php';
        if ($piva !== '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && file_exists($mailPath)) {
            $cfgMail = require $mailPath;

            clienti_refresh_pdo($pdo);
            $fStmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec, c.telefono
                FROM fatture f
                JOIN clienti c ON c.id = f.cliente_id
                WHERE f.cliente_id = :cliente_id
                  AND f.document_type = 'invoice'
                  AND (f.mail_status IS NULL OR f.mail_status <> 'sent')");
            $fStmt->execute([':cliente_id' => $clienteId]);
            $fatture = $fStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($fatture as $fattura) {
                $fatturaId = (int)($fattura['id'] ?? 0);
                if ($fatturaId <= 0) {
                    continue;
                }

                try {
                    clienti_refresh_pdo($pdo);
                    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
                    $righeStmt->execute([':id' => $fatturaId]);
                    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

                    fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale');

                    clienti_refresh_pdo($pdo);
                    $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err, mail_sent_at = NOW() WHERE id = :id');
                    $upd->execute([
                        ':status' => 'sent',
                        ':err' => null,
                        ':id' => $fatturaId,
                    ]);
                } catch (Throwable $e) {
                    try {
                        clienti_refresh_pdo($pdo);
                        $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id');
                        $upd->execute([
                            ':status' => 'error',
                            ':err' => substr((string)$e->getMessage(), 0, 1000),
                            ':id' => $fatturaId,
                        ]);
                    } catch (Throwable $ignored) {
                    }
                }
            }
        }

        clienti_refresh_pdo($pdo);
        $sdiStmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec, c.telefono
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.cliente_id = :cliente_id
              AND f.document_type = 'invoice'
              AND f.stato = 'pagata'
              AND f.fic_document_id IS NOT NULL
              AND TRIM(f.fic_document_id) <> ''
              AND (
                    f.ei_status IS NULL
                    OR TRIM(f.ei_status) = ''
                    OR LOWER(f.ei_status) IN ('todo', 'error')
                  )
            ORDER BY f.id DESC
            LIMIT 20");
        $sdiStmt->execute([':cliente_id' => $clienteId]);
        $fattureSdi = $sdiStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fattureSdi as $fattura) {
            $fatturaId = (int)($fattura['id'] ?? 0);
            if ($fatturaId <= 0) {
                continue;
            }

            try {
                $missingDataError = function_exists('fic_einvoice_missing_data_error')
                    ? fic_einvoice_missing_data_error($fattura)
                    : null;
                if ($missingDataError !== null) {
                    if (function_exists('fic_update_einvoice_tracking')) {
                        fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $missingDataError, false);
                    }
                    if (function_exists('fatture_log')) {
                        clienti_refresh_pdo($pdo);
                        fatture_log($pdo, $fatturaId, 'clienti_auto_sdi_retry', 'info', 'Invio SDI dopo salvataggio cliente saltato: ' . substr($missingDataError, 0, 170));
                    }
                    continue;
                }

                clienti_refresh_pdo($pdo);
                $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
                $righeStmt->execute([':id' => $fatturaId]);
                $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

                fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'clienti_store_update');

                if (function_exists('fatture_log')) {
                    clienti_refresh_pdo($pdo);
                    fatture_log($pdo, $fatturaId, 'clienti_auto_sdi_retry', 'ok', 'Invio SDI ritentato automaticamente dopo salvataggio anagrafica cliente.');
                }
            } catch (Throwable $e) {
                if (clienti_retry_is_db_gone((string)$e->getMessage())) {
                    try {
                        clienti_clear_transient_retry_errors($pdo, $clienteId);
                    } catch (Throwable $ignored) {
                    }
                }
                if (function_exists('fatture_log')) {
                    clienti_refresh_pdo($pdo);
                    fatture_log($pdo, $fatturaId, 'clienti_auto_sdi_retry', 'error', 'Errore retry SDI dopo salvataggio cliente: ' . substr((string)$e->getMessage(), 0, 170));
                }
            }
        }
    } catch (Throwable $e) {
    }
}

function clienti_normalize_vat_for_match(string $value): string
{
    $vat = strtoupper(trim($value));
    $vat = preg_replace('/[\s\.\-\/]/', '', $vat);
    if (!is_string($vat)) {
        return '';
    }
    if (preg_match('/^IT(\d{11})$/', $vat, $m)) {
        return (string)$m[1];
    }
    return $vat;
}

function clienti_address_looks_complete(string $value): bool
{
    $address = trim($value);
    if ($address === '') {
        return false;
    }

    return (bool)(
        preg_match('/\b\d{5}\b/', $address)
        && preg_match('/\b\d{5}\s+[^,]+?\s+[A-Z]{2}\b/i', $address)
    );
}

function clienti_valid_sdi_for_retry(string $value): bool
{
    $sdi = strtoupper(trim($value));
    return (bool)preg_match('/^[A-Z0-9]{7}$/', $sdi) && $sdi !== '0000000';
}

function clienti_valid_pec_for_retry(string $value): bool
{
    $pec = strtolower(trim($value));
    return $pec !== '' && filter_var($pec, FILTER_VALIDATE_EMAIL) !== false;
}

function clienti_update_duplicate_from_source(PDO $pdo, array $source, array $target): void
{
    $set = [];
    $params = [':id' => (int)$target['id']];

    $copyIfEmpty = ['nome', 'azienda', 'email', 'telefono', 'piva'];
    foreach ($copyIfEmpty as $field) {
        $src = trim((string)($source[$field] ?? ''));
        $dst = trim((string)($target[$field] ?? ''));
        if ($src !== '' && $dst === '') {
            $key = ':' . $field;
            $set[] = "`$field` = $key";
            $params[$key] = $src;
        }
    }

    $sourceAddress = trim((string)($source['indirizzo'] ?? ''));
    $targetAddress = trim((string)($target['indirizzo'] ?? ''));
    if (clienti_address_looks_complete($sourceAddress) && !clienti_address_looks_complete($targetAddress)) {
        $set[] = '`indirizzo` = :indirizzo';
        $params[':indirizzo'] = $sourceAddress;
    }

    $sourceSdi = strtoupper(trim((string)($source['codice_sdi'] ?? '')));
    $targetSdi = strtoupper(trim((string)($target['codice_sdi'] ?? '')));
    if (clienti_valid_sdi_for_retry($sourceSdi) && !clienti_valid_sdi_for_retry($targetSdi)) {
        $set[] = '`codice_sdi` = :codice_sdi';
        $params[':codice_sdi'] = $sourceSdi;
    }

    $sourcePec = strtolower(trim((string)($source['pec'] ?? '')));
    $targetPec = strtolower(trim((string)($target['pec'] ?? '')));
    if (clienti_valid_pec_for_retry($sourcePec) && !clienti_valid_pec_for_retry($targetPec)) {
        $set[] = '`pec` = :pec';
        $params[':pec'] = $sourcePec;
    }

    if (!$set) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE clienti SET ' . implode(', ', $set) . ' WHERE id = :id');
    $stmt->execute($params);
}

function clienti_sync_duplicates_and_get_retry_ids(PDO $pdo, int $sourceId): array
{
    if ($sourceId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT id, nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec FROM clienti WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sourceId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            return [$sourceId];
        }

        $ids = [$sourceId => true];
        $sourceVat = clienti_normalize_vat_for_match((string)($source['piva'] ?? ''));
        if ($sourceVat === '') {
            return array_keys($ids);
        }

        $candidatesStmt = $pdo->prepare("SELECT id, nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec FROM clienti WHERE id <> :id AND piva IS NOT NULL AND TRIM(piva) <> ''");
        $candidatesStmt->execute([':id' => $sourceId]);
        $candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $candidate) {
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId <= 0) {
                continue;
            }
            if (clienti_normalize_vat_for_match((string)($candidate['piva'] ?? '')) !== $sourceVat) {
                continue;
            }

            clienti_update_duplicate_from_source($pdo, $source, $candidate);
            $ids[$candidateId] = true;
        }

        return array_map('intval', array_keys($ids));
    } catch (Throwable $e) {
        return [$sourceId];
    }
}

function clienti_retry_after_anagrafica_save(PDO $pdo, int $clienteId): void
{
    $retryIds = clienti_sync_duplicates_and_get_retry_ids($pdo, $clienteId);
    foreach ($retryIds as $retryId) {
        clienti_try_send_pending_fatture($pdo, (int)$retryId);
    }
}

$action = isset($_POST['action']) ? $_POST['action'] : 'create';

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM clienti WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
    header('Location: clienti.php');
    exit;
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE clienti SET nome=:nome, email=:email, telefono=:telefono, azienda=:azienda, indirizzo=:indirizzo, piva=:piva, codice_sdi=:codice_sdi, pec=:pec, note=:note WHERE id=:id");
        $stmt->execute([
            ':nome' => trim($_POST['nome'] ?? ''),
            ':email' => trim($_POST['email'] ?? ''),
            ':telefono' => trim($_POST['telefono'] ?? ''),
            ':azienda' => trim($_POST['azienda'] ?? ''),
            ':indirizzo' => trim($_POST['indirizzo'] ?? ''),
            ':piva' => trim($_POST['piva'] ?? ''),
            ':codice_sdi' => trim($_POST['codice_sdi'] ?? ''),
            ':pec' => trim($_POST['pec'] ?? ''),
            ':note' => trim($_POST['note'] ?? ''),
            ':id' => $id,
        ]);
        clienti_retry_after_anagrafica_save($pdo, $id);
        header('Location: cliente_scheda.php?id=' . $id);
        exit;
    }
}

// create
$stmt = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note) VALUES (:nome, :email, :telefono, :azienda, :indirizzo, :piva, :codice_sdi, :pec, :note)");
$stmt->execute([
    ':nome' => trim($_POST['nome'] ?? ''),
    ':email' => trim($_POST['email'] ?? ''),
    ':telefono' => trim($_POST['telefono'] ?? ''),
    ':azienda' => trim($_POST['azienda'] ?? ''),
    ':indirizzo' => trim($_POST['indirizzo'] ?? ''),
    ':piva' => trim($_POST['piva'] ?? ''),
    ':codice_sdi' => trim($_POST['codice_sdi'] ?? ''),
    ':pec' => trim($_POST['pec'] ?? ''),
    ':note' => trim($_POST['note'] ?? ''),
]);

$newClienteId = (int)$pdo->lastInsertId();
if ($newClienteId > 0) {
    clienti_retry_after_anagrafica_save($pdo, $newClienteId);
}

header('Location: clienti.php');
// Se è un create/update, sincronizza questo cliente in Stripe subito dopo
if ($action === 'create' || $action === 'update') {
    try {
        $clienteId = (int)($_POST['id'] ?? ($newClienteId ?? 0));
        if ($clienteId > 0) {
            $stmtSync = $pdo->prepare("
                SELECT id, nome, email FROM clienti WHERE id = :id
            ");
            $stmtSync->execute([':id' => $clienteId]);
            $cliente = $stmtSync->fetch();

            if ($cliente) {
                // Carica i dati dal secret
                $secret = @require __DIR__ . '/../../config/secret.php';
                $stripeKey = $secret['stripe']['secret_key'] ?? '';

                if ($stripeKey && strpos($stripeKey, 'sk_') === 0) {
                    require_once __DIR__ . '/../../vendor/autoload.php';
                    \Stripe\Stripe::setApiKey($stripeKey);

                    function normalize_name_simple(string $name): string {
                        $name = strtolower(trim($name));
                        $name = preg_replace('/\s+/', ' ', $name);
                        $name = preg_replace('/[^a-z0-9\s\-\._]/', '', $name);
                        return trim($name);
                    }

                    // Cerca il cliente Stripe per nome o email
                    $nameKey = normalize_name_simple($cliente['nome']);
                    $emailKey = strtolower(trim($cliente['email'] ?? ''));
                    $stripeCustomer = null;

                    if ($nameKey) {
                        foreach (\Stripe\Customer::all(['limit' => 100])->autoPagingIterator() as $cust) {
                            if (normalize_name_simple($cust->name) === $nameKey) {
                                $stripeCustomer = $cust;
                                break;
                            }
                        }
                    }

                    if (!$stripeCustomer && $emailKey) {
                        foreach (\Stripe\Customer::all(['limit' => 100])->autoPagingIterator() as $cust) {
                            if (strtolower(trim($cust->email ?? '')) === $emailKey) {
                                $stripeCustomer = $cust;
                                break;
                            }
                        }
                    }

                    // Se trovato, sincronizza
                    if ($stripeCustomer) {
                        $customerId = $stripeCustomer->id;
                        $chargeStats = [
                            'total_amount' => 0,
                            'count' => 0,
                            'last_charge' => null,
                            'last_date' => 0,
                        ];

                        foreach (\Stripe\Charge::all(['limit' => 100, 'expand' => ['data.customer']])->autoPagingIterator() as $charge) {
                            $cid = is_string($charge->customer) ? $charge->customer : ($charge->customer->id ?? null);
                            if ($cid === $customerId) {
                                if ($charge->status === 'succeeded') {
                                    $chargeStats['total_amount'] += (int)($charge->amount ?? 0);
                                    $chargeStats['count']++;
                                }
                                $created = (int)($charge->created ?? 0);
                                if ($created > $chargeStats['last_date']) {
                                    $chargeStats['last_date'] = $created;
                                    $chargeStats['last_charge'] = $charge;
                                }
                            }
                        }

                        $lastChargeDate = null;
                        $lastChargeAmount = 0;
                        $paymentMethod = null;
                        if ($chargeStats['last_charge']) {
                            $lastChargeDate = date('Y-m-d H:i:s', (int)($chargeStats['last_charge']->created ?? 0));
                            $lastChargeAmount = (int)($chargeStats['last_charge']->amount ?? 0);
                            $paymentMethod = (string)($chargeStats['last_charge']->payment_method_details->type ?? '');
                        }

                        $stmtInsert = $pdo->prepare("
                            INSERT INTO stripe_sync (cliente_id, stripe_customer_id, stripe_email, stripe_name, stripe_balance, stripe_country, last_charge_amount, last_charge_date, payment_method, total_charges, total_amount_charged, synced_at)
                            VALUES (:cliente_id, :stripe_customer_id, :stripe_email, :stripe_name, :stripe_balance, :stripe_country, :last_charge_amount, :last_charge_date, :payment_method, :total_charges, :total_amount_charged, NOW())
                            ON DUPLICATE KEY UPDATE
                            stripe_customer_id = VALUES(stripe_customer_id),
                            stripe_email = VALUES(stripe_email),
                            stripe_name = VALUES(stripe_name),
                            stripe_balance = VALUES(stripe_balance),
                            stripe_country = VALUES(stripe_country),
                            last_charge_amount = VALUES(last_charge_amount),
                            last_charge_date = VALUES(last_charge_date),
                            payment_method = VALUES(payment_method),
                            total_charges = VALUES(total_charges),
                            total_amount_charged = VALUES(total_amount_charged),
                            synced_at = NOW()
                        ");
                        $stmtInsert->execute([
                            ':cliente_id' => $clienteId,
                            ':stripe_customer_id' => $customerId,
                            ':stripe_email' => (string)($stripeCustomer->email ?? ''),
                            ':stripe_name' => (string)($stripeCustomer->name ?? ''),
                            ':stripe_balance' => (int)($stripeCustomer->balance ?? 0),
                            ':stripe_country' => $stripeCustomer->address ? $stripeCustomer->address->country : null,
                            ':last_charge_amount' => $lastChargeAmount,
                            ':last_charge_date' => $lastChargeDate,
                            ':payment_method' => $paymentMethod,
                            ':total_charges' => (int)($chargeStats['count'] ?? 0),
                            ':total_amount_charged' => (int)($chargeStats['total_amount'] ?? 0),
                        ]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Silenzioso, non bloccare l'operazione principale
        error_log("Auto-sync Stripe error: " . $e->getMessage());
    }
}
