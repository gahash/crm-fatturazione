
<?php
// Sicurezza output: nessun output prima del JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '240');

// Registra shutdown handler per catturare fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Fatal error: ' . ($error['message'] ?? 'sconosciuto'),
            'file' => basename($error['file'] ?? ''),
            'line' => $error['line'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/stripe_customer_links.php';
require_once __DIR__ . '/../dashboard/cache_utils.php';

// Imposta header JSON SEMPRE
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
}

// Pulisci eventuale output precedente
if (ob_get_length()) {
    ob_clean();
}

$isScheduled = isset($_GET['scheduled']) && $_GET['scheduled'] === '1';
$cronKey = (string)($_GET['cron'] ?? '');
require_once __DIR__ . '/../../config/env.php';
$expectedCronKey = (string)(getenv('CRON_SECRET') ?: '');

if ($isScheduled && ($cronKey === '' || !hash_equals($expectedCronKey, $cronKey))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accesso cron non autorizzato']);
    exit;
}

$requestPayload = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $requestPayload = $decoded;
        }
    }
}

$invoiceFromDateInput = trim((string)($requestPayload['invoice_from_date'] ?? ($_POST['invoice_from_date'] ?? '')));
$invoiceFromTs = 0;
$invoiceFromDateApplied = '';
if ($invoiceFromDateInput !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceFromDateInput)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Formato data non valido. Usa YYYY-MM-DD']);
        exit;
    }

    $parts = explode('-', $invoiceFromDateInput);
    $year = (int)($parts[0] ?? 0);
    $month = (int)($parts[1] ?? 0);
    $day = (int)($parts[2] ?? 0);
    if (!checkdate($month, $day, $year)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Data non valida']);
        exit;
    }

    $invoiceFromTs = strtotime(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
    if ($invoiceFromTs === false || $invoiceFromTs <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Data non valida']);
        exit;
    }
    $invoiceFromDateApplied = sprintf('%04d-%02d-%02d', $year, $month, $day);
}


$config = require __DIR__ . '/../../config/config.php';
$secretConfigPath = __DIR__ . '/../../config/secret.php';
$stripeConfig = file_exists($secretConfigPath)
    ? ((require $secretConfigPath)['stripe'] ?? [])
    : ($config['stripe'] ?? []);

$stripe_secret_key = $stripeConfig['secret_key'] ?? null;

if (!$stripe_secret_key || strpos($stripe_secret_key, 'sk_') !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Stripe non configurato']);
    exit;
}

$pdo = require __DIR__ . '/../../config/db.php';

function stripe_invoice_auto_send_enabled(PDO $pdo): bool
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

function stripe_set_invoice_mail_pending(PDO $pdo, int $fatturaId, string $reason): void
{
    try {
        $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id');
        $upd->execute([
            ':status' => 'pending',
            ':err' => substr($reason, 0, 1000),
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
    }
}

function &stripe_auto_send_stats_store(): array
{
    static $stats = [
        'attempted' => 0,
        'sent' => 0,
        'errors' => 0,
        'already_sent' => 0,
        'skipped_incomplete' => 0,
        'skipped_config' => 0,
        'skipped_excluded' => 0,
        'skipped_other' => 0,
        'sent_invoice_ids' => [],
        'error_invoice_ids' => [],
        'skipped_invoice_ids' => [],
    ];
    return $stats;
}

function stripe_auto_send_mark(string $bucket, int $fatturaId = 0): void
{
    $stats =& stripe_auto_send_stats_store();
    if (!isset($stats[$bucket])) {
        return;
    }
    $stats[$bucket]++;

    if ($fatturaId <= 0) {
        return;
    }

    if ($bucket === 'sent') {
        $stats['sent_invoice_ids'][(string)$fatturaId] = true;
    } elseif ($bucket === 'errors') {
        $stats['error_invoice_ids'][(string)$fatturaId] = true;
    } elseif (strpos($bucket, 'skipped_') === 0 || $bucket === 'already_sent') {
        $stats['skipped_invoice_ids'][(string)$fatturaId] = true;
    }
}

function stripe_auto_send_stats_snapshot(): array
{
    $stats = stripe_auto_send_stats_store();
    return [
        'attempted' => (int)($stats['attempted'] ?? 0),
        'sent' => (int)($stats['sent'] ?? 0),
        'errors' => (int)($stats['errors'] ?? 0),
        'already_sent' => (int)($stats['already_sent'] ?? 0),
        'skipped_incomplete' => (int)($stats['skipped_incomplete'] ?? 0),
        'skipped_config' => (int)($stats['skipped_config'] ?? 0),
        'skipped_excluded' => (int)($stats['skipped_excluded'] ?? 0),
        'skipped_other' => (int)($stats['skipped_other'] ?? 0),
        'sent_invoice_ids' => array_map('intval', array_keys($stats['sent_invoice_ids'] ?? [])),
        'error_invoice_ids' => array_map('intval', array_keys($stats['error_invoice_ids'] ?? [])),
        'skipped_invoice_ids' => array_map('intval', array_keys($stats['skipped_invoice_ids'] ?? [])),
    ];
}

function stripe_collect_pdf_delivery_stats(PDO $pdo, array $invoiceIds): array
{
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static function ($id) {
        return $id > 0;
    })));

    $stats = [
        'checked_invoices' => count($invoiceIds),
        'logs_found' => 0,
        'sent_ok' => 0,
        'errors' => 0,
        'pdf_attached' => 0,
        'pdf_link' => 0,
        'pending_without_log' => count($invoiceIds),
        'unavailable' => false,
    ];

    if (empty($invoiceIds)) {
        return $stats;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql = "
            SELECT l.fattura_id, l.esito, l.meta_json
            FROM fatture_log l
            INNER JOIN (
                SELECT fattura_id, MAX(id) AS max_id
                FROM fatture_log
                WHERE azione = 'send_mail'
                  AND fattura_id IN ($placeholders)
                GROUP BY fattura_id
            ) t ON t.max_id = l.id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($invoiceIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats['logs_found'] = count($rows);
        $stats['pending_without_log'] = max(0, count($invoiceIds) - $stats['logs_found']);

        foreach ($rows as $row) {
            $esito = (string)($row['esito'] ?? '');
            if ($esito === 'ok') {
                $stats['sent_ok']++;
            } elseif ($esito === 'error') {
                $stats['errors']++;
            }

            $meta = [];
            if (!empty($row['meta_json'])) {
                $meta = json_decode((string)$row['meta_json'], true);
                if (!is_array($meta)) {
                    $meta = [];
                }
            }

            $pdfStatus = strtolower(trim((string)($meta['pdf_status'] ?? '')));
            if ($pdfStatus === 'attached') {
                $stats['pdf_attached']++;
            } elseif ($pdfStatus === 'link') {
                $stats['pdf_link']++;
            }
        }
    } catch (Throwable $e) {
        $stats['unavailable'] = true;
        $stats['error'] = substr((string)$e->getMessage(), 0, 180);
    }

    return $stats;
}

