<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function reconcile_duplicates_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $updatedAlreadyLinked = 0;
    $updatedPaymentIntent = 0;

    $clearLinkedSql = "
        UPDATE fatture
        SET fic_status = CASE
                WHEN LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved' THEN 'linked'
                ELSE fic_status
            END,
            ei_status = CASE
                WHEN LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved' THEN NULL
                ELSE ei_status
            END,
            ei_error = CASE
                WHEN LOWER(COALESCE(ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                    OR LOWER(COALESCE(ei_error, '')) LIKE '%duplicato fic non risolto%'
                THEN NULL
                ELSE ei_error
            END,
            mail_error = CASE
                WHEN LOWER(COALESCE(mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                    OR LOWER(COALESCE(mail_error, '')) LIKE '%duplicato fic non risolto%'
                THEN NULL
                ELSE mail_error
            END,
            fic_synced_at = NOW()
        WHERE document_type = 'invoice'
          AND fic_document_id IS NOT NULL
          AND TRIM(fic_document_id) <> ''
          AND (
                LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved'
                OR LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved'
          )
    ";

    $updatedAlreadyLinked = (int)$pdo->exec($clearLinkedSql);

    if (reconcile_duplicates_column_exists($pdo, 'fatture', 'stripe_payment_intent_id')) {
        $samePaymentIntentSql = "
            UPDATE fatture f_old
            INNER JOIN (
                SELECT stripe_payment_intent_id, MAX(id) AS latest_id
                FROM fatture
                WHERE document_type = 'invoice'
                  AND stripe_payment_intent_id IS NOT NULL
                  AND TRIM(stripe_payment_intent_id) <> ''
                  AND fic_document_id IS NOT NULL
                  AND TRIM(fic_document_id) <> ''
                  AND LOWER(COALESCE(fic_status, '')) <> 'duplicate_unresolved'
                GROUP BY stripe_payment_intent_id
            ) map ON map.stripe_payment_intent_id = f_old.stripe_payment_intent_id
            INNER JOIN fatture f_new ON f_new.id = map.latest_id
            SET f_old.fic_document_id = f_new.fic_document_id,
                f_old.fic_status = CASE
                    WHEN TRIM(COALESCE(f_new.fic_status, '')) <> '' THEN f_new.fic_status
                    ELSE 'linked'
                END,
                f_old.fic_synced_at = NOW(),
                f_old.ei_status = CASE
                    WHEN LOWER(COALESCE(f_old.ei_status, '')) = 'duplicate_unresolved' THEN NULL
                    ELSE f_old.ei_status
                END,
                f_old.ei_error = CASE
                    WHEN LOWER(COALESCE(f_old.ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(f_old.ei_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE f_old.ei_error
                END,
                f_old.mail_error = CASE
                    WHEN LOWER(COALESCE(f_old.mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(f_old.mail_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE f_old.mail_error
                END
            WHERE f_old.document_type = 'invoice'
              AND f_old.id <> f_new.id
              AND LOWER(COALESCE(f_old.fic_status, '')) = 'duplicate_unresolved'
              AND (f_old.fic_document_id IS NULL OR TRIM(f_old.fic_document_id) = '')
        ";

        $updatedPaymentIntent = (int)$pdo->exec($samePaymentIntentSql);
    }

    echo json_encode([
        'ok' => true,
        'updated_already_linked' => $updatedAlreadyLinked,
        'updated_same_payment_intent' => $updatedPaymentIntent,
        'updated_total' => $updatedAlreadyLinked + $updatedPaymentIntent,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}