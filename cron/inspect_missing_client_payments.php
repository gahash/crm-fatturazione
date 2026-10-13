<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$emails = array_values(array_filter(array_map(static function ($value): string {
    return strtolower(trim((string)$value));
}, explode(',', (string)($_GET['emails'] ?? ''))), static function (string $value): bool {
    return $value !== '';
}));

if (!$emails) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'emails richiesto'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

try {
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $clientSql = "SELECT id, nome, azienda, email, piva, codice_sdi, pec, indirizzo, creato_il
                  FROM clienti
                  WHERE LOWER(TRIM(COALESCE(email, ''))) IN ($placeholders)
                  ORDER BY creato_il DESC, id DESC";
    $clientStmt = $pdo->prepare($clientSql);
    $clientStmt->execute($emails);
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($clients as $client) {
        $clienteId = (int)($client['id'] ?? 0);
        $email = strtolower(trim((string)($client['email'] ?? '')));
        $name = trim((string)($client['nome'] ?? ''));
        $company = trim((string)($client['azienda'] ?? ''));

        $dupStmt = $pdo->prepare(
            "SELECT id, nome, azienda, email, creato_il
             FROM clienti
             WHERE id <> :id
               AND (
                    LOWER(TRIM(COALESCE(email, ''))) = :email
                    OR LOWER(TRIM(COALESCE(nome, ''))) = LOWER(:name)
                    OR LOWER(TRIM(COALESCE(azienda, ''))) = LOWER(:company)
               )
             ORDER BY creato_il DESC, id DESC"
        );
        $dupStmt->execute([
            ':id' => $clienteId,
            ':email' => $email,
            ':name' => $name,
            ':company' => $company,
        ]);
        $duplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stripeSyncByClientStmt = $pdo->prepare(
            "SELECT cliente_id, stripe_customer_id, stripe_email, stripe_name, total_charges, total_amount_charged, last_charge_date, synced_at
             FROM stripe_sync
             WHERE cliente_id = ?
             ORDER BY synced_at DESC, id DESC
             LIMIT 5"
        );
        $stripeSyncByClientStmt->execute([$clienteId]);
        $stripeSyncByClient = $stripeSyncByClientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stripeSyncByEmailStmt = $pdo->prepare(
            "SELECT cliente_id, stripe_customer_id, stripe_email, stripe_name, total_charges, total_amount_charged, last_charge_date, synced_at
             FROM stripe_sync
             WHERE LOWER(TRIM(COALESCE(stripe_email, ''))) = ?
             ORDER BY synced_at DESC, id DESC
             LIMIT 10"
        );
        $stripeSyncByEmailStmt->execute([$email]);
        $stripeSyncByEmail = $stripeSyncByEmailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $paymentCountStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN LOWER(COALESCE(stato, '')) = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_rows
             FROM pagamenti
             WHERE cliente_id = ?"
        );
        $paymentCountStmt->execute([$clienteId]);
        $paymentCounts = $paymentCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $invoiceCountStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS stripe_invoice_rows,
                SUM(CASE WHEN LOWER(COALESCE(ei_status, '')) IN ('sent','processing','accepted','pending','todo','error') THEN 1 ELSE 0 END) AS einvoice_rows
             FROM fatture
             WHERE cliente_id = ?
               AND document_type = 'invoice'
               AND stripe_payment_intent_id IS NOT NULL
               AND TRIM(stripe_payment_intent_id) <> ''"
        );
        $invoiceCountStmt->execute([$clienteId]);
        $invoiceCounts = $invoiceCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $duplicateIds = array_values(array_filter(array_map(static function ($row): int {
            return (int)($row['id'] ?? 0);
        }, $duplicates), static function (int $id): bool {
            return $id > 0;
        }));

        $duplicatePaymentSummary = [];
        if ($duplicateIds) {
            $dupPlaceholders = implode(',', array_fill(0, count($duplicateIds), '?'));
            $dupPayStmt = $pdo->prepare(
                "SELECT cliente_id,
                        COUNT(*) AS total_rows,
                        SUM(CASE WHEN LOWER(COALESCE(stato, '')) = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_rows
                 FROM pagamenti
                 WHERE cliente_id IN ($dupPlaceholders)
                 GROUP BY cliente_id"
            );
            $dupPayStmt->execute($duplicateIds);
            $duplicatePaymentSummary = $dupPayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $results[] = [
            'client' => $client,
            'stripe_sync_by_client' => $stripeSyncByClient,
            'stripe_sync_by_email' => $stripeSyncByEmail,
            'payments_by_client_id' => [
                'total_rows' => (int)($paymentCounts['total_rows'] ?? 0),
                'succeeded_rows' => (int)($paymentCounts['succeeded_rows'] ?? 0),
            ],
            'stripe_invoices_by_client_id' => [
                'stripe_invoice_rows' => (int)($invoiceCounts['stripe_invoice_rows'] ?? 0),
                'einvoice_rows' => (int)($invoiceCounts['einvoice_rows'] ?? 0),
            ],
            'duplicates' => $duplicates,
            'duplicate_payments' => $duplicatePaymentSummary,
        ];
    }

    echo json_encode([
        'ok' => true,
        'email_count' => count($emails),
        'client_count' => count($results),
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}