function stripe_to_array($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_object($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return [];
}

function stripe_first_non_empty(array $values): string
{
    foreach ($values as $value) {
        $txt = trim((string)$value);
        if ($txt !== '') {
            return $txt;
        }
    }
    return '';
}

function stripe_metadata_pick(array $meta, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $meta)) {
            continue;
        }
        $value = trim((string)$meta[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function stripe_normalize_vat_number(string $vat): string
{
    $vat = strtoupper(trim($vat));
    if ($vat === '') {
        return '';
    }
    return preg_replace('/\s+/', '', $vat) ?: $vat;
}

function stripe_exclusion_file_path(): string
{
    return __DIR__ . '/../../tmp/fatture_exclusions.json';
}

function stripe_exclusion_list_load(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $path = stripe_exclusion_file_path();
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache = [];
        return $cache;
    }

    $items = $decoded['items'] ?? $decoded;
    if (!is_array($items)) {
        $cache = [];
        return $cache;
    }

    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $row = [
            'cliente_id' => (int)($item['cliente_id'] ?? 0),
            'email' => strtolower(trim((string)($item['email'] ?? ''))),
            'piva' => stripe_normalize_vat_number((string)($item['piva'] ?? '')),
            'nome' => strtolower(trim((string)($item['nome'] ?? ''))),
            'azienda' => strtolower(trim((string)($item['azienda'] ?? ''))),
            'stripe_id' => strtolower(trim((string)($item['stripe_id'] ?? ''))),
            'motivo' => trim((string)($item['motivo'] ?? 'Esclusione da lista file')),
        ];

        if ($row['cliente_id'] <= 0 && $row['email'] === '' && $row['piva'] === '' && $row['nome'] === '' && $row['azienda'] === '' && $row['stripe_id'] === '') {
            continue;
        }

        $rows[] = $row;
    }

    $cache = $rows;
    return $cache;
}

function stripe_invoice_exclusion_match(array $fattura): ?array
{
    $clienteId = (int)($fattura['cliente_id'] ?? 0);
    $email = strtolower(trim((string)($fattura['email'] ?? '')));
    $piva = stripe_normalize_vat_number((string)($fattura['piva'] ?? ''));
    $nome = strtolower(trim((string)($fattura['nome'] ?? '')));
    $azienda = strtolower(trim((string)($fattura['azienda'] ?? '')));
    $stripeId = strtolower(trim((string)($fattura['stripe_payment_intent_id'] ?? '')));

    foreach (stripe_exclusion_list_load() as $rule) {
        if ((int)$rule['cliente_id'] > 0 && $clienteId > 0 && (int)$rule['cliente_id'] === $clienteId) {
            return $rule;
        }
        if ($rule['email'] !== '' && $email !== '' && $rule['email'] === $email) {
            return $rule;
        }
        if ($rule['piva'] !== '' && $piva !== '' && $rule['piva'] === $piva) {
            return $rule;
        }
        if ($rule['stripe_id'] !== '' && $stripeId !== '' && $rule['stripe_id'] === $stripeId) {
            return $rule;
        }
        if ($rule['nome'] !== '' && $nome !== '' && $rule['nome'] === $nome) {
            return $rule;
        }
        if ($rule['azienda'] !== '' && $azienda !== '' && $rule['azienda'] === $azienda) {
            return $rule;
        }
    }

    return null;
}

