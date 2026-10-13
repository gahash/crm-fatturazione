<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';
require_once __DIR__ . '/../../impostazioni/mail_smtp.php';

function fic_push_build_return_url(string $baseUrl, string $returnPath, array $params = []): string
{
	$returnPath = trim($returnPath);
	if ($returnPath === '' || strpos($returnPath, '://') !== false || strpos($returnPath, '//') === 0 || strpos($returnPath, '..') !== false) {
		$returnPath = 'modules/fatture/index.php';
	}

	$returnPath = ltrim($returnPath, '/');
	if (strpos($returnPath, 'modules/') !== 0) {
		$returnPath = 'modules/fatture/' . ltrim($returnPath, '/');
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
$returnPath = (string)($_GET['return'] ?? 'modules/fatture/index.php');

if ($id <= 0) {
	header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, ['fic' => 'error']));
	exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fattura) {
	header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, ['fic' => 'error']));
	exit;
}

$cfg = fic_config();
$cfg['allow_duplicate_force_create'] = true; // solo sync singolo manuale
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
	$companyId = (string)fic_get_company_id();
}

if ($companyId === '') {
	fatture_log($pdo, $id, 'fic_sync', 'error', 'Sincronizzazione FIC: company_id mancante.');
	header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, ['fic' => 'error']));
	exit;
}

$righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
$righeStmt->execute([':id' => $id]);
$righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

try {
	[$docId, $status] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);

	if (!$docId) {
		throw new RuntimeException('Documento FIC non creato.');
	}

	fic_publish_document_if_draft($companyId, (string)$docId);
	fatture_log($pdo, $id, 'fic_sync', 'ok', 'Sincronizzazione FIC completata.', [
		'fic_document_id' => $docId,
		'fic_status' => $status,
	]);

	// Tentativo di invio email con PDF allegato
	$mailStatus = 'ok';
	$mailError = null;

	$mailPath = __DIR__ . '/../../../config/mail.php';
	if (!file_exists($mailPath)) {
		$mailStatus = 'missing_config';
		$mailError = 'Configurazione SMTP mancante.';
	} else {
		try {
			$cfgMail = require $mailPath;
			fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'auto');
			$mailStatus = 'sent';
			fatture_log($pdo, $id, 'send_mail', 'ok', 'Email con PDF inviata automaticamente dopo sincronizzazione FIC.', [
				'numero' => $fattura['numero'] ?? null,
				'email' => $fattura['email'] ?? null,
			]);
		} catch (Throwable $mailE) {
			$mailStatus = 'error';
			$mailError = substr($mailE->getMessage(), 0, 500);
			fatture_log($pdo, $id, 'send_mail', 'error', 'Invio email fallito dopo sincronizzazione FIC: ' . $mailError);
		}
	}

	// Redirect con stato mail + FIC ok
	$params = ['fic' => 'ok'];
	if ($mailStatus !== 'ok') {
		$params['mail'] = $mailStatus;
	}

	header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, $params));
	exit;
} catch (Throwable $e) {
	$msg = (string)$e->getMessage();

	if (strpos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0) {
		$num = substr($msg, strlen('FIC_DUPLICATE_NOT_RECOVERABLE:'));
		$num = $num !== '' ? $num : ((string)($fattura['numero'] ?? 'N/A'));
		fatture_log($pdo, $id, 'fic_sync', 'error', 'Documento duplicato su FIC non recuperabile.', ['numero' => $num]);
		header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, ['fic' => 'duplicate', 'num' => $num]));
		exit;
	}

	fatture_log($pdo, $id, 'fic_sync', 'error', 'Errore sincronizzazione FIC: ' . substr($msg, 0, 180));
	header('Location: ' . fic_push_build_return_url($baseUrl, $returnPath, ['fic' => 'error']));
	exit;
}


