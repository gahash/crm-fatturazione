<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_audit.php';

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim((string)($appConfig['app']['base_url'] ?? 'http://localhost:8000'), '/');

function fic_cancel_build_return_url(string $baseUrl, string $returnPath, array $params = []): string
{
    $returnPath = trim($returnPath);
    if ($returnPath === '' || strpos($returnPath, '://') !== false || strpos($returnPath, '//') === 0 || strpos($returnPath, '..') !== false) {
        $returnPath = 'index.php';
    }

    $returnPath = ltrim($returnPath, '/');
    if (strpos($returnPath, 'modules/fatture/') !== 0) {
        $returnPath = 'modules/fatture/' . $returnPath;
    }

    $url = rtrim($baseUrl, '/') . '/' . $returnPath;
    if (!empty($params)) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $sep . http_build_query($params);
    }

    return $url;
}

function fic_cancel_error_snippet(array $response, int $maxLen = 240): string
{
    $jsonMsg = trim((string)($response['json']['error']['message'] ?? ''));
    if ($jsonMsg !== '') {
        return substr($jsonMsg, 0, $maxLen);
    }

    $body = trim((string)($response['body'] ?? ''));
    if ($body === '') {
        return 'Errore API FIC non dettagliato';
    }

    $body = preg_replace('/\s+/', ' ', $body);
    return substr((string)$body, 0, $maxLen);
}

$id = (int)($_GET['id'] ?? 0);
$returnPath = (string)($_GET['return'] ?? 'index.php');
if ($id <= 0) {
    header('Location: ' . fic_cancel_build_return_url($baseUrl, $returnPath, [
        'ei_cancel' => 'error',
        'ei_cancel_msg' => 'ID fattura non valido',
    ]));
    exit;
}

$stmt = $pdo->prepare('SELECT id, numero, fic_document_id, ei_status FROM fatture WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fattura) {
    header('Location: ' . fic_cancel_build_return_url($baseUrl, $returnPath, [
        'ei_cancel' => 'error',
        'ei_cancel_msg' => 'Fattura non trovata',
    ]));
    exit;
}

$docId = trim((string)($fattura['fic_document_id'] ?? ''));
if ($docId === '') {
    header('Location: ' . fic_cancel_build_return_url($baseUrl, $returnPath, [
        'ei_cancel' => 'error',
        'ei_cancel_msg' => 'Nessun documento FIC collegato',
    ]));
    exit;
}

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    $deleteResponse = fic_request('DELETE', "/c/{$companyId}/issued_documents/{$docId}");
    $deleteStatus = (int)($deleteResponse['status'] ?? 0);
    $alreadyMissing = ($deleteStatus === 404);

    if (!$alreadyMissing && ($deleteStatus < 200 || $deleteStatus >= 300)) {
        $msg = 'HTTP ' . $deleteStatus . ': ' . fic_cancel_error_snippet($deleteResponse);
        throw new RuntimeException($msg);
    }

    $upd = $pdo->prepare(
        'UPDATE fatture
         SET fic_document_id = NULL,
             fic_status = NULL,
             fic_pdf_url = NULL,
             fic_synced_at = NULL,
             ei_status = NULL,
             ei_error = NULL,
             ei_sent_at = NULL
         WHERE id = :id'
    );
    $upd->execute([':id' => $id]);

    fatture_log($pdo, $id, 'cancel_einvoice', 'ok', $alreadyMissing
        ? 'Annullamento e-fattura: documento gia assente su FIC, collegamento locale azzerato.'
        : 'Annullamento e-fattura completato: documento cancellato da FIC e tracking SDI azzerato.', [
        'fic_document_id' => $docId,
        'ei_status_before' => (string)($fattura['ei_status'] ?? ''),
    ]);

    header('Location: ' . fic_cancel_build_return_url($baseUrl, $returnPath, [
        'ei_cancel' => 'ok',
        'num' => (string)($fattura['numero'] ?? ''),
    ]));
    exit;
} catch (Throwable $e) {
    $msg = substr((string)$e->getMessage(), 0, 220);
    fatture_log($pdo, $id, 'cancel_einvoice', 'error', 'Errore annullamento e-fattura: ' . $msg, [
        'fic_document_id' => $docId,
        'ei_status_before' => (string)($fattura['ei_status'] ?? ''),
    ]);

    header('Location: ' . fic_cancel_build_return_url($baseUrl, $returnPath, [
        'ei_cancel' => 'error',
        'ei_cancel_msg' => $msg,
    ]));
    exit;
}