function stripe_compose_address(array $address): string
{
    $parts = [];
    foreach (['line1', 'line2', 'postal_code', 'city', 'state', 'country'] as $k) {
        $v = trim((string)($address[$k] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(', ', $parts);
}

function stripe_extract_cliente_data($customer, $charge): array
{
    $customerArr = stripe_to_array($customer);
    $chargeArr = stripe_to_array($charge);
    $billing = stripe_to_array($chargeArr['billing_details'] ?? []);

    $customerMeta = stripe_to_array($customerArr['metadata'] ?? []);
    $chargeMeta = stripe_to_array($chargeArr['metadata'] ?? []);
    $customerAddress = stripe_to_array($customerArr['address'] ?? []);
    $billingAddress = stripe_to_array($billing['address'] ?? []);

    $email = stripe_first_non_empty([
        $customerArr['email'] ?? '',
        $billing['email'] ?? '',
    ]);
    $nome = stripe_first_non_empty([
        $customerArr['name'] ?? '',
        $billing['name'] ?? '',
    ]);
    $telefono = stripe_first_non_empty([
        $customerArr['phone'] ?? '',
        $billing['phone'] ?? '',
    ]);
    $azienda = stripe_first_non_empty([
        stripe_metadata_pick($customerMeta, ['azienda', 'company', 'company_name', 'ragione_sociale']),
        stripe_metadata_pick($chargeMeta, ['azienda', 'company', 'company_name', 'ragione_sociale']),
    ]);
    $piva = stripe_first_non_empty([
        stripe_metadata_pick($customerMeta, ['piva', 'partita_iva', 'vat_number', 'tax_id', 'vat']),
        stripe_metadata_pick($chargeMeta, ['piva', 'partita_iva', 'vat_number', 'tax_id', 'vat']),
    ]);
    $codiceSdi = stripe_first_non_empty([
        stripe_metadata_pick($customerMeta, ['codice_sdi', 'sdi', 'recipient_code', 'codice_destinatario']),
        stripe_metadata_pick($chargeMeta, ['codice_sdi', 'sdi', 'recipient_code', 'codice_destinatario']),
    ]);
    $pec = stripe_first_non_empty([
        stripe_metadata_pick($customerMeta, ['pec', 'certified_email']),
        stripe_metadata_pick($chargeMeta, ['pec', 'certified_email']),
    ]);
    $indirizzo = stripe_first_non_empty([
        stripe_compose_address($customerAddress),
        stripe_compose_address($billingAddress),
    ]);

    return [
        'nome' => $nome,
        'email' => strtolower(trim($email)),
        'telefono' => $telefono,
        'azienda' => $azienda,
        'indirizzo' => $indirizzo,
        'piva' => stripe_normalize_vat_number($piva),
        'codice_sdi' => strtoupper(trim($codiceSdi)),
        'pec' => strtolower(trim($pec)),
    ];
}

function stripe_update_cliente_if_empty(PDO $pdo, int $clienteId, array $data): void
{
    if ($clienteId <= 0) {
        return;
    }

    $map = [
        'email' => (string)($data['email'] ?? ''),
        'telefono' => (string)($data['telefono'] ?? ''),
        'azienda' => (string)($data['azienda'] ?? ''),
        'indirizzo' => (string)($data['indirizzo'] ?? ''),
        'piva' => (string)($data['piva'] ?? ''),
        'codice_sdi' => (string)($data['codice_sdi'] ?? ''),
        'pec' => (string)($data['pec'] ?? ''),
    ];

    $set = [];
    $params = [':id' => $clienteId];
    foreach ($map as $column => $value) {
        $value = trim($value);
        if ($value === '') {
            continue;
        }
        $ph = ':' . $column;
        $set[] = "`$column` = COALESCE(NULLIF(`$column`, ''), $ph)";
        $params[$ph] = $value;
    }

    if (empty($set)) {
        return;
    }

    try {
        $sql = 'UPDATE clienti SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
    }
}

function stripe_upsert_customer_mapping(PDO $pdo, int $clienteId, string $stripeCustomerId, array $clienteData, int $chargeAmountCents, int $chargeCreatedTs, string $paymentMethod): void
{
    if ($clienteId <= 0 || $stripeCustomerId === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO stripe_sync (
                cliente_id, stripe_customer_id, stripe_email, stripe_name, stripe_balance, stripe_country,
                last_charge_amount, last_charge_date, payment_method, total_charges, total_amount_charged, synced_at
            )
            VALUES (
                :cliente_id, :stripe_customer_id, :stripe_email, :stripe_name, 0, NULL,
                :last_charge_amount, :last_charge_date, :payment_method, 0, 0, NOW()
            )
            ON DUPLICATE KEY UPDATE
                stripe_customer_id = VALUES(stripe_customer_id),
                stripe_email = VALUES(stripe_email),
                stripe_name = VALUES(stripe_name),
                last_charge_amount = VALUES(last_charge_amount),
                last_charge_date = VALUES(last_charge_date),
                payment_method = VALUES(payment_method),
                synced_at = NOW()"
        );
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':stripe_customer_id' => $stripeCustomerId,
            ':stripe_email' => (string)($clienteData['email'] ?? ''),
            ':stripe_name' => (string)($clienteData['nome'] ?? $clienteData['azienda'] ?? ''),
            ':last_charge_amount' => $chargeAmountCents,
            ':last_charge_date' => date('Y-m-d H:i:s', $chargeCreatedTs > 0 ? $chargeCreatedTs : time()),
            ':payment_method' => $paymentMethod,
        ]);
        tdg_upsert_stripe_customer_link(
            $pdo,
            $clienteId,
            $stripeCustomerId,
            (string)($clienteData['email'] ?? ''),
            (string)($clienteData['nome'] ?? $clienteData['azienda'] ?? ''),
            'mod_pagamenti_sync'
        );
    } catch (Throwable $e) {
        try {
            $upd = $pdo->prepare(
                "UPDATE stripe_sync
                 SET cliente_id = :cliente_id,
                     stripe_email = COALESCE(NULLIF(stripe_email, ''), :stripe_email),
                     stripe_name = COALESCE(NULLIF(stripe_name, ''), :stripe_name),
                     synced_at = NOW()
                 WHERE stripe_customer_id = :stripe_customer_id"
            );
            $upd->execute([
                ':cliente_id' => $clienteId,
                ':stripe_customer_id' => $stripeCustomerId,
                ':stripe_email' => (string)($clienteData['email'] ?? ''),
                ':stripe_name' => (string)($clienteData['nome'] ?? $clienteData['azienda'] ?? ''),
            ]);
            tdg_upsert_stripe_customer_link(
                $pdo,
                $clienteId,
                $stripeCustomerId,
                (string)($clienteData['email'] ?? ''),
                (string)($clienteData['nome'] ?? $clienteData['azienda'] ?? ''),
                'mod_pagamenti_sync'
            );
        } catch (Throwable $ignore) {
        }
    }
}

