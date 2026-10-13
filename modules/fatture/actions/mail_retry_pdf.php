<?php
// Script di retry invio email con PDF allegato per fatture non ancora inviate correttamente
require_once __DIR__ . '/../../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_mail_helpers.php';

$maxRetry = 10; // massimo 10 fatture per run
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare("SELECT f.*, c.email, c.nome, c.azienda FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.mail_status != 'sent' AND f.fic_document_id IS NOT NULL AND f.data_emissione >= DATE_SUB(NOW(), INTERVAL 90 DAY) ORDER BY f.id DESC LIMIT :max");
$stmt->bindValue(':max', $maxRetry, PDO::PARAM_INT);
$stmt->execute();
$fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cfg = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}
$mailPath = __DIR__ . '/../../../config/mail.php';
if (!file_exists($mailPath)) {
    throw new RuntimeException('Configurazione SMTP mancante');
}
$cfgMail = require $mailPath;

$sent = 0;
$failed = 0;
foreach ($fatture as $fattura) {
    $fatturaId = (int)$fattura['id'];
    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
    $righeStmt->execute([':id' => $fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'retry');
        $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err, mail_sent_at = NOW() WHERE id = :id');
        $upd->execute([
            ':status' => 'sent',
            ':err' => null,
            ':id' => $fatturaId,
        ]);
        $sent++;
    } catch (Throwable $e) {
        $upd = $pdo->prepare('UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id');
        $upd->execute([
            ':status' => 'error',
            ':err' => substr($e->getMessage(), 0, 1000),
            ':id' => $fatturaId,
        ]);
        $failed++;
    }
}
// Output JSON se richiesta AJAX o Accept JSON, altrimenti testo semplice
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);
if ($isAjax) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => true,
        'sent' => $sent,
        'failed' => $failed,
        'message' => "Retry completato. Inviate: $sent, fallite: $failed"
    ]);
} else {
    echo "Retry completato. Inviate: $sent, fallite: $failed\n";
}
