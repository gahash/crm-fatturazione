<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    require __DIR__ . '/../../../auth.php';
    require_login();

    $user = current_user();
    if (($user['ruolo'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Accesso non autorizzato']);
        exit;
    }

    header('Content-Type: application/json');

    $pdo = require __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/fic_api.php';
    require_once __DIR__ . '/fic_audit.php';
    require_once __DIR__ . '/fic_alert_helpers.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Errore inizializzazione: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit;
}

$limit = max(1, min(300, (int)($_GET['limit'] ?? 80)));
$stripeOnly = !isset($_GET['stripe_only']) || (string)$_GET['stripe_only'] !== '0';
$includeOk = isset($_GET['include_ok']) && (string)$_GET['include_ok'] === '1';

function alignment_auto_send_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute([':setting_key' => 'fatture_send']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['send_mode' => 'manuale', 'enabled' => 0];
        return ((string)($row['send_mode'] ?? 'manuale') === 'automatico') && ((int)($row['enabled'] ?? 0) === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function alignment_snippet(string $value, int $max = 180): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length <= $max) {
        return $value;
    }

    $cut = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    return $cut . '…';
}

function alignment_normalized_snippet(string $value, int $max = 180): string
{
    return alignment_snippet(tdg_sdi_normalize_reason_text($value), $max);
}

function alignment_test_pdf_ready(string $companyId, string $docId): array
{
    try {
        $resp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}/pdf");
        $status = (int)($resp['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return [false, 'HTTP ' . $status . ' ' . alignment_snippet((string)($resp['body'] ?? ''))];
        }

        $contentType = (string)($resp['content_type'] ?? '');
        if (stripos($contentType, 'application/json') !== false) {
            $url = (string)($resp['json']['data']['url'] ?? '');
            if ($url === '') {
                return [false, 'Risposta JSON senza URL PDF'];
            }
            return [true, 'URL PDF disponibile'];
        }

        $body = (string)($resp['body'] ?? '');
        if ($body === '') {
            return [false, 'Body PDF vuoto'];
        }

        return [true, 'PDF binario disponibile'];
    } catch (Throwable $e) {
        return [false, alignment_snippet($e->getMessage())];
    }
}

$cfg = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}

if ($companyId === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'company_id Fatture in Cloud mancante',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$autoSendEnabled = alignment_auto_send_enabled($pdo);

// Verifica se la colonna metodo_pagamento esiste
$columnsCheck = $pdo->query("SHOW COLUMNS FROM fatture LIKE 'metodo_pagamento'");
$hasMetodoPagamento = $columnsCheck->rowCount() > 0;

if ($hasMetodoPagamento) {
    $sql = "SELECT f.id, f.numero, f.document_type, f.data_emissione, f.totale, f.stato, f.metodo_pagamento,
                   f.fic_document_id, f.fic_status, f.fic_pdf_url, f.mail_status, f.mail_error,
                   c.nome, c.azienda, c.email, c.piva
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'";
    
    if ($stripeOnly) {
        $sql .= " AND f.metodo_pagamento LIKE 'Stripe%'";
    }
} else {
    $sql = "SELECT f.id, f.numero, f.document_type, f.data_emissione, f.totale, f.stato,
                   f.fic_document_id, f.fic_status, f.fic_pdf_url, f.mail_status, f.mail_error,
                   c.nome, c.azienda, c.email, c.piva
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'";
}

$sql .= " ORDER BY f.data_emissione DESC, f.id DESC LIMIT :limit";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'checked' => count($fatture),
    'aligned' => 0,
    'with_issues' => 0,
    'missing_fic_document' => 0,
    'fic_document_unreachable' => 0,
    'pdf_not_ready' => 0,
    'auto_send_expected_but_not_sent' => 0,
    'anagrafica_incomplete' => 0,
];

$results = [];