function stripe_try_auto_send_invoice(PDO $pdo, int $fatturaId, bool $autoSendEnabled): void
{
    if (!$autoSendEnabled || $fatturaId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1');
    $stmt->execute([':id' => $fatturaId]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        return;
    }

    if ((string)($fattura['document_type'] ?? '') !== 'invoice') {
        return;
    }

    $exclusionRule = stripe_invoice_exclusion_match($fattura);
    if (is_array($exclusionRule)) {
        $reason = trim((string)($exclusionRule['motivo'] ?? 'Esclusione da lista file'));
        stripe_set_invoice_mail_pending($pdo, $fatturaId, 'Pagamento Stripe registrato: invio automatico non eseguito (cliente in lista esclusioni: ' . $reason . ').');
        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'stripe_auto_send_sync', 'ok', 'Invio automatico saltato: cliente in lista esclusioni.');
        }
        stripe_auto_send_mark('skipped_excluded', $fatturaId);
        return;
    }

    $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
    if (in_array($eiStatus, ['sent', 'processing', 'accepted'], true)) {
        stripe_auto_send_mark('already_sent', $fatturaId);
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
        stripe_set_invoice_mail_pending($pdo, $fatturaId, 'Pagamento Stripe registrato: invio automatico non eseguito (anagrafica incompleta: ' . implode(', ', $missing) . ').');
        stripe_auto_send_mark('skipped_incomplete', $fatturaId);
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
        stripe_set_invoice_mail_pending($pdo, $fatturaId, 'Pagamento Stripe registrato: invio automatico non eseguito (company_id FIC mancante).');
        stripe_auto_send_mark('skipped_config', $fatturaId);
        return;
    }

    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
    $righeStmt->execute([':id' => $fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        stripe_auto_send_mark('attempted', $fatturaId);
        fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'auto_stripe_sync');

        $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id');
        $upd->execute([
            ':status' => ((string)($fattura['mail_status'] ?? '') !== '' ? (string)$fattura['mail_status'] : 'pending'),
            ':err' => null,
            ':id' => $fatturaId,
        ]);

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'stripe_auto_send_sync', 'ok', 'Invio automatico e-fattura (SDI) da sincronizzazione Stripe completato.');
        }
        stripe_auto_send_mark('sent', $fatturaId);
    } catch (Throwable $e) {
        try {
            $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id');
            $upd->execute([
                ':status' => 'error',
                ':err' => substr((string)$e->getMessage(), 0, 1000),
                ':id' => $fatturaId,
            ]);
        } catch (Throwable $ignore) {
        }

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'stripe_auto_send_sync', 'error', 'Errore invio automatico da sync Stripe: ' . substr((string)$e->getMessage(), 0, 180));
        }
        stripe_auto_send_mark('errors', $fatturaId);
    }
}

