<?php
/**
 * CRON: Sincronizza anagrafiche clienti da Stripe → DB
 *
 * Cron job (ogni 15 minuti):
 *   wget -q -O /dev/null "https://crm.example.com/cron/sync_customers.php?key=CHIAVE"
 *
 * Scarica tutti i Customer da Stripe e aggiorna/crea clienti locali.
 * Aggiorna: email, nome, telefono, P.IVA, SDI, PEC, azienda da metadata.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../config/stripe_customer_links.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$fullSync = isset($_GET['full']) && (string)$_GET['full'] === '1';

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$secret = file_exists(__DIR__ . '/../config/secret.php') ? require __DIR__ . '/../config/secret.php' : [];
$pdo    = require __DIR__ . '/../config/db.php';

$stripeKey = $secret['stripe']['secret_key'] ?? $config['stripe']['secret_key'] ?? '';
if (!$stripeKey || strpos($stripeKey, 'sk_') !== 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stripe non configurato']);
    exit;
}

\Stripe\Stripe::setApiKey($stripeKey);

$stats = [
    'processed'  => 0,
    'created'    => 0,
    'updated'    => 0,
    'unchanged'  => 0,
    'errors'     => 0,
];

function pick_meta(array $meta, array $keys): string
{
    foreach ($keys as $k) {
        $v = trim((string)($meta[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}

try {
    $customerParams = ['limit' => 100];
    if (!$fullSync) {
        $customerParams['created'] = ['gte' => time() - 86400];
    }

    $customers = \Stripe\Customer::all($customerParams);

    $maxToProcess = 500;
    $count = 0;

    foreach ($customers->autoPagingIterator() as $cust) {
        if (++$count > $maxToProcess) break;
        $stats['processed']++;

        $stripeCustomerId = (string)($cust->id ?? '');
        $email = strtolower(trim($cust->email ?? ''));
        $nome = trim($cust->name ?? '');
        $telefono = trim($cust->phone ?? '');
        $meta = is_object($cust->metadata) ? json_decode(json_encode($cust->metadata), true) : (array)($cust->metadata ?? []);

        $piva = strtoupper(preg_replace('/\s+/', '', pick_meta($meta, ['piva', 'partita_iva', 'vat_number', 'tax_id'])));
        $sdi = strtoupper(trim(pick_meta($meta, ['codice_sdi', 'sdi', 'recipient_code', 'codice_destinatario'])));
        $pec = strtolower(trim(pick_meta($meta, ['pec', 'certified_email'])));
        $azienda = pick_meta($meta, ['azienda', 'company', 'company_name', 'ragione_sociale']);

        $addr = '';
        if ($cust->address) {
            $a = is_object($cust->address) ? json_decode(json_encode($cust->address), true) : (array)$cust->address;
            $parts = [];
            foreach (['line1', 'line2', 'postal_code', 'city', 'state', 'country'] as $k) {
                $v = trim((string)($a[$k] ?? ''));
                if ($v !== '') $parts[] = $v;
            }
            $addr = implode(', ', $parts);
        }

        // Cerca cliente esistente
        $clienteId = 0;

        // 1. Da stripe_sync
        if ($stripeCustomerId !== '') {
            try {
                $clienteId = tdg_find_cliente_id_by_stripe_customer($pdo, $stripeCustomerId);
            } catch (Throwable $e) {}
        }

        // 2. Per email
        if ($clienteId <= 0 && $email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $clienteId = (int)$row['id'];
        }

        // 3. Per nome
        if ($clienteId <= 0 && $nome !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(nome)) = LOWER(?) OR LOWER(TRIM(azienda)) = LOWER(?) LIMIT 1");
            $stmt->execute([$nome, $nome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $clienteId = (int)$row['id'];
        }

        // 4. Crea nuovo
        if ($clienteId <= 0) {
            $nomeIns = $nome !== '' ? $nome : ($azienda !== '' ? $azienda : 'Cliente Stripe');
            try {
                $stmt = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note, creato_il) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                $stmt->execute([
                    $nomeIns,
                    $email !== '' ? $email : null,
                    $telefono !== '' ? $telefono : null,
                    $azienda !== '' ? $azienda : null,
                    $addr !== '' ? $addr : null,
                    $piva !== '' ? $piva : null,
                    $sdi !== '' ? $sdi : null,
                    $pec !== '' ? $pec : null,
                    'Importato da cron sync_customers (Stripe: ' . $stripeCustomerId . ')',
                ]);
                $clienteId = (int)$pdo->lastInsertId();
                $stats['created']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                continue;
            }
        } else {
            $updatedClient = tdg_cliente_sync_apply_updates($pdo, $clienteId, [
                'email' => $email,
                'nome' => $nome,
                'telefono' => $telefono,
                'azienda' => $azienda,
                'indirizzo' => $addr,
                'piva' => $piva,
                'codice_sdi' => $sdi,
                'pec' => $pec,
            ]);
            if ($updatedClient) {
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        }

        // Aggiorna stripe_sync
        if ($clienteId > 0 && $stripeCustomerId !== '') {
            try {
                $pdo->prepare("INSERT INTO stripe_sync (cliente_id, stripe_customer_id, stripe_email, stripe_name, synced_at)
                    VALUES (?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE cliente_id = VALUES(cliente_id), stripe_email = VALUES(stripe_email), stripe_name = VALUES(stripe_name), synced_at = NOW()")
                    ->execute([$clienteId, $stripeCustomerId, $email, $nome]);
                tdg_upsert_stripe_customer_link($pdo, $clienteId, $stripeCustomerId, $email, $nome, 'cron_sync_customers');
            } catch (Throwable $e) {}
        }
    }

    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'mode' => $fullSync ? 'full' : 'recent',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr($e->getMessage(), 0, 300),
    ]);
}
