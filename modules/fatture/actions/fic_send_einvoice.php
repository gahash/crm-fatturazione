<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');

function fatture_ei_build_return_url(string $baseUrl, string $returnPath, array $params = []): string
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

function fatture_ei_single_is_duplicate_not_recoverable(string $msg): bool
{
    return stripos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') !== false;
}

function fatture_ei_single_mark_duplicate_unresolved(PDO $pdo, int $fatturaId, string $error): void
{
    if ($fatturaId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            UPDATE fatture
            SET fic_status = 'duplicate_unresolved',
                fic_synced_at = NOW(),
                ei_status = 'duplicate_unresolved',
                ei_error = :err
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $fatturaId,
            ':err' => substr($error, 0, 1000),
        ]);
    } catch (Throwable $e) {
        // Non bloccare il redirect dell'utente.
    }
}

$id = (int)($_GET['id'] ?? 0);
$returnPath = (string)($_GET['return'] ?? 'index.php');
if ($id <= 0) {
    header('Location: ' . fatture_ei_build_return_url($baseUrl, $returnPath, [
        'ei' => 'error',
        'ei_msg' => 'ID fattura non valido',
    ]));
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                       FROM fatture f
                       JOIN clienti c ON c.id = f.cliente_id
                       WHERE f.id = :id
                       LIMIT 1");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fattura) {
    header('Location: ' . fatture_ei_build_return_url($baseUrl, $returnPath, [
        'ei' => 'error',
        'ei_msg' => 'Fattura non trovata',
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
} catch (Throwable $e) {
    $err = substr((string)$e->getMessage(), 0, 220);
    fatture_log($pdo, $id, 'send_einvoice', 'error', 'Invio SDI: configurazione FIC non valida. ' . substr((string)$e->getMessage(), 0, 160));
    header('Location: ' . fatture_ei_build_return_url($baseUrl, $returnPath, [
        'ei' => 'error',
        'ei_id' => (int)$id,
        'ei_msg' => $err !== '' ? $err : 'Configurazione FIC non valida',
    ]));
    exit;
}

$righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
$righeStmt->execute([':id' => $id]);
$righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

try {
    fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'manuale_singolo');
    header('Location: ' . fatture_ei_build_return_url($baseUrl, $returnPath, [
        'ei' => 'ok',
        'ei_id' => (int)$id,
        'num' => (string)($fattura['numero'] ?? ''),
    ]));
    exit;
} catch (Throwable $e) {
    $err = substr((string)$e->getMessage(), 0, 220);
    if (fatture_ei_single_is_duplicate_not_recoverable($err)) {
        fatture_ei_single_mark_duplicate_unresolved($pdo, $id, $err);
    }

    fatture_log($pdo, $id, 'send_einvoice', 'error', 'Errore invio SDI singolo: ' . substr((string)$e->getMessage(), 0, 180));
    header('Location: ' . fatture_ei_build_return_url($baseUrl, $returnPath, [
        'ei' => 'error',
        'ei_id' => (int)$id,
        'num' => (string)($fattura['numero'] ?? ''),
        'ei_msg' => $err !== '' ? $err : 'Errore invio SDI',
    ]));
    exit;
}