function stripe_generate_invoice_number(PDO $pdo, int $timestamp): string
{
    $dayKey = date('Ymd', $timestamp);
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

function stripe_find_or_create_cliente(PDO $pdo, array $clienteData, string $note, int $preferredClienteId = 0): int
{
    $email = strtolower(trim((string)($clienteData['email'] ?? '')));
    $nome = trim((string)($clienteData['nome'] ?? ''));
    $telefono = trim((string)($clienteData['telefono'] ?? ''));
    $azienda = trim((string)($clienteData['azienda'] ?? ''));
    $indirizzo = trim((string)($clienteData['indirizzo'] ?? ''));
    $piva = trim((string)($clienteData['piva'] ?? ''));
    $codiceSdi = trim((string)($clienteData['codice_sdi'] ?? ''));
    $pec = strtolower(trim((string)($clienteData['pec'] ?? '')));

    if ($preferredClienteId > 0) {
        stripe_update_cliente_if_empty($pdo, $preferredClienteId, $clienteData);
        return $preferredClienteId;
    }

    if ($email !== '') {
        $stmtCheck = $pdo->prepare('SELECT id FROM clienti WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $stmtCheck->execute([$email]);
        $existingCliente = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if ($existingCliente) {
            $clienteId = (int)$existingCliente['id'];
            stripe_update_cliente_if_empty($pdo, $clienteId, $clienteData);
            return $clienteId;
        }
    }

    if ($nome !== '' || $azienda !== '') {
        $nameProbe = $nome !== '' ? $nome : $azienda;
        $stmtByName = $pdo->prepare('SELECT id FROM clienti WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) OR LOWER(TRIM(azienda)) = LOWER(TRIM(?)) LIMIT 1');
        $stmtByName->execute([$nameProbe, $nameProbe]);
        $existingByName = $stmtByName->fetch(PDO::FETCH_ASSOC);
        if ($existingByName) {
            $clienteId = (int)$existingByName['id'];
            stripe_update_cliente_if_empty($pdo, $clienteId, $clienteData);
            return $clienteId;
        }
    }

    $nomeInsert = $nome !== '' ? $nome : ($azienda !== '' ? $azienda : 'Cliente Stripe');
    $emailInsert = $email !== '' ? $email : null;
    $telInsert = $telefono !== '' ? $telefono : null;
    $aziendaInsert = $azienda !== '' ? $azienda : null;
    $indirizzoInsert = $indirizzo !== '' ? $indirizzo : null;
    $pivaInsert = $piva !== '' ? $piva : null;
    $codiceSdiInsert = $codiceSdi !== '' ? $codiceSdi : null;
    $pecInsert = $pec !== '' ? $pec : null;

    try {
        $stmtInsertCliente = $pdo->prepare('
            INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note, creato_il)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmtInsertCliente->execute([
            $nomeInsert,
            $emailInsert,
            $telInsert,
            $aziendaInsert,
            $indirizzoInsert,
            $pivaInsert,
            $codiceSdiInsert,
            $pecInsert,
            $note,
        ]);
    } catch (Throwable $e) {
        $stmtInsertCliente = $pdo->prepare('
            INSERT INTO clienti (nome, email, telefono, note, creato_il)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmtInsertCliente->execute([
            $nomeInsert,
            $emailInsert,
            $telInsert,
            $note,
        ]);
    }

    return (int)$pdo->lastInsertId();
}

function stripe_find_cliente_from_sync(PDO $pdo, string $stripeCustomerId): int
{
    if ($stripeCustomerId === '') {
        return 0;
    }

    try {
        return tdg_find_cliente_id_by_stripe_customer($pdo, $stripeCustomerId);
    } catch (Throwable $e) {
        return 0;
    }
}

function stripe_get_table_columns(PDO $pdo, string $table, bool $forceRefresh = false): array
{
    static $cache = [];

    if (!$forceRefresh && isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function stripe_ensure_fatture_compat_columns(PDO $pdo): array
{
    static $checked = false;

    if (!$checked) {
        $checked = true;
        try {
            $columns = stripe_get_table_columns($pdo, 'fatture', true);
            $needed = [
                'document_type' => "VARCHAR(30) NOT NULL DEFAULT 'invoice'",
                'data_pagamento' => 'DATETIME NULL',
                'metodo_pagamento' => 'VARCHAR(50) NULL',
                'stripe_payment_intent_id' => 'VARCHAR(255) NULL',
                'creato_il' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ];

            foreach ($needed as $col => $def) {
                if (!isset($columns[$col])) {
                    try {
                        $pdo->exec('ALTER TABLE fatture ADD COLUMN `' . str_replace('`', '``', $col) . '` ' . $def);
                    } catch (Throwable $e) {
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    try {
        return stripe_get_table_columns($pdo, 'fatture', true);
    } catch (Throwable $e) {
        return [];
    }
}

function stripe_ensure_invoice_for_payment(
    PDO $pdo,
    string $stripeId,
    int $clienteId,
    string $stato,
    string $metodo,
    float $totale,
    int $createdTs,
    string $descrizione,
    bool $autoSendEnabled
): int {
    if ($stato !== 'succeeded' || $clienteId <= 0) {
        return 0;
    }

    $fattureColumns = stripe_ensure_fatture_compat_columns($pdo);
    $hasStripePaymentIntent = isset($fattureColumns['stripe_payment_intent_id']);
    $hasDocumentType = isset($fattureColumns['document_type']);

    if (!$hasStripePaymentIntent) {
        return 0;
    }

    $checkInvoiceSql = 'SELECT id FROM fatture WHERE stripe_payment_intent_id = ?';
    if ($hasDocumentType) {
        $checkInvoiceSql .= ' AND document_type = "invoice"';
    }
    $checkInvoiceSql .= ' LIMIT 1';
    $checkInvoiceStmt = $pdo->prepare($checkInvoiceSql);
    $checkInvoiceStmt->execute([$stripeId]);
    $existingInvoiceId = (int)($checkInvoiceStmt->fetchColumn() ?: 0);
    if ($existingInvoiceId > 0) {
        stripe_try_auto_send_invoice($pdo, $existingInvoiceId, $autoSendEnabled);
        return -1;
    }

    if ($hasDocumentType) {
        $legacyOrderStmt = $pdo->prepare('
            SELECT id FROM fatture
            WHERE stripe_payment_intent_id = ?
              AND document_type = "order"
            LIMIT 1
        ');
        $legacyOrderStmt->execute([$stripeId]);
        $legacyOrderId = (int)($legacyOrderStmt->fetchColumn() ?: 0);
        if ($legacyOrderId > 0) {
            $updLegacy = $pdo->prepare('
                UPDATE fatture
                SET document_type = "invoice",
                    note = CONCAT(IFNULL(note, ""), "\n\nConvertita automaticamente da ordine Stripe a fattura.")
                WHERE id = :id
            ');
            $updLegacy->execute([':id' => $legacyOrderId]);
            stripe_try_auto_send_invoice($pdo, $legacyOrderId, $autoSendEnabled);
            return -2;
        }
    }

    $dataFattura = date('Y-m-d', $createdTs > 0 ? $createdTs : time());
    $numeroFattura = stripe_generate_invoice_number($pdo, $createdTs > 0 ? $createdTs : time());

    $paymentDateTime = date('Y-m-d H:i:s', $createdTs > 0 ? $createdTs : time());
    $insertData = [
        'cliente_id' => $clienteId,
        'numero' => $numeroFattura,
        'data_emissione' => $dataFattura,
        'stato' => 'pagata',
        'totale' => $totale,
        'note' => "Fattura creata automaticamente da sincronizzazione Stripe\n\n" . $descrizione,
        'stripe_payment_intent_id' => $stripeId,
    ];

    if ($hasDocumentType) {
        $insertData['document_type'] = 'invoice';
    }
    if (isset($fattureColumns['metodo_pagamento'])) {
        $insertData['metodo_pagamento'] = 'Stripe - ' . $metodo;
    }
    if (isset($fattureColumns['data_pagamento'])) {
        $insertData['data_pagamento'] = $paymentDateTime;
    }
    if (isset($fattureColumns['creato_il'])) {
        $insertData['creato_il'] = $paymentDateTime;
    }

    $insertCols = array_keys($insertData);
    $insertSql = 'INSERT INTO fatture (' . implode(', ', $insertCols) . ') VALUES (:' . implode(', :', $insertCols) . ')';
    $insertInvoiceStmt = $pdo->prepare($insertSql);
    $insertInvoiceStmt->execute($insertData);

    $fatturaId = (int)$pdo->lastInsertId();
    if ($fatturaId <= 0) {
        return 0;
    }

    $insertRigaStmt = $pdo->prepare('
        INSERT INTO fatture_righe (
            fattura_id, descrizione, quantita,
            prezzo_unitario, iva, totale_riga
        ) VALUES (?, ?, ?, ?, ?, ?)
    ');
    $insertRigaStmt->execute([
        $fatturaId,
        $descrizione,
        1,
        $totale,
        0,
        $totale,
    ]);

    stripe_try_auto_send_invoice($pdo, $fatturaId, $autoSendEnabled);
    return $fatturaId;
}

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_scheduler_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sync_key VARCHAR(100) NOT NULL UNIQUE,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
        last_run_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
}

if ($isScheduled) {
    try {
        $cfgStmt = $pdo->prepare('SELECT enabled, interval_minutes, last_run_at FROM sync_scheduler_settings WHERE sync_key = :sync_key LIMIT 1');
        $cfgStmt->execute([':sync_key' => 'pagamenti_stripe']);
        $schedule = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        $enabled = (int)($schedule['enabled'] ?? 0) === 1;
        $interval = max(1, (int)($schedule['interval_minutes'] ?? 60));
        $lastRunAt = $schedule['last_run_at'] ?? null;

        if (!$enabled) {
            echo json_encode(['ok' => true, 'skipped' => true, 'message' => 'Sincronizzazione automatica disattivata']);
            exit;
        }

        if (!empty($lastRunAt)) {
            $nextTs = strtotime((string)$lastRunAt . ' +' . $interval . ' minutes');
            if ($nextTs !== false && $nextTs > time()) {
                echo json_encode([
                    'ok' => true,
                    'skipped' => true,
                    'message' => 'Sincronizzazione non necessaria in questo momento',
                    'next_run_at' => date('Y-m-d H:i:s', $nextTs),
                ]);
                exit;
            }
        }
    } catch (Throwable $e) {
    }
}

try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pagamenti (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stripe_id VARCHAR(255) UNIQUE NOT NULL,
            cliente_id INT,
            importo INT NOT NULL COMMENT "in centesimi",
            valuta VARCHAR(3) DEFAULT "EUR",
            stato VARCHAR(50) DEFAULT "pending",
            metodo VARCHAR(100),
            descrizione TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY (cliente_id),
            FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
        )
    ');
} catch (Exception $e) {
}

try {
    $autoSendEnabled = stripe_invoice_auto_send_enabled($pdo);

    \Stripe\Stripe::setApiKey($stripe_secret_key);

    $count = 0;
    $createdInvoices = 0;
    $skippedNoCliente = 0;
    $skippedNotSucceeded = 0;
    $processed_ids = [];
    $existingInvoiceHits = 0;
    $legacyConverted = 0;
    $ensureErrors = 0;
    $ensureErrorSample = '';
    $skippedBeforeDate = 0;

    // Pre-carica tutti gli stripe_id già in DB per skip veloce
    $existingStripeIds = [];
    try {
        $existStmt = $pdo->query('SELECT stripe_id FROM pagamenti');
        while ($r = $existStmt->fetch(PDO::FETCH_COLUMN)) {
            $existingStripeIds[$r] = true;
        }
    } catch (Throwable $e) {}

    $forceFullSync = !$isScheduled || (isset($_GET['full']) && (string)$_GET['full'] === '1');
    $syncFromTs = 0;
    if ($forceFullSync) {
        $syncFromTs = max(0, time() - (365 * 86400));
    } else {
        try {
            $lastPaymentStmt = $pdo->query('SELECT MAX(created_at) FROM pagamenti');
            $lastPaymentAt = $lastPaymentStmt ? $lastPaymentStmt->fetchColumn() : null;
            $lastPaymentTs = $lastPaymentAt ? strtotime((string)$lastPaymentAt) : 0;
            $syncFromTs = $lastPaymentTs > 0 ? max(0, $lastPaymentTs - (14 * 86400)) : 0;
        } catch (Throwable $e) {
            $syncFromTs = 0;
        }
    }

    $chargeParams = ['limit' => 100, 'expand' => ['data.customer']];
    if ($syncFromTs > 0) {
        $chargeParams['created'] = ['gte' => $syncFromTs];
    }
    $chargesList = \Stripe\Charge::all($chargeParams);
    $processedChargesCounter = 0;
    $skippedExisting = 0;

    foreach ($chargesList->autoPagingIterator() as $charge) {
        $processedChargesCounter++;

        $stripe_id = $charge->id;
        if (in_array($stripe_id, $processed_ids, true)) {
            continue;
        }
        $processed_ids[] = $stripe_id;

        $stato = (string)$charge->status;
        $metodo = 'card';
        if ($charge->payment_method_details) {
            $metodo = $charge->payment_method_details->type ?? 'card';
        }
        $chargeCreatedTs = (int)($charge->created ?? 0);
        $chargeCreatedAt = date('Y-m-d H:i:s', $chargeCreatedTs > 0 ? $chargeCreatedTs : time());
        $chargeDescription = (string)($charge->description ?? 'Pagamento importato da Stripe');

        // Skip veloce se già nel DB (evita chiamate API inutili)
        if (isset($existingStripeIds[$stripe_id])) {
            // Ma verifica comunque se serve creare fattura
            $skippedExisting++;
            try {
                $existPayStmt = $pdo->prepare('SELECT cliente_id FROM pagamenti WHERE stripe_id = ? LIMIT 1');
                $existPayStmt->execute([$stripe_id]);
                $existPay = $existPayStmt->fetch(PDO::FETCH_ASSOC);
                $existClienteId = (int)($existPay['cliente_id'] ?? 0);

                $updExistingStmt = $pdo->prepare('
                    UPDATE pagamenti
                    SET importo = ?, valuta = ?, stato = ?, metodo = ?, descrizione = ?, created_at = ?
                    WHERE stripe_id = ?
                ');
                $updExistingStmt->execute([
                    (int)$charge->amount,
                    strtoupper((string)$charge->currency),
                    $stato,
                    $metodo,
                    $chargeDescription,
                    $chargeCreatedAt,
                    $stripe_id,
                ]);

                if ($existClienteId > 0 && $stato === 'succeeded') {
                    if ($invoiceFromTs > 0 && $chargeCreatedTs > 0 && $chargeCreatedTs < $invoiceFromTs) {
                        $skippedBeforeDate++;
                        continue;
                    }
                    try {
                        $newInvoiceId = stripe_ensure_invoice_for_payment(
                            $pdo, (string)$stripe_id, $existClienteId, 'succeeded',
                            (string)$metodo, ((float)$charge->amount) / 100, $chargeCreatedTs,
                            $chargeDescription, $autoSendEnabled
                        );
                        if ($newInvoiceId > 0) $createdInvoices++;
                        elseif ($newInvoiceId === -1) $existingInvoiceHits++;
                        elseif ($newInvoiceId === -2) $legacyConverted++;
                    } catch (Throwable $e) {
                        $ensureErrors++;
                        if ($ensureErrorSample === '') $ensureErrorSample = substr($e->getMessage(), 0, 180);
                    }
                }
            } catch (Throwable $e) {}
            continue;
        }

        $cliente_id = null;
        $stripeCustomerId = '';
        if ($charge->customer) {
            $stripeCustomerId = is_string($charge->customer)
                ? (string)$charge->customer
                : (string)($charge->customer->id ?? '');

            if ($stripeCustomerId !== '') {
                $cliente_id = stripe_find_cliente_from_sync($pdo, $stripeCustomerId);
            }

            try {
                $customer = is_string($charge->customer)
                    ? \Stripe\Customer::retrieve($charge->customer)
                    : $charge->customer;
                $clienteData = stripe_extract_cliente_data($customer, $charge);
                $cliente_id = stripe_find_or_create_cliente(
                    $pdo,
                    $clienteData,
                    'Cliente importato da Stripe (Customer ID: ' . ($customer->id ?? $stripeCustomerId) . ')',
                    (int)$cliente_id
                );

                stripe_upsert_customer_mapping(
                    $pdo,
                    (int)$cliente_id,
                    (string)$stripeCustomerId,
                    $clienteData,
                    (int)$charge->amount,
                    (int)$charge->created,
                    (string)$metodo
                );
            } catch (Throwable $e) {
                error_log('Errore gestione cliente Stripe: ' . $e->getMessage());
            }
        }

        if (!$cliente_id) {
            try {
                $clienteData = stripe_extract_cliente_data(null, $charge);
                if (!empty($clienteData['email']) || !empty($clienteData['nome']) || !empty($clienteData['azienda'])) {
                    $cliente_id = stripe_find_or_create_cliente(
                        $pdo,
                        $clienteData,
                        'Cliente importato da Stripe (billing details su charge ' . $stripe_id . ')'
                    );
                }
            } catch (Throwable $e) {
                error_log('Errore gestione billing details Stripe: ' . $e->getMessage());
            }
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO pagamenti
                (stripe_id, cliente_id, importo, valuta, stato, metodo, descrizione, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    cliente_id = COALESCE(NULLIF(VALUES(cliente_id), 0), cliente_id),
                    importo = VALUES(importo),
                    valuta = VALUES(valuta),
                    stato = VALUES(stato),
                    metodo = VALUES(metodo),
                    descrizione = VALUES(descrizione),
                    created_at = VALUES(created_at)
            ');
            $stmt->execute([
                $stripe_id,
                $cliente_id,
                (int)$charge->amount,
                strtoupper((string)$charge->currency),
                $stato,
                $metodo,
                $chargeDescription,
                $chargeCreatedAt,
            ]);

            if ($stmt->rowCount() > 0) {
                $count++;
            } elseif (!$cliente_id) {
                $payStmt = $pdo->prepare('SELECT cliente_id FROM pagamenti WHERE stripe_id = ? LIMIT 1');
                $payStmt->execute([$stripe_id]);
                $existingPayment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingPayment && !empty($existingPayment['cliente_id'])) {
                    $cliente_id = (int)$existingPayment['cliente_id'];
                }
            } else {
                $updPayStmt = $pdo->prepare('UPDATE pagamenti SET cliente_id = ? WHERE stripe_id = ? AND (cliente_id IS NULL OR cliente_id = 0)');
                $updPayStmt->execute([(int)$cliente_id, (string)$stripe_id]);
            }

            try {
                if ((string)$stato !== 'succeeded') {
                    $skippedNotSucceeded++;
                } elseif ((int)$cliente_id <= 0) {
                    $skippedNoCliente++;
                }

                $chargeCreatedTs = (int)($charge->created ?? 0);
                if ($invoiceFromTs > 0 && $chargeCreatedTs > 0 && $chargeCreatedTs < $invoiceFromTs) {
                    $skippedBeforeDate++;
                    continue;
                }

                // --- PATCH: crea fattura FIC, invia SDI, aggiorna tracking pagamenti ---
                $newInvoiceId = stripe_ensure_invoice_for_payment(
                    $pdo,
                    (string)$stripe_id,
                    (int)$cliente_id,
                    (string)$stato,
                    (string)$metodo,
                    ((float)$charge->amount) / 100,
                    (int)$charge->created,
                    (string)($charge->description ?? 'Fattura da pagamento Stripe'),
                    $autoSendEnabled
                );
                if ($newInvoiceId > 0) {
                    $createdInvoices++;
                } elseif ($newInvoiceId === -1) {
                    $existingInvoiceHits++;
                } elseif ($newInvoiceId === -2) {
                    $legacyConverted++;
                }
            } catch (Throwable $orderEx) {
                $ensureErrors++;
                if ($ensureErrorSample === '') {
                    $ensureErrorSample = substr((string)$orderEx->getMessage(), 0, 180);
                }
                error_log('Errore creazione fattura automatica: ' . $orderEx->getMessage());
            }
        } catch (PDOException $e) {
            error_log('Duplicato o errore: ' . $stripe_id . ' - ' . $e->getMessage());
        }
    }

    $backfillProcessed = 0;
    try {
        $clientByEmail = [];
        $clientNames = [];
        try {
            $clientsStmt = $pdo->query('SELECT id, nome, azienda, email FROM clienti');
            $clientsList = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($clientsList as $clientRow) {
                $emailKey = strtolower(trim((string)($clientRow['email'] ?? '')));
                if ($emailKey !== '') {
                    $clientByEmail[$emailKey] = (int)$clientRow['id'];
                }

                $nomeKey = strtolower(trim((string)($clientRow['nome'] ?? '')));
                if ($nomeKey !== '') {
                    $clientNames[] = ['id' => (int)$clientRow['id'], 'label' => $nomeKey];
                }

                $aziendaKey = strtolower(trim((string)($clientRow['azienda'] ?? '')));
                if ($aziendaKey !== '') {
                    $clientNames[] = ['id' => (int)$clientRow['id'], 'label' => $aziendaKey];
                }
            }
        } catch (Throwable $e) {
        }

        $backfillStmt = $pdo->query('
            SELECT stripe_id, cliente_id, importo, stato, metodo, descrizione, created_at
            FROM pagamenti
            WHERE stato = "succeeded"
            ORDER BY created_at DESC
            LIMIT 500
        ');
        $backfillRows = $backfillStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($backfillRows as $row) {
            $backfillProcessed++;
            try {
                $rowCreatedTs = strtotime((string)($row['created_at'] ?? '')) ?: 0;
                if ($invoiceFromTs > 0 && $rowCreatedTs > 0 && $rowCreatedTs < $invoiceFromTs) {
                    $skippedBeforeDate++;
                    continue;
                }

                $clienteId = (int)($row['cliente_id'] ?? 0);
                if ($clienteId <= 0) {
                    $desc = (string)($row['descrizione'] ?? '');
                    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $desc, $matches)) {
                        $emailKey = strtolower($matches[0]);
                        if (isset($clientByEmail[$emailKey])) {
                            $clienteId = (int)$clientByEmail[$emailKey];
                        }
                    }

                    if ($clienteId <= 0 && $desc !== '') {
                        $descLower = strtolower($desc);
                        $matchId = 0;
                        $multipleMatch = false;
                        foreach ($clientNames as $candidate) {
                            if ($candidate['label'] !== '' && strpos($descLower, $candidate['label']) !== false) {
                                if ($matchId > 0 && $matchId !== (int)$candidate['id']) {
                                    $multipleMatch = true;
                                    break;
                                }
                                $matchId = (int)$candidate['id'];
                            }
                        }
                        if (!$multipleMatch && $matchId > 0) {
                            $clienteId = $matchId;
                        }
                    }

                    if ($clienteId > 0) {
                        $updPayStmt = $pdo->prepare('UPDATE pagamenti SET cliente_id = ? WHERE stripe_id = ? AND (cliente_id IS NULL OR cliente_id = 0)');
                        $updPayStmt->execute([$clienteId, (string)$row['stripe_id']]);
                    }
                }

                $newInvoiceId = stripe_ensure_invoice_for_payment(
                    $pdo,
                    (string)$row['stripe_id'],
                    (int)$clienteId,
                    (string)$row['stato'],
                    (string)($row['metodo'] ?? 'card'),
                    ((float)$row['importo']) / 100,
                    strtotime((string)$row['created_at']) ?: time(),
                    (string)($row['descrizione'] ?? 'Fattura da pagamento Stripe'),
                    $autoSendEnabled
                );
                if ($newInvoiceId > 0) {
                    $createdInvoices++;
                } elseif ($newInvoiceId === -1) {
                    $existingInvoiceHits++;
                } elseif ($newInvoiceId === -2) {
                    $legacyConverted++;
                }
            } catch (Throwable $bfEx) {
                $ensureErrors++;
                if ($ensureErrorSample === '') {
                    $ensureErrorSample = substr((string)$bfEx->getMessage(), 0, 180);
                }
                error_log('Errore backfill fattura Stripe: ' . $bfEx->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('Errore backfill pagamenti Stripe: ' . $e->getMessage());
    }

    if ($isScheduled) {
        try {
            $updSched = $pdo->prepare('UPDATE sync_scheduler_settings SET last_run_at = NOW() WHERE sync_key = :sync_key');
            $updSched->execute([':sync_key' => 'pagamenti_stripe']);
        } catch (Throwable $e) {
        }
    }

    $autoSendStats = stripe_auto_send_stats_snapshot();
    $pdfDeliveryStats = stripe_collect_pdf_delivery_stats($pdo, $autoSendStats['sent_invoice_ids'] ?? []);

    if ($count > 0 || $createdInvoices > 0 || $backfillProcessed > 0 || $legacyConverted > 0) {
        tdg_dashboard_cache_clear();
    }

    http_response_code(200);
    // Pulisci eventuale output accidentale
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'ok' => true,
        'count' => $count,
        'created_invoices' => $createdInvoices,
        'backfill_processed' => $backfillProcessed,
        'charges_processed' => $processedChargesCounter,
        'sync_from' => $syncFromTs > 0 ? date('Y-m-d H:i:s', $syncFromTs) : 'all',
        'full_sync' => $forceFullSync,
        'existing_invoices' => $existingInvoiceHits,
        'legacy_converted' => $legacyConverted,
        'ensure_errors' => $ensureErrors,
        'ensure_error_sample' => $ensureErrorSample,
        'invoice_from_date' => $invoiceFromDateApplied,
        'skipped_before_date' => $skippedBeforeDate,
        'skipped_no_cliente' => $skippedNoCliente,
        'skipped_not_succeeded' => $skippedNotSucceeded,
        'auto_send' => $autoSendStats,
        'pdf_delivery' => $pdfDeliveryStats,
        'message' => 'Sincronizzazione completata con successo',
        'imported' => $processed_ids,
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'ok' => false,
        'error' => 'Errore Stripe API: ' . $e->getMessage(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
