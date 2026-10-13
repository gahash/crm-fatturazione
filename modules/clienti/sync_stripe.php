<?php
/**
 * Sincronizza i Customer Stripe con il database locale.
 *
 * Puo essere chiamato:
 * 1. Manualmente via browser (sync_stripe.php?manual=1)
 * 2. Via cron job (sync_stripe.php?cron=1 con secret key)
 * 3. Via AJAX (sync_stripe.php?api=1)
 */

require_once __DIR__ . '/../../config/env.php';
$cronSecretEnv = (string)(getenv('CRON_SECRET') ?: '');
$isCron = isset($_GET['cron']) && $cronSecretEnv !== '' && hash_equals($cronSecretEnv, (string)$_GET['cron']);
$isManual = isset($_GET['manual']) && $isCron === false;
$isApi = isset($_GET['api']);

require __DIR__ . '/../../auth.php';
if (!$isCron) {
    require_login();
}
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/stripe_customer_links.php';

if (!$isCron && !$isManual && !$isApi) {
    http_response_code(403);
    echo 'Accesso non autorizzato';
    exit;
}

if (!$isCron) {
    $user = current_user();
    if (!$user || ($user['ruolo'] ?? '') !== 'admin') {
        http_response_code(403);
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Solo admin']);
        } else {
            echo 'Solo admin puo sincronizzare';
        }
        exit;
    }
}

function tdg_stripe_sync_normalize_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/[^a-z0-9\s\-\._]/', '', $name);
    return trim((string)$name);
}

