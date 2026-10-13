<?php
require __DIR__ . '/../../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../../config/db.php';
$secret = require __DIR__ . '/../../../config/secret.php';

$mode = (string)($_GET['mode'] ?? 'redirect');
$isLinkMode = $mode === 'link';

function stripe_checkout_respond_json(int $status, array $payload): void
{
	header('Content-Type: application/json; charset=utf-8');
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

$stripe = $secret['stripe'] ?? [];
$secretKey = $stripe['secret_key'] ?? '';
$publicUrl = rtrim($stripe['public_url'] ?? '', '/');

if ($secretKey === '' || $publicUrl === '') {
	if ($isLinkMode) {
		stripe_checkout_respond_json(500, ['ok' => false, 'error' => 'Stripe: configurazione mancante.']);
	}
	http_response_code(500);
	echo 'Stripe: configurazione mancante.';
	exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
	if ($isLinkMode) {
		stripe_checkout_respond_json(400, ['ok' => false, 'error' => 'ID non valido.']);
	}
	http_response_code(400);
	echo 'ID non valido.';
	exit;
}

$stmt = $pdo->prepare("SELECT id, numero, totale, stato FROM fatture WHERE id = :id");
$stmt->execute([':id' => $id]);
$fattura = $stmt->fetch();

if (!$fattura) {
	if ($isLinkMode) {
		stripe_checkout_respond_json(404, ['ok' => false, 'error' => 'Fattura non trovata.']);
	}
	http_response_code(404);
	echo 'Fattura non trovata.';
	exit;
}

if (($fattura['stato'] ?? '') === 'pagata') {
	if ($isLinkMode) {
		stripe_checkout_respond_json(409, ['ok' => false, 'error' => 'La fattura risulta gia pagata.']);
	}
	header('Location: ../index.php?stripe=paid');
	exit;
}

$amount = (int)round(((float)$fattura['totale']) * 100);
if ($amount <= 0) {
	if ($isLinkMode) {
		stripe_checkout_respond_json(400, ['ok' => false, 'error' => 'Importo non valido.']);
	}
	http_response_code(400);
	echo 'Importo non valido.';
	exit;
}

$payload = http_build_query([
	'mode' => 'payment',
	'payment_method_types[]' => 'card',
	'line_items[0][price_data][currency]' => 'eur',
	'line_items[0][price_data][product_data][name]' => 'Fattura #' . $fattura['numero'],
	'line_items[0][price_data][unit_amount]' => $amount,
	'line_items[0][quantity]' => 1,
	'success_url' => $publicUrl . '/modules/fatture/index.php?stripe=success&id=' . $fattura['id'],
	'cancel_url' => $publicUrl . '/modules/fatture/index.php?stripe=cancel&id=' . $fattura['id'],
	'metadata[fattura_id]' => $fattura['id'],
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	'Authorization: Bearer ' . $secretKey,
	'Content-Type: application/x-www-form-urlencoded',
]);

$response = curl_exec($ch);
if ($response === false) {
	$err = curl_error($ch);
	curl_close($ch);
	if ($isLinkMode) {
		stripe_checkout_respond_json(502, ['ok' => false, 'error' => 'Stripe: errore cURL: ' . $err]);
	}
	http_response_code(502);
	echo 'Stripe: errore cURL: ' . $err;
	exit;
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status < 200 || $status >= 300) {
	if ($isLinkMode) {
		stripe_checkout_respond_json(502, ['ok' => false, 'error' => 'Stripe: risposta non valida.']);
	}
	http_response_code(502);
	echo 'Stripe: risposta non valida. ' . $response;
	exit;
}

$data = json_decode($response, true);
$sessionId = $data['id'] ?? null;
$checkoutUrl = $data['url'] ?? null;

if (!$sessionId || !$checkoutUrl) {
	if ($isLinkMode) {
		stripe_checkout_respond_json(502, ['ok' => false, 'error' => 'Stripe: risposta incompleta.']);
	}
	http_response_code(502);
	echo 'Stripe: risposta incompleta.';
	exit;
}

$upd = $pdo->prepare("UPDATE fatture SET stripe_session_id = :sid, stripe_checkout_url = :url WHERE id = :id");
$upd->execute([
	':sid' => $sessionId,
	':url' => $checkoutUrl,
	':id' => $fattura['id'],
]);

if ($isLinkMode) {
	stripe_checkout_respond_json(200, [
		'ok' => true,
		'fattura_id' => (int)$fattura['id'],
		'numero' => (string)$fattura['numero'],
		'checkout_url' => (string)$checkoutUrl,
	]);
}

header('Location: ' . $checkoutUrl);
exit;

