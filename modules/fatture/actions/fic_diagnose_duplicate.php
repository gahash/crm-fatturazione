<?php
require __DIR__ . '/../../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$pdo = require __DIR__ . '/../../../config/db.php';

$fatturaId = (int)($_GET['id'] ?? 0);

try {
    if ($fatturaId > 0) {
        $stmt = $pdo->prepare(
            "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
             FROM fatture f
             JOIN clienti c ON c.id = f.cliente_id
             WHERE f.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $fatturaId]);
    } else {
        $stmt = $pdo->query(
            "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
             FROM fatture f
             JOIN clienti c ON c.id = f.cliente_id
             WHERE f.document_type = 'invoice'
               AND (f.fic_document_id IS NULL OR TRIM(f.fic_document_id) = '')
               AND (
                    LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved'
                    OR LOWER(COALESCE(f.mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                    OR LOWER(COALESCE(f.ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                    OR EXISTS (
                        SELECT 1
                        FROM fatture_log fl
                        WHERE fl.fattura_id = f.id
                          AND LOWER(COALESCE(fl.messaggio, '')) LIKE '%fic_duplicate_not_recoverable%'
                    )
               )
             ORDER BY f.id ASC
             LIMIT 1"
        );
    }

    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fattura non trovata']);
        exit;
    }

    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id ASC');
    $righeStmt->execute([(int)$fattura['id']]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cfg = fic_config();
    $cfg['allow_duplicate_force_create'] = true;
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $traceEvents = [];
    $GLOBALS['FIC_API_TRACE_CALLBACK'] = static function (array $event) use (&$traceEvents): void {
        if (($event['method'] ?? '') !== 'POST') {
            return;
        }
        if (strpos((string)($event['path'] ?? ''), '/issued_documents') === false) {
            return;
        }

        $traceEvents[] = $event;
    };

    $result = [
        'ok' => true,
        'fattura_id' => (int)$fattura['id'],
        'numero' => (string)($fattura['numero'] ?? ''),
        'created' => false,
        'doc_id' => null,
        'fic_status' => null,
        'first_409_logged' => false,
        'warning' => null,
    ];

    try {
        [$docId, $ficStatus] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
        $result['created'] = !empty($docId);
        $result['doc_id'] = $docId;
        $result['fic_status'] = $ficStatus;
        if (!empty($docId)) {
            $result['warning'] = 'La diagnosi ha creato o collegato un documento FIC reale.';
        }
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = substr($e->getMessage(), 0, 300);
    } finally {
        unset($GLOBALS['FIC_API_TRACE_CALLBACK']);
    }

    $first409 = null;
    foreach ($traceEvents as $event) {
        if ((int)($event['status'] ?? 0) === 409) {
            $first409 = $event;
            break;
        }
    }

    $meta = [
        'company_id' => $companyId,
        'fattura' => [
            'id' => (int)$fattura['id'],
            'numero' => (string)($fattura['numero'] ?? ''),
            'data_emissione' => (string)($fattura['data_emissione'] ?? ''),
            'data_pagamento' => (string)($fattura['data_pagamento'] ?? ''),
            'cliente_id' => (int)($fattura['cliente_id'] ?? 0),
            'fic_document_id' => (string)($fattura['fic_document_id'] ?? ''),
            'fic_status' => (string)($fattura['fic_status'] ?? ''),
        ],
        'trace_summary' => array_map(static function (array $event): array {
            return [
                'method' => $event['method'] ?? null,
                'path' => $event['path'] ?? null,
                'attempt' => $event['attempt'] ?? null,
                'status' => $event['status'] ?? null,
                'curl_error' => $event['curl_error'] ?? null,
            ];
        }, $traceEvents),
    ];

    if ($first409 !== null) {
        $meta['first_409'] = $first409;
        $result['first_409_logged'] = true;
        fatture_log($pdo, (int)$fattura['id'], 'fic_diag_409', 'info', 'Diagnostica duplicato FIC: salvato primo 409 con payload e risposta.', $meta);
    } else {
        if (!$traceEvents) {
            $meta['note'] = 'Nessuna chiamata POST /issued_documents intercettata dal trace.';
        }
        fatture_log($pdo, (int)$fattura['id'], 'fic_diag_409', 'info', 'Diagnostica duplicato FIC eseguita senza intercettare un 409.', $meta);
    }

    $result['trace_count'] = count($traceEvents);
    $result['first_409'] = $first409 !== null ? [
        'status' => $first409['status'] ?? null,
        'path' => $first409['path'] ?? null,
        'attempt' => $first409['attempt'] ?? null,
        'payload' => $first409['payload'] ?? null,
        'response_body' => substr((string)($first409['body'] ?? ''), 0, 4000),
        'response_json' => $first409['json'] ?? null,
    ] : null;

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    unset($GLOBALS['FIC_API_TRACE_CALLBACK']);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}