foreach ($fatture as $f) {
    $issues = [];
    $warnings = [];
    $normalizedMailError = trim(tdg_sdi_normalize_reason_text((string)($f['mail_error'] ?? '')));

    $email = trim((string)($f['email'] ?? ''));
    $piva = trim((string)($f['piva'] ?? ''));
    $hasRegistry = trim((string)($f['azienda'] ?? '')) !== '' || trim((string)($f['nome'] ?? '')) !== '';
    $hasValidEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    $hasVat = $piva !== '';
    $isBillingReady = $hasValidEmail && $hasVat && $hasRegistry;

    if (!$isBillingReady) {
        $summary['anagrafica_incomplete']++;
        $missing = [];
        if (!$hasValidEmail) {
            $missing[] = 'email valida';
        }
        if (!$hasVat) {
            $missing[] = 'partita IVA';
        }
        if (!$hasRegistry) {
            $missing[] = 'anagrafica (nome/azienda)';
        }
        $warnings[] = 'Anagrafica incompleta: ' . implode(', ', $missing);
    }

    if ($autoSendEnabled && $isBillingReady && (string)($f['mail_status'] ?? '') !== 'sent') {
        $summary['auto_send_expected_but_not_sent']++;
        $issues[] = $normalizedMailError !== ''
            ? $normalizedMailError
            : 'Invio automatico atteso ma non completato.';
    }

    $docId = trim((string)($f['fic_document_id'] ?? ''));
    if ($docId === '') {
        $summary['missing_fic_document']++;
        if ($normalizedMailError !== '') {
            $issues[] = $normalizedMailError;
        } else {
            $issues[] = 'Documento FIC non ancora creato o collegato.';
        }
    } else {
        $docResp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
        $status = (int)($docResp['status'] ?? 0);

        if ($status < 200 || $status >= 300) {
            $summary['fic_document_unreachable']++;
            $issues[] = 'Documento FIC non raggiungibile (HTTP ' . $status . ')';
        } else {
            $doc = $docResp['json']['data'] ?? [];
            $docStatus = (string)($doc['status'] ?? '');
            if ($docStatus === 'draft') {
                $warnings[] = 'Documento FIC in stato draft';
            }

            $docDate = (string)($doc['date'] ?? '');
            if ($docDate !== '' && $docDate !== (string)$f['data_emissione']) {
                $warnings[] = 'Data emissione diversa tra gestionale e FIC';
            }

            [$pdfReady, $pdfInfo] = alignment_test_pdf_ready($companyId, $docId);
            if (!$pdfReady) {
                $summary['pdf_not_ready']++;
                $issues[] = 'PDF non disponibile: ' . $pdfInfo;
            }
        }
    }

    $ok = empty($issues);
    if ($ok) {
        $summary['aligned']++;
    } else {
        $summary['with_issues']++;
    }

    if ($includeOk || !$ok || !empty($warnings)) {
        $results[] = [
            'fattura_id' => (int)$f['id'],
            'numero' => (string)$f['numero'],
            'cliente' => (string)(($f['azienda'] ?: $f['nome']) ?: '-'),
            'mail_status' => (string)($f['mail_status'] ?? ''),
            'fic_document_id' => $docId !== '' ? $docId : null,
            'ok' => $ok,
            'issues' => $issues,
            'warnings' => $warnings,
            'mail_error' => alignment_normalized_snippet((string)($f['mail_error'] ?? '')),
        ];
    }
}

if (function_exists('fatture_log')) {
    fatture_log($pdo, null, 'fic_alignment_test', $summary['with_issues'] > 0 ? 'info' : 'ok', 'Test allineamento FIC completato.', [
        'checked' => $summary['checked'],
        'with_issues' => $summary['with_issues'],
        'stripe_only' => $stripeOnly,
        'auto_send_enabled' => $autoSendEnabled,
    ]);
}

echo json_encode([
    'ok' => true,
    'run_at' => date('c'),
    'company_id' => $companyId,
    'has_metodo_pagamento_column' => $hasMetodoPagamento,
    'migration_needed' => !$hasMetodoPagamento,
    'scope' => [
        'stripe_only' => $stripeOnly,
        'limit' => $limit,
        'auto_send_enabled' => $autoSendEnabled,
    ],
    'summary' => $summary,
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
