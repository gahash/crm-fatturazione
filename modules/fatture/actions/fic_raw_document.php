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

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $docId = trim((string)($_GET['doc_id'] ?? ''));
    if ($docId === '') {
        throw new RuntimeException('doc_id mancante');
    }

    $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
    echo json_encode([
        'ok' => (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300),
        'company_id' => $companyId,
        'doc_id' => $docId,
        'status' => $response['status'] ?? null,
        'content_type' => $response['content_type'] ?? null,
        'json' => $response['json'] ?? null,
        'body' => $response['body'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}