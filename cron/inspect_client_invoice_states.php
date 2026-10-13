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

$clienteIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['cliente_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));

if (!$clienteIds) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cliente_ids richiesto'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

try {
    $placeholders = implode(',', array_fill(0, count($clienteIds), '?'));
    $sql = "SELECT
                f.id AS fattura_id,
                f.cliente_id,
                TRIM(COALESCE(NULLIF(c.azienda, ''), NULLIF(c.nome, ''), CONCAT('Cliente #', c.id))) AS cliente,
                f.numero,
                f.data_emissione,
                f.stato,
                f.totale,
                f.fic_document_id,
                f.fic_status,
                f.mail_status,
                f.mail_error,
                f.mail_sent_at,
                f.ei_status,
                f.ei_error
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
              AND f.cliente_id IN ({$placeholders})
              AND (
                    LOWER(COALESCE(f.mail_status, '')) IN ('pending', 'error')
                    OR TRIM(COALESCE(f.mail_error, '')) <> ''
                    OR LOWER(COALESCE(f.ei_status, '')) IN ('', 'todo', 'error', 'duplicate_unresolved')
                    OR TRIM(COALESCE(f.ei_error, '')) <> ''
                    OR (
                        (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
                        AND LOWER(COALESCE(f.fic_status, '')) IN ('sync_error', 'duplicate_unresolved')
                    )
                  )
            ORDER BY f.cliente_id ASC, f.data_emissione DESC, f.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($clienteIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clients = [];
    foreach ($rows as $row) {
        $clienteId = (int)($row['cliente_id'] ?? 0);
        if ($clienteId <= 0) {
            continue;
        }

        if (!isset($clients[$clienteId])) {
            $clients[$clienteId] = [
                'cliente_id' => $clienteId,
                'cliente' => (string)($row['cliente'] ?? ''),
                'invoice_count' => 0,
                'mail_pending_or_error_count' => 0,
                'sdi_incomplete_count' => 0,
                'fic_sync_issue_count' => 0,
                'invoices' => [],
            ];
        }

        $mailStatus = strtolower(trim((string)($row['mail_status'] ?? '')));
        $eiStatus = strtolower(trim((string)($row['ei_status'] ?? '')));
        $ficStatus = strtolower(trim((string)($row['fic_status'] ?? '')));
        $mailError = trim((string)($row['mail_error'] ?? ''));
        $eiError = trim((string)($row['ei_error'] ?? ''));
        $ficDocumentId = trim((string)($row['fic_document_id'] ?? ''));

        $mailIssue = in_array($mailStatus, ['pending', 'error'], true) || $mailError !== '';
        $sdiIssue = in_array($eiStatus, ['', 'todo', 'error', 'duplicate_unresolved'], true) || $eiError !== '';
        $ficIssue = $ficDocumentId === '' && in_array($ficStatus, ['sync_error', 'duplicate_unresolved'], true);

        $clients[$clienteId]['invoice_count']++;
        if ($mailIssue) {
            $clients[$clienteId]['mail_pending_or_error_count']++;
        }
        if ($sdiIssue) {
            $clients[$clienteId]['sdi_incomplete_count']++;
        }
        if ($ficIssue) {
            $clients[$clienteId]['fic_sync_issue_count']++;
        }

        $clients[$clienteId]['invoices'][] = [
            'fattura_id' => (int)($row['fattura_id'] ?? 0),
            'numero' => (string)($row['numero'] ?? ''),
            'data_emissione' => (string)($row['data_emissione'] ?? ''),
            'stato' => (string)($row['stato'] ?? ''),
            'totale' => $row['totale'] ?? null,
            'fic_document_id' => $ficDocumentId !== '' ? $ficDocumentId : null,
            'fic_status' => (string)($row['fic_status'] ?? ''),
            'mail_status' => (string)($row['mail_status'] ?? ''),
            'mail_error' => $mailError !== '' ? $mailError : null,
            'mail_sent_at' => (string)($row['mail_sent_at'] ?? ''),
            'ei_status' => (string)($row['ei_status'] ?? ''),
            'ei_error' => $eiError !== '' ? $eiError : null,
            'mail_issue' => $mailIssue,
            'sdi_issue' => $sdiIssue,
            'fic_issue' => $ficIssue,
        ];
    }

    echo json_encode([
        'ok' => true,
        'cliente_ids' => $clienteIds,
        'summary' => [
            'clients_checked' => count($clienteIds),
            'clients_with_issues' => count($clients),
            'invoice_rows' => count($rows),
        ],
        'clients' => array_values($clients),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}