<?php
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';

$companyId = trim((string)($_GET['cid'] ?? ''));
$docId = trim((string)($_GET['doc'] ?? ''));
$expiresAt = (int)($_GET['exp'] ?? 0);
$signature = trim((string)($_GET['sig'] ?? ''));
$downloadMode = ((string)($_GET['download'] ?? '1')) !== '0';
$attempt = max(0, (int)($_GET['attempt'] ?? 0));
$maxAttempts = 12;

if (!preg_match('/^\d+$/', $companyId) || !preg_match('/^\d+$/', $docId)) {
    http_response_code(400);
    echo 'Link non valido.';
    exit;
}

if (!fic_verify_public_pdf_signature($companyId, $docId, $expiresAt, $signature)) {
    http_response_code(403);
    echo 'Link scaduto o non valido.';
    exit;
}

try {
    fic_publish_document_if_draft($companyId, $docId);
    [$pdfUrl, $pdfBody] = fic_fetch_pdf_with_wait($companyId, $docId, 12, 2000);

    if ($pdfBody === '' && $pdfUrl !== '') {
        $pdfBody = fic_download_binary_url($pdfUrl);
        if ($pdfBody === false && filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
            header('Location: ' . $pdfUrl);
            exit;
        }
    }

    if ($pdfBody === '' || $pdfBody === false) {
        http_response_code(503);
        echo 'PDF non disponibile al momento. Riprova tra qualche minuto.';
        exit;
    }

    $filename = 'fattura_' . $docId . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($downloadMode ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBody));
    echo $pdfBody;
    exit;
} catch (Throwable $e) {
    error_log('fic_pdf_public error cid=' . $companyId . ' doc=' . $docId . ' msg=' . substr((string)$e->getMessage(), 0, 300));

    $pdfUrl = fic_try_get_pdf_url($companyId, $docId);
    if ($pdfUrl !== '' && filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $pdfUrl);
        exit;
    }

    $docPublicUrl = fic_get_document_public_url($companyId, $docId);
    if ($docPublicUrl !== '' && filter_var($docPublicUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $docPublicUrl);
        exit;
    }

    if ($attempt < $maxAttempts) {
        $nextAttempt = $attempt + 1;
        $retryQuery = http_build_query([
            'cid' => $companyId,
            'doc' => $docId,
            'exp' => $expiresAt,
            'sig' => $signature,
            'download' => $downloadMode ? '1' : '0',
            'attempt' => $nextAttempt,
        ]);
        $retryUrl = 'fic_pdf_public.php?' . $retryQuery;
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta http-equiv="refresh" content="4;url=' . htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8') . '"><title>Preparazione PDF</title></head><body style="font-family:Arial,sans-serif;padding:24px;">';
        echo '<h3>Stiamo preparando il PDF...</h3>';
        echo '<p>Attendere qualche secondo, il download partirà automaticamente.</p>';
        echo '<p>Se non parte, <a href="' . htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8') . '">clicca qui</a>.</p>';
        echo '</body></html>';
        exit;
    }

    http_response_code(503);
    echo 'PDF non disponibile al momento. Riprova tra qualche minuto.';
    exit;
}
