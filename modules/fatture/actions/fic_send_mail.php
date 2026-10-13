<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
ob_start();
require_once __DIR__ . '/fic_mail_helpers.php';
$helperBootOutput = trim((string)ob_get_clean());
if ($helperBootOutput !== '') {
	error_log('Output inatteso da fic_mail_helpers.php: ' . substr($helperBootOutput, 0, 300));
}
require_once __DIR__ . '/fic_audit.php';

function fatture_single_update_mail_tracking(PDO $pdo, int $id, string $status, ?string $error, bool $setSentAt): void
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

function fatture_build_return_url(string $baseUrl, string $returnPath, array $params = []): string
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

$appConfig = require __DIR__ . '/../../../config/config.php';
$baseUrl = rtrim($appConfig['app']['base_url'] ?? 'http://localhost:8000', '/');

$id = (int)($_GET['id'] ?? 0);
$returnPath = (string)($_GET['return'] ?? 'index.php');

if ($id <= 0) {
	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'error']));
	exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'error']));
	exit;
}

$mailPath = __DIR__ . '/../../../config/mail.php';
if (!file_exists($mailPath)) {
	fatture_single_update_mail_tracking($pdo, $id, 'error', 'Configurazione SMTP mancante.', false);
	fatture_log($pdo, $id, 'send_mail', 'error', 'Invio singolo: configurazione SMTP mancante.');
	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'missing_config']));
	exit;
}

$cfgMail = require $mailPath;
$cfg = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
	$companyId = (string)fic_get_company_id();
}

if ($companyId === '') {
	$err = 'company_id Fatture in Cloud mancante.';
	fatture_single_update_mail_tracking($pdo, $id, 'error', $err, false);
	fatture_log($pdo, $id, 'send_mail', 'error', 'Invio singolo: company_id FIC mancante.');
	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'error']));
	exit;
}

$righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
$righeStmt->execute([':id' => $id]);
$righe = $righeStmt->fetchAll();

$wasAlreadySent = !empty($fattura['mail_status']) && $fattura['mail_status'] === 'sent';

try {
	fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale');
	fatture_single_update_mail_tracking($pdo, $id, 'sent', null, true);
	fatture_log($pdo, $id, 'send_mail', 'ok', $wasAlreadySent ? 'Reinvio email completato.' : 'Invio email completato.', [
		'numero' => $fattura['numero'] ?? null,
		'email' => $fattura['email'] ?? null,
		'resend' => $wasAlreadySent,
	]);

	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'ok', 'fic' => 'ok']));
	exit;
} catch (Throwable $e) {
	$msg = (string)$e->getMessage();

	if (strpos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0) {
		$num = substr($msg, strlen('FIC_DUPLICATE_NOT_RECOVERABLE:'));
		$num = $num !== '' ? $num : ((string)($fattura['numero'] ?? 'N/A'));

		fatture_single_update_mail_tracking($pdo, $id, 'error', 'Documento duplicato su FIC con numero ' . $num . '.', false);
		fatture_log($pdo, $id, 'send_mail', 'error', 'Documento duplicato su FIC non recuperabile.', [
			'numero' => $num,
		]);

		header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['fic' => 'duplicate', 'num' => $num, 'mail' => 'error']));
		exit;
	}

	$err = substr($msg, 0, 1000);
	fatture_single_update_mail_tracking($pdo, $id, 'error', $err, false);
	fatture_log($pdo, $id, 'send_mail', 'error', 'Errore invio singolo: ' . substr($err, 0, 180));

	header('Location: ' . fatture_build_return_url($baseUrl, $returnPath, ['mail' => 'error']));
	exit;
}

