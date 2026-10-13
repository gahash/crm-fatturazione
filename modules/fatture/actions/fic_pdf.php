<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';

$id = (int)($_GET['id'] ?? 0);
$downloadMode = isset($_GET['download']) && $_GET['download'] == '1';
if ($id <= 0) {
	http_response_code(400);
	echo 'ID non valido.';
	exit;
}

$stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
	http_response_code(404);
	echo 'Fattura non trovata.';
	exit;
}

$cfg = fic_config();
$companyId = $cfg['company_id'] ?? '';
if ($companyId === '') {
	$companyId = (string)fic_get_company_id();
}
if ($companyId === '') {
	http_response_code(500);
	echo 'Fatture in Cloud: company_id mancante.';
	exit;
}

// Se già abbiamo il PDF URL salvato, usalo direttamente
if (!empty($fattura['fic_pdf_url'])) {
	header('Location: ' . $fattura['fic_pdf_url']);
	exit;
}

// LOGICA SEMPLIFICATA: La fattura dovrebbe già esistere su FIC
$docId = $fattura['fic_document_id'];
$docType = $fattura['document_type'] ?? 'invoice';

// Se abbiamo un docId locale ma non è più raggiungibile su FIC, azzera e prova ricerca/ricreazione
if ($docId) {
	$docStatus = fic_get_document_status($companyId, (string)$docId);
	if ($docStatus === null) {
		$clear = $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_pdf_url = NULL WHERE id = :id");
		$clear->execute([':id' => $id]);
		$docId = null;
	}
}

// Se non abbiamo fic_document_id, cerca il documento esistente su FIC per numero
if (!$docId) {
	[$docId, $status] = fic_find_existing_document_by_number($companyId, $docType, $fattura);
	
	if ($docId) {
		// Trovato! Salva l'ID nel DB
		$upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
		$upd->execute([
			':doc_id' => $docId,
			':status' => $status,
			':id' => $id,
		]);
	} else {
		// Non trovato su FIC: crealo solo come ultima risorsa
		$righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
		$righeStmt->execute([':id' => $id]);
		$righe = $righeStmt->fetchAll();

		[$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
		if (!$docId) {
			http_response_code(502);
			echo 'Fatture in Cloud: impossibile creare il documento.';
			exit;
		}
	}
}

// Ora abbiamo sicuramente un docId: verifica stato e pubblica se draft
fic_publish_document_if_draft($companyId, (string)$docId);

// Scarica il PDF da FIC
try {
	[$pdfUrl, $pdfBody] = fic_fetch_pdf_with_wait($companyId, (string)$docId, 10, 2000);
	
	// Salva l'URL del PDF per usi futuri
	if ($pdfUrl !== '') {
		$upd = $pdo->prepare("UPDATE fatture SET fic_pdf_url = :url, fic_synced_at = NOW() WHERE id = :id");
		$upd->execute([':url' => $pdfUrl, ':id' => $id]);
	}
	
	// Output PDF
	$filename = 'fattura_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fattura['numero']) . '.pdf';
	header('Content-Type: application/pdf');
	header('Content-Disposition: ' . ($downloadMode ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
	if ($pdfBody !== '') {
		header('Content-Length: ' . strlen($pdfBody));
		echo $pdfBody;
	}
	exit;
	
} catch (Throwable $e) {
	$errorMessage = (string)$e->getMessage();
	$retry = max(0, (int)($_GET['retry'] ?? 0));
	$maxRetry = 18;
	$isDocNotReady = strpos($errorMessage, 'FIC_DOC_NOT_FOUND:') === 0;

	if ($isDocNotReady && $retry < $maxRetry) {
		$nextRetry = $retry + 1;
		$basePath = '/modules/fatture/actions/fic_pdf.php?id=' . (int)$id;
		$basePath .= $downloadMode ? '&download=1' : '';
		$refreshUrl = $basePath . '&retry=' . $nextRetry;

		usleep(3000000);
		header('Location: ' . $refreshUrl, true, 302);
		exit;
	}

	// Dopo tutti i tentativi, mostra fallback manuale
	http_response_code(502);
	echo '<h1>PDF temporaneamente non disponibile</h1>';
	echo '<p>Il documento esiste su Fatture in Cloud ma il PDF non è ancora pronto.</p>';
	echo '<p>Riprova tra qualche minuto oppure <a href="https://secure.fattureincloud.it/azienda-' . htmlspecialchars($companyId) . '/documenti/fatture/emesse/' . htmlspecialchars((string)$docId) . '" target="_blank">visualizzalo direttamente su Fatture in Cloud</a>.</p>';
	echo '<p>Errore: ' . htmlspecialchars($errorMessage) . '</p>';
	exit;
}