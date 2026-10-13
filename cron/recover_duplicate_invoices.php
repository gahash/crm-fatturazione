<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$pdo = require __DIR__ . '/../config/db.php';

$GLOBALS['FIC_API_MAX_ATTEMPTS'] = 3;
$GLOBALS['FIC_API_CONNECT_TIMEOUT'] = 6;
$GLOBALS['FIC_API_TIMEOUT'] = 18;

function recover_duplicates_is_db_gone(string $message): bool
{
    $message = strtolower($message);
    return strpos($message, 'server has gone away') !== false
        || strpos($message, 'lost connection') !== false;
}

function recover_duplicates_refresh_pdo(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        unset($GLOBALS['_tdg_pdo']);
        if (function_exists('get_db')) {
            $pdo = get_db(true);
            return;
        }
    }

    $pdo = require __DIR__ . '/../config/db.php';
}

function recover_duplicates_retry(PDO &$pdo, callable $callback)
{
    try {
        recover_duplicates_refresh_pdo($pdo);
        return $callback($pdo);
    } catch (Throwable $e) {
        if (!recover_duplicates_is_db_gone((string)$e->getMessage())) {
            throw $e;
        }
        recover_duplicates_refresh_pdo($pdo);
        return $callback($pdo);
    }
}

function recover_duplicates_fetch_candidates(PDO &$pdo, int $limit, int $singleInvoiceId = 0): array
{
    return recover_duplicates_retry($pdo, static function (PDO $db) use ($limit, $singleInvoiceId): array {
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

        $sql .= ' ORDER BY f.data_emissione ASC, f.id ASC LIMIT ' . (int)$limit;

        $stmt = $db->prepare($sql);
        if ($singleInvoiceId > 0) {
            $stmt->bindValue(':fattura_id', $singleInvoiceId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });
}

function recover_duplicates_reset_invoice(PDO &$pdo, int $fatturaId, string $newNumero): void
{
    recover_duplicates_retry($pdo, static function (PDO $db) use ($fatturaId, $newNumero): void {
        $db->prepare(
            "UPDATE fatture
             SET numero = :num,
                 fic_status = NULL,
                 fic_document_id = NULL,
                 ei_status = NULL,
                 ei_error = NULL,
                 mail_error = NULL
             WHERE id = :id"
        )->execute([
            ':num' => $newNumero,
            ':id' => $fatturaId,
        ]);
    });
}

function recover_duplicates_fetch_invoice(PDO &$pdo, int $fatturaId): ?array
{
    return recover_duplicates_retry($pdo, static function (PDO $db) use ($fatturaId): ?array {
        $stmt = $db->prepare(
            "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
             FROM fatture f
             JOIN clienti c ON c.id = f.cliente_id
             WHERE f.id = ?
             LIMIT 1"
        );
        $stmt->execute([$fatturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    });
}

function recover_duplicates_fetch_rows(PDO &$pdo, int $fatturaId): array
{
    return recover_duplicates_retry($pdo, static function (PDO $db) use ($fatturaId): array {
        $stmt = $db->prepare('SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC');
        $stmt->execute([$fatturaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });
}

function recover_duplicates_fetch_state(PDO &$pdo, int $fatturaId): array
{
    return recover_duplicates_retry($pdo, static function (PDO $db) use ($fatturaId): array {
        $stmt = $db->prepare('SELECT numero, fic_document_id, fic_status, ei_status, ei_error, mail_error FROM fatture WHERE id = ? LIMIT 1');
        $stmt->execute([$fatturaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    });
}

function recover_duplicates_try_process(PDO &$pdo, array $fattura, array $cfg, string $companyId): array
{
    $fatturaId = (int)($fattura['id'] ?? 0);
    $righe = recover_duplicates_fetch_rows($pdo, $fatturaId);

    $attempt = static function () use (&$pdo, $fattura, $righe, $cfg, $companyId): array {
        [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
        if (!empty($docId)) {
            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'cron_duplicate_recovery');
        }
        return [(string)$docId, recover_duplicates_fetch_state($pdo, (int)$fattura['id'])];
    };

    try {
        return $attempt();
    } catch (Throwable $e) {
        if (!recover_duplicates_is_db_gone((string)$e->getMessage())) {
            throw $e;
        }

        recover_duplicates_refresh_pdo($pdo);
        $state = recover_duplicates_fetch_state($pdo, $fatturaId);
        if (!empty($state['fic_document_id'])) {
            return [(string)$state['fic_document_id'], $state];
        }

        return $attempt();
    }
}

try {
    $cfg = fic_config();
    $cfg['allow_duplicate_force_create'] = true;

    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $limit = (int)($_GET['limit'] ?? 25);
    $singleInvoiceId = max(0, (int)($_GET['fattura_id'] ?? 0));
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    if ($singleInvoiceId > 0) {
        $limit = 1;
    }

    $candidates = recover_duplicates_fetch_candidates($pdo, $limit, $singleInvoiceId);
    $stats = [
        'ok' => true,
        'single_invoice_id' => $singleInvoiceId > 0 ? $singleInvoiceId : null,
        'selected' => count($candidates),
        'fixed' => 0,
        'failed' => 0,
        'skipped_missing_data' => 0,
        'processed' => [],
        'errors' => [],
    ];

    foreach ($candidates as $row) {
        $fatturaId = (int)($row['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }

        $missingDataError = fic_einvoice_missing_data_error($row);
        if ($missingDataError !== null) {
            $stats['skipped_missing_data']++;
            $stats['processed'][] = [
                'id' => $fatturaId,
                'cliente' => trim((string)(($row['azienda'] ?? '') !== '' ? $row['azienda'] : ($row['nome'] ?? ''))),
                'numero' => (string)($row['numero'] ?? ''),
                'status' => 'skipped_missing_data',
                'message' => $missingDataError,
            ];
            continue;
        }

        try {
            $newNumero = fic_generate_duplicate_retry_number($pdo, $row);
            if ($newNumero === null || $newNumero === '') {
                $newNumero = 'STR-INV-' . date('Ymd-His') . '-R' . $fatturaId;
            }
            recover_duplicates_reset_invoice($pdo, $fatturaId, $newNumero);

            $fattura = recover_duplicates_fetch_invoice($pdo, $fatturaId);
            if (!$fattura) {
                throw new RuntimeException('Fattura non trovata dopo aggiornamento');
            }

            $targetNumeration = fic_duplicate_recovery_numeration($cfg, ['fic_forced_number' => '1'] + $fattura);
            if ($targetNumeration !== '') {
                $fattura['fic_target_numeration'] = $targetNumeration;
            }
            $forcedNumber = fic_pick_chronological_number(
                $companyId,
                (string)($fattura['document_type'] ?? 'invoice'),
                $fattura,
                15,
                [],
                $targetNumeration
            );
            if ($forcedNumber === null && $targetNumeration !== '') {
                $forcedNumber = fic_build_smart_recovery_number($fattura);
            }
            if ($forcedNumber === null && $targetNumeration !== '') {
                $forcedNumber = 1;
            }
            if ($forcedNumber !== null) {
                $fattura['fic_forced_number'] = (string)$forcedNumber;
            }

            [$docId, $state] = recover_duplicates_try_process($pdo, $fattura, $cfg, $companyId);

            $stats['fixed']++;
            $stats['processed'][] = [
                'id' => $fatturaId,
                'cliente' => trim((string)(($fattura['azienda'] ?? '') !== '' ? $fattura['azienda'] : ($fattura['nome'] ?? ''))),
                'numero' => (string)($state['numero'] ?? $fattura['numero'] ?? ''),
                'status' => 'fixed',
                'fic_document_id' => $docId,
                'fic_status' => $state['fic_status'] ?? null,
                'ei_status' => $state['ei_status'] ?? null,
            ];
        } catch (Throwable $e) {
            $message = substr((string)$e->getMessage(), 0, 220);
            $stats['failed']++;
            $stats['errors'][] = 'Fattura #' . ($row['numero'] ?? $fatturaId) . ': ' . $message;
            try {
                recover_duplicates_refresh_pdo($pdo);
                fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $message, false);
            } catch (Throwable $ignored) {
            }
            $stats['processed'][] = [
                'id' => $fatturaId,
                'cliente' => trim((string)(($row['azienda'] ?? '') !== '' ? $row['azienda'] : ($row['nome'] ?? ''))),
                'numero' => (string)($row['numero'] ?? ''),
                'status' => 'error',
                'message' => $message,
            ];
        }
    }

    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}