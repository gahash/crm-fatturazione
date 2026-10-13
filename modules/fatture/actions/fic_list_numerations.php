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

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $docType = trim((string)($_GET['type'] ?? 'invoice'));
    if ($docType === '') {
        $docType = 'invoice';
    }

    $preCreate = fic_get_pre_create_info($companyId, $docType);
    $candidateKeys = [
        'numerations',
        'document_numerations',
        'numberings',
        'sections',
        'sectionals',
        'available_numerations',
        'options',
    ];

    $matches = [];
    foreach ($candidateKeys as $key) {
        $value = $preCreate[$key] ?? null;
        if ($value !== null) {
            $matches[$key] = $value;
        }
    }

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'doc_type' => $docType,
        'configured_duplicate_recovery_numeration' => (string)($cfg['duplicate_recovery_numeration'] ?? ''),
        'candidate_keys' => array_keys($matches),
        'candidate_values' => $matches,
        'pre_create_info' => $preCreate,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}