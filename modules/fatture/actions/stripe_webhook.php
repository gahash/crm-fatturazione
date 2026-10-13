<?php
$pdo = require __DIR__ . '/../../../config/db.php';
$secret = require __DIR__ . '/../../../config/secret.php';
require_once __DIR__ . '/../../../config/stripe_customer_links.php';
require_once __DIR__ . '/../../dashboard/cache_utils.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$dashboardCacheShouldClear = false;

$stripe = $secret['stripe'] ?? [];
$webhookSecret = $stripe['webhook_secret'] ?? '';
if ($webhookSecret === '') {
    http_response_code(500);
    echo 'Webhook secret mancante.';
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

function stripe_verify_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    if ($sigHeader === '') {
        return false;
    }

    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = [];

    foreach ($parts as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $timestamp = (int)$kv[1];
        } elseif ($kv[0] === 'v1') {
            $signatures[] = $kv[1];
        }
    }

    if (!$timestamp || empty($signatures)) {
        return false;
    }

    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

if (!stripe_verify_signature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    echo 'Firma non valida.';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo 'Evento non valido.';
    exit;
}

$eventType = $event['type'];

// ---------- charge.succeeded: importa pagamento + cliente + fattura automatica ----------
if ($eventType === 'charge.succeeded') {
    $charge = $event['data']['object'] ?? [];
    $stripeId = (string)($charge['id'] ?? '');
    $amount = (int)($charge['amount'] ?? 0);
    $currency = strtoupper((string)($charge['currency'] ?? 'EUR'));
    $createdTs = (int)($charge['created'] ?? time());
    $description = (string)($charge['description'] ?? 'Pagamento Stripe');
    $metodo = (string)($charge['payment_method_details']['type'] ?? 'card');
    $stripeCustomerId = (string)($charge['customer'] ?? '');

    $clienteId = 0;

    // Cerca/crea cliente
    if ($stripeCustomerId !== '') {
        try {
            $clienteId = tdg_find_cliente_id_by_stripe_customer($pdo, $stripeCustomerId);
        } catch (Throwable $e) {}
    }

    // Estrai dati dal billing_details della charge
    $billing = $charge['billing_details'] ?? [];
    $chMeta = $charge['metadata'] ?? [];
    $email = strtolower(trim($billing['email'] ?? ''));
    $nome = trim($billing['name'] ?? '');

    if ($clienteId <= 0 && ($email !== '' || $nome !== '')) {
        // Match per email
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $clienteId = (int)$row['id'];
        }
        // Match per nome
        if ($clienteId <= 0 && $nome !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(nome)) = LOWER(?) OR LOWER(TRIM(azienda)) = LOWER(?) LIMIT 1");
            $stmt->execute([$nome, $nome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $clienteId = (int)$row['id'];
        }
        // Crea nuovo cliente
        if ($clienteId <= 0) {
            $nomeIns = $nome !== '' ? $nome : 'Cliente Stripe';
            $stmt = $pdo->prepare("INSERT INTO clienti (nome, email, note, creato_il) VALUES (?,?,?,NOW())");
            $stmt->execute([$nomeIns, $email !== '' ? $email : null, 'Importato da webhook charge.succeeded']);
            $clienteId = (int)$pdo->lastInsertId();
        }
    }

    // Aggiorna campi vuoti del cliente da metadata
    if ($clienteId > 0) {
        $metaPick = function(array $meta, array $keys): string {
            foreach ($keys as $k) { $v = trim((string)($meta[$k] ?? '')); if ($v !== '') return $v; }
            return '';
        };
        $piva = $metaPick($chMeta, ['piva','partita_iva','vat_number','tax_id']);
        $sdi = $metaPick($chMeta, ['codice_sdi','sdi','recipient_code']);
        $pec = $metaPick($chMeta, ['pec','certified_email']);
        $azienda = $metaPick($chMeta, ['azienda','company','company_name','ragione_sociale']);

        tdg_cliente_sync_apply_updates($pdo, $clienteId, [
            'piva' => $piva,
            'codice_sdi' => $sdi,
            'pec' => $pec,
            'azienda' => $azienda,
        ]);
    }

    // Inserisci pagamento
    if ($stripeId !== '') {
        try {
            $createdAt = date('Y-m-d H:i:s', $createdTs > 0 ? $createdTs : time());
            $ins = $pdo->prepare("INSERT INTO pagamenti (stripe_id, cliente_id, importo, valuta, stato, metodo, descrizione, created_at)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    cliente_id = COALESCE(NULLIF(VALUES(cliente_id), 0), cliente_id),
                    importo = VALUES(importo),
                    valuta = VALUES(valuta),
                    stato = VALUES(stato),
                    metodo = VALUES(metodo),
                    descrizione = VALUES(descrizione),
                    created_at = VALUES(created_at)");
            $ins->execute([$stripeId, $clienteId > 0 ? $clienteId : null, $amount, $currency, 'succeeded', $metodo, $description, $createdAt]);
        } catch (Throwable $e) {}

        // Crea fattura automatica se requisiti OK
        if ($clienteId > 0) {
            // Check requisiti
            $cStmt = $pdo->prepare("SELECT email, piva, azienda, nome FROM clienti WHERE id = ? LIMIT 1");
            $cStmt->execute([$clienteId]);
            $c = $cStmt->fetch(PDO::FETCH_ASSOC);
            $hasEmail = !empty($c['email']) && filter_var($c['email'], FILTER_VALIDATE_EMAIL);
            $hasPiva = !empty(trim($c['piva'] ?? ''));
            $hasAnagrafica = trim($c['azienda'] ?? '') !== '' || trim($c['nome'] ?? '') !== '';

            // Verifica che non esista già fattura
            $existCheck = $pdo->prepare("SELECT id FROM fatture WHERE stripe_payment_intent_id = ? LIMIT 1");
            $existCheck->execute([$stripeId]);
            if (!$existCheck->fetchColumn()) {
                $totale = $amount / 100;
                $dataEmissione = date('Y-m-d', $createdTs);
                $dataPagamento = date('Y-m-d H:i:s', $createdTs);

                // Genera numero fattura
                $day = date('Ymd', $createdTs);
                $pfx = 'STR-INV-' . $day . '-';
                $numStmt = $pdo->prepare("SELECT numero FROM fatture WHERE document_type='invoice' AND numero LIKE ? ORDER BY numero DESC LIMIT 1");
                $numStmt->execute([$pfx . '%']);
                $lastNum = $numStmt->fetchColumn();
                $prog = 1;
                if ($lastNum && preg_match('/STR-INV-\d{8}-(\d+)/', (string)$lastNum, $m)) $prog = (int)$m[1] + 1;
                $numero = sprintf('STR-INV-%s-%03d', $day, $prog);

                $ins = $pdo->prepare("INSERT INTO fatture (cliente_id, numero, data_emissione, stato, totale, note, stripe_payment_intent_id, document_type, metodo_pagamento, data_pagamento, creato_il)
                    VALUES (?, ?, ?, 'pagata', ?, ?, ?, 'invoice', ?, ?, ?)");
                $ins->execute([$clienteId, $numero, $dataEmissione, $totale,
                    "Fattura automatica da webhook Stripe\n\n" . $description,
                    $stripeId, 'Stripe - ' . $metodo, $dataPagamento, $dataPagamento]);
                $newFatturaId = (int)$pdo->lastInsertId();

                if ($newFatturaId > 0) {
                    $pdo->prepare("INSERT INTO fatture_righe (fattura_id, descrizione, quantita, prezzo_unitario, iva, totale_riga) VALUES (?,?,1,?,0,?)")
                        ->execute([$newFatturaId, $description, $totale, $totale]);

                    if ($hasEmail && $hasPiva && $hasAnagrafica) {
                        // Auto-send
                        try {
                            $mailPath = __DIR__ . '/../../../config/mail.php';
                            if (file_exists($mailPath)) {
                                $cfgMail = require $mailPath;
                                $cfg = fic_config();
                                $companyId = (string)($cfg['company_id'] ?? '');
                                if ($companyId === '') $companyId = (string)fic_get_company_id();

                                if ($companyId !== '') {
                                    $righe = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id");
                                    $righe->execute([$newFatturaId]);
                                    $righe = $righe->fetchAll(PDO::FETCH_ASSOC);

                                    $fStmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
                                    $fStmt->execute([$newFatturaId]);
                                    $fattura = $fStmt->fetch(PDO::FETCH_ASSOC);

                                    fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'webhook');
                                    $pdo->prepare("UPDATE fatture SET mail_status = 'sent', mail_error = NULL, mail_sent_at = NOW() WHERE id = ?")
                                        ->execute([$newFatturaId]);
                                    fatture_log($pdo, $newFatturaId, 'webhook_auto_send', 'ok', 'Fattura inviata automaticamente da webhook charge.succeeded.');
                                }
                            }
                        } catch (Throwable $e) {
                            $pdo->prepare("UPDATE fatture SET mail_status = 'error', mail_error = ? WHERE id = ?")
                                ->execute([substr($e->getMessage(), 0, 1000), $newFatturaId]);
                        }
                    } else {
                        $missing = [];
                        if (!$hasEmail) $missing[] = 'email';
                        if (!$hasPiva) $missing[] = 'P.IVA';
                        if (!$hasAnagrafica) $missing[] = 'anagrafica';
                        $pdo->prepare("UPDATE fatture SET mail_status = 'pending', mail_error = ? WHERE id = ?")
                            ->execute(['Anagrafica incompleta: ' . implode(', ', $missing), $newFatturaId]);
                    }
                }
            }
        }

        if ($clienteId > 0 && $stripeCustomerId !== '') {
            tdg_upsert_stripe_customer_link($pdo, $clienteId, $stripeCustomerId, $email, $nome, 'stripe_webhook_charge');
        }
    }

    $dashboardCacheShouldClear = true;
}

// ---------- customer.created / customer.updated: sincronizza anagrafica ----------
if ($eventType === 'customer.created' || $eventType === 'customer.updated') {
    $cust = $event['data']['object'] ?? [];
    $stripeCustomerId = (string)($cust['id'] ?? '');
    $custEmail = strtolower(trim($cust['email'] ?? ''));
    $custName = trim($cust['name'] ?? '');
    $custPhone = trim($cust['phone'] ?? '');
    $custMeta = $cust['metadata'] ?? [];
    $custAddress = [];
    if (!empty($cust['address']) && is_array($cust['address'])) {
        $custAddress = $cust['address'];
    }
    $custAddressText = '';
    foreach (['line1', 'line2', 'postal_code', 'city', 'state', 'country'] as $key) {
        $value = trim((string)($custAddress[$key] ?? ''));
        if ($value !== '') {
            $custAddressText .= ($custAddressText !== '' ? ', ' : '') . $value;
        }
    }

    $metaPick = function(array $meta, array $keys): string {
        foreach ($keys as $k) { $v = trim((string)($meta[$k] ?? '')); if ($v !== '') return $v; }
        return '';
    };

    $clienteId = 0;

    // Cerca da stripe_sync
    if ($stripeCustomerId !== '') {
        try {
            $clienteId = tdg_find_cliente_id_by_stripe_customer($pdo, $stripeCustomerId);
        } catch (Throwable $e) {}
    }

    // Cerca per email
    if ($clienteId <= 0 && $custEmail !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        $stmt->execute([$custEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $clienteId = (int)$row['id'];
    }

    // Crea se nuovo
    if ($clienteId <= 0 && $eventType === 'customer.created') {
        $nomeIns = $custName !== '' ? $custName : 'Cliente Stripe';
        $stmt = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, note, creato_il) VALUES (?,?,?,?,NOW())");
        $stmt->execute([$nomeIns, $custEmail !== '' ? $custEmail : null, $custPhone !== '' ? $custPhone : null, 'Importato da webhook customer.created']);
        $clienteId = (int)$pdo->lastInsertId();
    }

    // Aggiorna campi da metadata
    if ($clienteId > 0) {
        $piva = $metaPick($custMeta, ['piva','partita_iva','vat_number','tax_id']);
        $sdi = $metaPick($custMeta, ['codice_sdi','sdi','recipient_code']);
        $pec = $metaPick($custMeta, ['pec','certified_email']);
        $azienda = $metaPick($custMeta, ['azienda','company','company_name','ragione_sociale']);

        $updated = tdg_cliente_sync_apply_updates($pdo, $clienteId, [
            'email' => $custEmail, 'nome' => $custName, 'telefono' => $custPhone,
            'piva' => $piva, 'codice_sdi' => $sdi, 'pec' => $pec, 'azienda' => $azienda,
            'indirizzo' => $custAddressText,
        ]);

        // Aggiorna stripe_sync
        if ($stripeCustomerId !== '') {
            try {
                $pdo->prepare("INSERT INTO stripe_sync (cliente_id, stripe_customer_id, stripe_email, stripe_name, synced_at)
                    VALUES (?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE stripe_email = VALUES(stripe_email), stripe_name = VALUES(stripe_name), synced_at = NOW()")
                    ->execute([$clienteId, $stripeCustomerId, $custEmail, $custName]);
                tdg_upsert_stripe_customer_link($pdo, $clienteId, $stripeCustomerId, $custEmail, $custName, 'stripe_webhook_customer');
            } catch (Throwable $e) {}
        }

        // Se customer.updated, controlla fatture pending e riprova invio
        if ($eventType === 'customer.updated') {
            try {
                $pendingStmt = $pdo->prepare("SELECT f.id FROM fatture f WHERE f.cliente_id = ? AND f.mail_status = 'pending' AND f.document_type = 'invoice' LIMIT 10");
                $pendingStmt->execute([$clienteId]);
                $pendingFatture = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($pendingFatture)) {
                    // Richeck requisiti
                    $cCheck = $pdo->prepare("SELECT email, piva, azienda, nome FROM clienti WHERE id = ? LIMIT 1");
                    $cCheck->execute([$clienteId]);
                    $cl = $cCheck->fetch(PDO::FETCH_ASSOC);
                    $hasEmail = !empty($cl['email']) && filter_var($cl['email'], FILTER_VALIDATE_EMAIL);
                    $hasPiva = !empty(trim($cl['piva'] ?? ''));
                    $hasAnagrafica = trim($cl['azienda'] ?? '') !== '' || trim($cl['nome'] ?? '') !== '';

                    if ($hasEmail && $hasPiva && $hasAnagrafica) {
                        $mailPath = __DIR__ . '/../../../config/mail.php';
                        if (file_exists($mailPath)) {
                            $cfgMail = require $mailPath;
                            $cfg = fic_config();
                            $companyId = (string)($cfg['company_id'] ?? '') ?: (string)fic_get_company_id();

                            foreach ($pendingFatture as $pendingId) {
                                try {
                                    $fStmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
                                    $fStmt->execute([$pendingId]);
                                    $fattura = $fStmt->fetch(PDO::FETCH_ASSOC);
                                    $righe = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id");
                                    $righe->execute([$pendingId]);
                                    $righe = $righe->fetchAll(PDO::FETCH_ASSOC);

                                    fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'webhook_retry');
                                    $pdo->prepare("UPDATE fatture SET mail_status = 'sent', mail_error = NULL, mail_sent_at = NOW() WHERE id = ?")
                                        ->execute([$pendingId]);
                                    fatture_log($pdo, (int)$pendingId, 'webhook_retry_send', 'ok', 'Fattura inviata dopo aggiornamento anagrafica cliente.');
                                } catch (Throwable $e) {
                                    // ignora singolo errore, riproverà al prossimo giro
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {}
        }
    }

    $dashboardCacheShouldClear = true;
}

// ---------- charge.refunded: aggiorna stato pagamento ----------
if ($eventType === 'charge.refunded') {
    $charge = $event['data']['object'] ?? [];
    $stripeId = (string)($charge['id'] ?? '');
    if ($stripeId !== '') {
        try {
            $pdo->prepare("UPDATE pagamenti SET stato = 'refunded' WHERE stripe_id = ?")->execute([$stripeId]);
        } catch (Throwable $e) {}
    }
    $dashboardCacheShouldClear = true;
}

// ---------- payout.paid / payout.failed: log per tracciamento ----------
if ($eventType === 'payout.paid' || $eventType === 'payout.failed') {
    $dashboardCacheShouldClear = true;
}

// ---------- checkout.session.completed (logica esistente) ----------
if ($eventType === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $metadata = $session['metadata'] ?? [];
    $fatturaId = isset($metadata['fattura_id']) ? (int)$metadata['fattura_id'] : 0;
    $paymentIntent = $session['payment_intent'] ?? null;

    if ($fatturaId > 0) {
        $upd = $pdo->prepare("UPDATE fatture SET stato = 'pagata', data_pagamento = NOW(), metodo_pagamento = 'Stripe', stripe_payment_intent_id = :pi WHERE id = :id");
        $upd->execute([
            ':pi' => $paymentIntent,
            ':id' => $fatturaId,
        ]);
        $dashboardCacheShouldClear = true;

        // Invia automaticamente la fattura solo se il cliente è già completo in anagrafica
        try {
            $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id LIMIT 1");
            $stmt->execute([':id' => $fatturaId]);
            $fattura = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fattura) {
                $docType = (string)($fattura['document_type'] ?? 'invoice');

                try {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_send_settings (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        setting_key VARCHAR(100) NOT NULL UNIQUE,
                        send_mode VARCHAR(20) NOT NULL DEFAULT "manuale",
                        enabled TINYINT(1) NOT NULL DEFAULT 0,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } catch (Throwable $ignored) {
                }

                $settingsStmt = $pdo->prepare('SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = :setting_key LIMIT 1');
                $settingsStmt->execute([':setting_key' => 'fatture_send']);
                $sendSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: ['send_mode' => 'manuale', 'enabled' => 0];
                $autoSendEnabled = ((string)($sendSettings['send_mode'] ?? 'manuale') === 'automatico') && ((int)($sendSettings['enabled'] ?? 0) === 1);

                // Invio automatico SOLO per fatture (non ordini/preventivi)
                if ($docType === 'invoice' && $autoSendEnabled) {
                    $piva = trim((string)($fattura['piva'] ?? ''));
                    $codiceSdi = trim((string)($fattura['codice_sdi'] ?? ''));
                    $pec = trim((string)($fattura['pec'] ?? ''));
                    $anagrafica = trim((string)($fattura['azienda'] ?? '')) !== '' || trim((string)($fattura['nome'] ?? '')) !== '';
                    $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
                    $alreadySent = in_array($eiStatus, ['sent', 'processing', 'accepted'], true);

                    // Requisiti minimi anagrafica per invio automatico
                    $hasVatData = $piva !== '';
                    $hasRecipientData = ($codiceSdi !== '') || ($pec !== '' && filter_var($pec, FILTER_VALIDATE_EMAIL));
                    $isRegistryComplete = $hasVatData && $hasRecipientData && $anagrafica;

                    if (!$alreadySent && $isRegistryComplete) {
                        $cfg = fic_config();
                        $companyId = (string)($cfg['company_id'] ?? '');
                        if ($companyId === '') {
                            $companyId = (string)fic_get_company_id();
                        }

                        if ($companyId !== '') {
                            $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
                            $righeStmt->execute([':id' => $fatturaId]);
                            $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

                            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'auto_stripe_webhook');

                            $updMail = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
                            $updMail->execute([
                                ':status' => ((string)($fattura['mail_status'] ?? '') !== '' ? (string)$fattura['mail_status'] : 'pending'),
                                ':err' => null,
                                ':id' => $fatturaId,
                            ]);

                            fatture_log($pdo, $fatturaId, 'stripe_auto_send', 'ok', 'Invio automatico e-fattura (SDI) dopo pagamento Stripe completato.', [
                                'payment_intent' => $paymentIntent,
                            ]);
                        } else {
                            $updMail = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
                            $updMail->execute([
                                ':status' => 'pending',
                                ':err' => 'Pagamento Stripe registrato: invio automatico non eseguito (company_id FIC mancante).',
                                ':id' => $fatturaId,
                            ]);
                        }
                    } elseif (!$isRegistryComplete) {
                        $missing = [];
                        if (!$hasVatData) {
                            $missing[] = 'partita IVA';
                        }
                        if (!$hasRecipientData) {
                            $missing[] = 'codice SDI o PEC valida';
                        }
                        if (!$anagrafica) {
                            $missing[] = 'anagrafica (nome/azienda)';
                        }

                        $reason = 'Pagamento Stripe registrato: invio automatico non eseguito (anagrafica incompleta: ' . implode(', ', $missing) . ').';
                        $updMail = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
                        $updMail->execute([
                            ':status' => 'pending',
                            ':err' => substr($reason, 0, 1000),
                            ':id' => $fatturaId,
                        ]);

                        fatture_log($pdo, $fatturaId, 'stripe_auto_send', 'info', 'Invio automatico saltato: anagrafica incompleta.', [
                            'payment_intent' => $paymentIntent,
                            'missing' => $missing,
                        ]);
                    }
                } elseif ($docType === 'invoice' && !$autoSendEnabled) {
                    $updMail = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
                    $updMail->execute([
                        ':status' => 'pending',
                        ':err' => 'Pagamento Stripe registrato: invio automatico disattivato da impostazioni.',
                        ':id' => $fatturaId,
                    ]);

                    fatture_log($pdo, $fatturaId, 'stripe_auto_send', 'info', 'Invio automatico saltato: automazione disattivata nelle impostazioni.', [
                        'payment_intent' => $paymentIntent,
                    ]);
                }
            }
        } catch (Throwable $e) {
            try {
                $updMail = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
                $updMail->execute([
                    ':status' => 'error',
                    ':err' => substr('Pagamento Stripe registrato ma invio automatico fallito: ' . $e->getMessage(), 0, 1000),
                    ':id' => $fatturaId,
                ]);
            } catch (Throwable $ignored) {
            }
        }
    }
}

if ($dashboardCacheShouldClear) {
    tdg_dashboard_cache_clear();
}

http_response_code(200);
echo 'ok';