function tdg_stripe_sync_metadata_array($metadata): array
{
    if (is_object($metadata)) {
        $decoded = json_decode(json_encode($metadata), true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($metadata) ? $metadata : [];
}

function tdg_stripe_sync_pick_meta(array $metadata, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($metadata[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function tdg_stripe_sync_address_text($address): string
{
    if (!$address) {
        return '';
    }

    $data = is_object($address) ? json_decode(json_encode($address), true) : (array)$address;
    if (!is_array($data)) {
        return '';
    }

    $parts = [];
    foreach (['line1', 'line2', 'postal_code', 'city', 'state', 'country'] as $key) {
        $value = trim((string)($data[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(', ', $parts);
}

function tdg_stripe_sync_customer_country($customer): ?string
{
    $address = $customer->address ?? null;
    if (!$address) {
        return null;
    }

    $data = is_object($address) ? json_decode(json_encode($address), true) : (array)$address;
    $country = strtoupper(trim((string)($data['country'] ?? '')));
    return $country !== '' ? $country : null;
}

function tdg_stripe_sync_customer_data($customer): array
{
    $metadata = tdg_stripe_sync_metadata_array($customer->metadata ?? []);
    $piva = tdg_stripe_sync_pick_meta($metadata, ['piva', 'partita_iva', 'vat_number', 'tax_id']);
    $azienda = tdg_stripe_sync_pick_meta($metadata, ['azienda', 'company', 'company_name', 'ragione_sociale']);

    return [
        'nome' => trim((string)($customer->name ?? '')),
        'email' => strtolower(trim((string)($customer->email ?? ''))),
        'telefono' => trim((string)($customer->phone ?? '')),
        'azienda' => trim($azienda),
        'indirizzo' => tdg_stripe_sync_address_text($customer->address ?? null),
        'piva' => strtoupper((string)preg_replace('/\s+/', '', $piva)),
        'codice_sdi' => strtoupper(trim(tdg_stripe_sync_pick_meta($metadata, ['codice_sdi', 'sdi', 'recipient_code', 'codice_destinatario']))),
        'pec' => strtolower(trim(tdg_stripe_sync_pick_meta($metadata, ['pec', 'certified_email']))),
    ];
}

function tdg_stripe_sync_add_client_to_maps(array &$byEmail, array &$byName, array $cliente): void
{
    $id = (int)($cliente['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    $email = strtolower(trim((string)($cliente['email'] ?? '')));
    if ($email !== '') {
        $byEmail[$email] = $byEmail[$email] ?? [];
        if (!in_array($id, $byEmail[$email], true)) {
            $byEmail[$email][] = $id;
        }
    }

    foreach (['nome', 'azienda'] as $field) {
        $nameKey = tdg_stripe_sync_normalize_name((string)($cliente[$field] ?? ''));
        if ($nameKey === '') {
            continue;
        }
        $byName[$nameKey] = $byName[$nameKey] ?? [];
        if (!in_array($id, $byName[$nameKey], true)) {
            $byName[$nameKey][] = $id;
        }
    }
}

function tdg_stripe_sync_unique_map_id(array $map, string $key): int
{
    $key = trim($key);
    if ($key === '' || empty($map[$key])) {
        return 0;
    }

    $ids = array_values(array_unique(array_map('intval', $map[$key])));
    return count($ids) === 1 ? (int)$ids[0] : 0;
}

function tdg_stripe_sync_update_client_empty_fields(PDO $pdo, int $clienteId, array $data): bool
{
    return tdg_cliente_sync_apply_updates($pdo, $clienteId, [
        'nome' => $data['nome'] ?? '',
        'email' => $data['email'] ?? '',
        'telefono' => $data['telefono'] ?? '',
        'azienda' => $data['azienda'] ?? '',
        'indirizzo' => $data['indirizzo'] ?? '',
        'piva' => $data['piva'] ?? '',
        'codice_sdi' => $data['codice_sdi'] ?? '',
        'pec' => $data['pec'] ?? '',
    ]);
}

function tdg_stripe_sync_create_client(PDO $pdo, array $data, string $customerId): int
{
    $nome = trim((string)($data['nome'] ?? ''));
    $azienda = trim((string)($data['azienda'] ?? ''));
    $nomeIns = $nome !== '' ? $nome : ($azienda !== '' ? $azienda : 'Cliente Stripe');

    $stmt = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note, creato_il) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $nomeIns,
        trim((string)($data['email'] ?? '')) !== '' ? $data['email'] : null,
        trim((string)($data['telefono'] ?? '')) !== '' ? $data['telefono'] : null,
        $azienda !== '' ? $azienda : null,
        trim((string)($data['indirizzo'] ?? '')) !== '' ? $data['indirizzo'] : null,
        trim((string)($data['piva'] ?? '')) !== '' ? $data['piva'] : null,
        trim((string)($data['codice_sdi'] ?? '')) !== '' ? $data['codice_sdi'] : null,
        trim((string)($data['pec'] ?? '')) !== '' ? $data['pec'] : null,
        'Importato da Sync Stripe (Customer: ' . $customerId . ')',
    ]);

    return (int)$pdo->lastInsertId();
}

function tdg_stripe_sync_output(bool $isApi, array $payload): void
{
    if ($isApi) {
        if (!($payload['success'] ?? false)) {
            http_response_code(500);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        return;
    }

    header('Content-Type: text/plain;charset=utf-8');
    if (!($payload['success'] ?? false)) {
        http_response_code(500);
        echo 'Errore: ' . (string)($payload['error'] ?? 'Sincronizzazione non riuscita') . "\n";
        return;
    }

    echo "Sincronizzazione completata\n";
    echo '   Customer Stripe letti: ' . (int)($payload['processed'] ?? 0) . "\n";
    echo '   Clienti creati: ' . (int)($payload['created'] ?? 0) . "\n";
    echo '   Clienti aggiornati: ' . (int)($payload['updated'] ?? 0) . "\n";
    echo '   Collegamenti sincronizzati: ' . (int)($payload['synced'] ?? 0) . "\n";
    echo '   Errori: ' . (int)($payload['errors'] ?? 0) . "\n";
    echo '   Timestamp: ' . (string)($payload['timestamp'] ?? date('Y-m-d H:i:s')) . "\n";
}

try {
    set_time_limit(180);

    $secretPath = __DIR__ . '/../../config/secret.php';
    if (!file_exists($secretPath)) {
        throw new RuntimeException('config/secret.php non trovato');
    }

    $secret = require $secretPath;
    $stripeCfg = $secret['stripe'] ?? [];
    $stripeKey = $stripeCfg['secret_key'] ?? '';

    if (!$stripeKey || strpos($stripeKey, 'sk_') !== 0) {
        throw new RuntimeException('Chiave Stripe non configurata');
    }

    require_once __DIR__ . '/../../vendor/autoload.php';
    \Stripe\Stripe::setApiKey($stripeKey);

    $processed = 0;
    $created = 0;
    $updated = 0;
    $synced = 0;
    $errors = 0;

    $dbByEmail = [];
    $dbByName = [];
    $stmtClients = $pdo->prepare("SELECT id, nome, email, azienda FROM clienti ORDER BY id");
    $stmtClients->execute();
    foreach ($stmtClients->fetchAll(PDO::FETCH_ASSOC) as $dbClient) {
        tdg_stripe_sync_add_client_to_maps($dbByEmail, $dbByName, $dbClient);
    }

    $clientIdByStripeCustomer = tdg_load_stripe_customer_map($pdo);

    $stripeCustomers = [];
    foreach (\Stripe\Customer::all(['limit' => 100])->autoPagingIterator() as $customer) {
        $customerId = trim((string)($customer->id ?? ''));
        if ($customerId !== '') {
            $stripeCustomers[$customerId] = $customer;
        }
    }

    $chargesByCustomer = [];
    foreach (\Stripe\Charge::all(['limit' => 100, 'expand' => ['data.customer']])->autoPagingIterator() as $charge) {
        $customerId = is_string($charge->customer) ? $charge->customer : ($charge->customer->id ?? null);
        $customerId = trim((string)$customerId);
        if ($customerId === '') {
            continue;
        }

        if (!isset($chargesByCustomer[$customerId])) {
            $chargesByCustomer[$customerId] = [
                'total_amount' => 0,
                'count' => 0,
                'last_charge' => null,
                'last_date' => 0,
            ];
        }

        if ((string)($charge->status ?? '') === 'succeeded') {
            $chargesByCustomer[$customerId]['total_amount'] += (int)($charge->amount ?? 0);
            $chargesByCustomer[$customerId]['count']++;
        }

        $chargeCreated = (int)($charge->created ?? 0);
        if ($chargeCreated > (int)$chargesByCustomer[$customerId]['last_date']) {
            $chargesByCustomer[$customerId]['last_date'] = $chargeCreated;
            $chargesByCustomer[$customerId]['last_charge'] = $charge;
        }
    }

    $customersByClientId = [];
    foreach ($stripeCustomers as $customerId => $customer) {
        $processed++;
        $data = tdg_stripe_sync_customer_data($customer);
        $clienteId = (int)($clientIdByStripeCustomer[$customerId] ?? 0);

        if ($clienteId <= 0 && $data['email'] !== '') {
            $clienteId = tdg_stripe_sync_unique_map_id($dbByEmail, strtolower(trim($data['email'])));
        }

        if ($clienteId <= 0) {
            foreach ([$data['nome'], $data['azienda']] as $nameCandidate) {
                $clienteId = tdg_stripe_sync_unique_map_id(
                    $dbByName,
                    tdg_stripe_sync_normalize_name((string)$nameCandidate)
                );
                if ($clienteId > 0) {
                    break;
                }
            }
        }

        try {
            if ($clienteId <= 0) {
                $clienteId = tdg_stripe_sync_create_client($pdo, $data, $customerId);
                $created++;
            } elseif (tdg_stripe_sync_update_client_empty_fields($pdo, $clienteId, $data)) {
                $updated++;
            }

            if ($clienteId > 0) {
                tdg_stripe_sync_add_client_to_maps($dbByEmail, $dbByName, [
                    'id' => $clienteId,
                    'nome' => $data['nome'],
                    'azienda' => $data['azienda'],
                    'email' => $data['email'],
                ]);
                $customersByClientId[$clienteId][$customerId] = $customer;
            }
        } catch (Throwable $e) {
            $errors++;
            error_log("Stripe customer sync error $customerId: " . $e->getMessage());
        }
    }

    $stmtSync = $pdo->prepare(
        "INSERT INTO stripe_sync (cliente_id, stripe_customer_id, stripe_email, stripe_name, stripe_balance, stripe_country, last_charge_amount, last_charge_date, payment_method, total_charges, total_amount_charged, synced_at)
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
         synced_at = NOW()"
    );

    foreach ($customersByClientId as $clienteId => $linkedCustomers) {
        $primaryCustomer = null;
        $primaryCustomerId = null;
        $primaryScore = -1;
        $balance = 0;
        $totalAmount = 0;
        $totalCharges = 0;
        $lastChargeAmount = 0;
        $lastChargeDate = null;
        $paymentMethod = null;
        $lastCharge = null;
        $lastChargeTs = 0;

        foreach ($linkedCustomers as $customerId => $stripeCustomer) {
            $chargeStats = $chargesByCustomer[$customerId] ?? null;
            $totalAmount += $chargeStats ? (int)($chargeStats['total_amount'] ?? 0) : 0;
            $totalCharges += $chargeStats ? (int)($chargeStats['count'] ?? 0) : 0;
            $balance += (int)($stripeCustomer->balance ?? 0);

            $score = max(
                (int)($stripeCustomer->created ?? 0),
                $chargeStats ? (int)($chargeStats['last_date'] ?? 0) : 0
            );
            if ($score > $primaryScore) {
                $primaryScore = $score;
                $primaryCustomer = $stripeCustomer;
                $primaryCustomerId = $customerId;
            }

            if ($chargeStats && !empty($chargeStats['last_charge']) && (int)$chargeStats['last_date'] > $lastChargeTs) {
                $lastChargeTs = (int)$chargeStats['last_date'];
                $lastCharge = $chargeStats['last_charge'];
            }
        }

        if ($lastCharge) {
            $lastChargeAmount = (int)($lastCharge->amount ?? 0);
            $lastChargeDate = date('Y-m-d H:i:s', (int)($lastCharge->created ?? 0));
            $paymentMethod = isset($lastCharge->payment_method_details->type)
                ? (string)$lastCharge->payment_method_details->type
                : null;
        }

        try {
            $stmtSync->execute([
                ':cliente_id' => (int)$clienteId,
                ':stripe_customer_id' => $primaryCustomerId,
                ':stripe_email' => $primaryCustomer ? (string)($primaryCustomer->email ?? '') : null,
                ':stripe_name' => $primaryCustomer ? (string)($primaryCustomer->name ?? '') : null,
                ':stripe_balance' => $balance,
                ':stripe_country' => $primaryCustomer ? tdg_stripe_sync_customer_country($primaryCustomer) : null,
                ':last_charge_amount' => $lastChargeAmount,
                ':last_charge_date' => $lastChargeDate,
                ':payment_method' => $paymentMethod,
                ':total_charges' => $totalCharges,
                ':total_amount_charged' => $totalAmount,
            ]);
            foreach ($linkedCustomers as $customerId => $stripeCustomer) {
                tdg_upsert_stripe_customer_link(
                    $pdo,
                    (int)$clienteId,
                    (string)$customerId,
                    (string)($stripeCustomer->email ?? ''),
                    (string)($stripeCustomer->name ?? ''),
                    'mod_clienti_sync'
                );
            }
            $synced++;
        } catch (PDOException $e) {
            $errors++;
            error_log("Sync error for client $clienteId: " . $e->getMessage());
        }
    }

    tdg_stripe_sync_output($isApi, [
        'success' => true,
        'processed' => $processed,
        'created' => $created,
        'updated' => $updated,
        'synced' => $synced,
        'errors' => $errors,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    tdg_stripe_sync_output($isApi, [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}
