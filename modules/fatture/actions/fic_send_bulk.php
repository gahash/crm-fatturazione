<?php
require __DIR__ . '/../../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';
@set_time_limit(0);
@ini_set('max_execution_time', '0');

function fatture_bulk_update_mail_tracking(PDO $pdo, int $id, string $status, ?string $error, bool $setSentAt): void
{
    try {
        $sql = "UPDATE fatture SET " . ($setSentAt ? "mail_sent_at = NOW(), " : "") . "mail_status = :status, mail_error = :err WHERE id = :id";
        $upd = $pdo->prepare($sql);
        $upd->execute([
            ':status' => $status,
            ':err' => $error,
            ':id' => $id,
        ]);
    } catch (Throwable $e) {
    }
}

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$docType = isset($_GET['document_type']) ? trim((string)$_GET['document_type']) : 'invoice';
$mode = trim((string)($_GET['mode'] ?? $_POST['mode'] ?? ''));
$isPublicLinkResend = $mode === 'public_link';
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_filter(array_map('intval', $ids), static function ($v) {
    return $v > 0;
}));

$params = [':docType' => $docType];
$where = " WHERE f.document_type = :docType";
if (!empty($ids)) {
    $placeholders = [];
    foreach ($ids as $idx => $id) {
        $key = ':id' . $idx;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $where .= " AND f.id IN (" . implode(', ', $placeholders) . ")";
} elseif ($q !== '') {
    $where .= " AND (f.numero LIKE :q OR c.nome LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id" . $where . " ORDER BY f.data_emissione DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$mailPath = __DIR__ . '/../../../config/mail.php';
if (!file_exists($mailPath)) {
    fatture_log($pdo, null, 'send_mail_bulk', 'error', 'Invio massivo: configurazione SMTP mancante.');
    header("Location: {$baseUrl}/modules/fatture/index.php?mail_bulk_error=missing_config&mail_bulk_mode=" . urlencode($mode));
    exit;
}
$cfgMail = require $mailPath;

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
    fatture_log($pdo, null, 'send_mail_bulk', 'error', 'Invio massivo: configurazione FIC non valida. ' . substr((string)$e->getMessage(), 0, 180));
    header("Location: {$baseUrl}/modules/fatture/index.php?mail_bulk_error=fic_config&mail_bulk_mode=" . urlencode($mode));
    exit;
}

$ok = 0;
$failed = 0;
$skipped = 0;

while ($fattura = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $wasAlreadySent = !empty($fattura['mail_status']) && $fattura['mail_status'] === 'sent';

    if (empty($fattura['email']) || !filter_var((string)$fattura['email'], FILTER_VALIDATE_EMAIL)) {
        fatture_log($pdo, (int)$fattura['id'], 'send_mail_bulk', 'error', 'Saltata: email cliente non valida o mancante.');
        $skipped++;
        continue;
    }

    $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
    $righeStmt->execute([':id' => $fattura['id']]);
    $righe = $righeStmt->fetchAll();

    try {
        if ($isPublicLinkResend) {
            fic_send_invoice_email_public_link($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale_public_link');
        } else {
            fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale');
        }
        fatture_bulk_update_mail_tracking($pdo, (int)$fattura['id'], 'sent', null, true);
        $successMessage = $isPublicLinkResend
            ? ($wasAlreadySent ? 'Reinvio email con link pubblico completato.' : 'Invio email con link pubblico completato.')
            : ($wasAlreadySent ? 'Reinvio email completato.' : 'Invio email completato.');

        fatture_log($pdo, (int)$fattura['id'], 'send_mail_bulk', 'ok', $successMessage, [
            'numero' => $fattura['numero'] ?? null,
            'email' => $fattura['email'] ?? null,
            'resend' => $wasAlreadySent,
            'mode' => $mode,
        ]);
        $ok++;
    } catch (Throwable $e) {
        $err = substr($e->getMessage(), 0, 1000);
        fatture_bulk_update_mail_tracking($pdo, (int)$fattura['id'], 'error', $err, false);
        fatture_log($pdo, (int)$fattura['id'], 'send_mail_bulk', 'error', 'Errore invio massivo: ' . substr($err, 0, 180));
        $failed++;
    }
}

fatture_log($pdo, null, 'send_mail_bulk', $failed > 0 ? 'error' : 'ok', 'Invio massivo completato.', [
    'ok' => $ok,
    'failed' => $failed,
    'skipped' => $skipped,
    'filtro' => $q,
    'document_type' => $docType,
    'mode' => $mode,
]);

$redirect = "{$baseUrl}/modules/fatture/index.php?mail_bulk_ok={$ok}&mail_bulk_failed={$failed}&mail_bulk_skipped={$skipped}&mail_bulk_mode=" . urlencode($mode);
if ($q !== '') {
    $redirect .= '&q=' . urlencode($q);
}
header('Location: ' . $redirect);
exit;

