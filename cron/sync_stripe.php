<?php
/**
 * CRON: Sincronizza pagamenti Stripe → DB → Clienti → Fatture automatiche
 *
 * Cron job (ogni 5 minuti):
 *   wget -q -O /dev/null "https://crm.example.com/cron/sync_stripe.php?key=CHIAVE"
 *
 * Flusso:
 *   1. Scarica ultimi pagamenti da Stripe (charge.succeeded)
 *   2. Upsert pagamento in tabella `pagamenti`
 *   3. Trova/crea cliente in `clienti` (match per email o nome)
 *   4. Aggiorna anagrafica da metadata Stripe (P.IVA, SDI, PEC, ecc.)
 *   5. Se il cliente ha i requisiti → crea fattura automatica → invia FIC + email
 *   6. Pulisce la cache dashboard
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 240);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../config/stripe_customer_links.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

// Carica dipendenze
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../modules/dashboard/cache_utils.php';

$config  = require __DIR__ . '/../config/config.php';
$secret  = file_exists(__DIR__ . '/../config/secret.php') ? require __DIR__ . '/../config/secret.php' : [];
$pdo     = require __DIR__ . '/../config/db.php';

$stripeKey = $secret['stripe']['secret_key'] ?? $config['stripe']['secret_key'] ?? '';
if (!$stripeKey || strpos($stripeKey, 'sk_') !== 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stripe non configurato']);
    exit;
}

\Stripe\Stripe::setApiKey($stripeKey);

// ---------- Tabelle necessarie ----------
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cron_sync_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_name VARCHAR(100) NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        imported INT UNSIGNED NOT NULL DEFAULT 0,
        invoices_created INT UNSIGNED NOT NULL DEFAULT 0,
        clients_created INT UNSIGNED NOT NULL DEFAULT 0,
        errors INT UNSIGNED NOT NULL DEFAULT 0,
        details JSON NULL,
        INDEX(job_name, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // tabella già presente
}

// Evita esecuzioni sovrapposte: se l'ultimo run è ancora 'running' e meno di 5 min fa, skip
try {
    $lockStmt = $pdo->prepare("SELECT started_at FROM cron_sync_log WHERE job_name = 'stripe_sync' AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
    $lockStmt->execute();
    if ($lockStmt->fetch()) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Sincronizzazione già in corso']);
        exit;
    }
} catch (Throwable $e) {
    // ignora, procedi comunque
}

// Registra inizio run
$runStmt = $pdo->prepare("INSERT INTO cron_sync_log (job_name, started_at, status) VALUES ('stripe_sync', NOW(), 'running')");
$runStmt->execute();
$runId = (int)$pdo->lastInsertId();

$stats = [
    'mode'             => 'incremental',
    'sync_since'       => null,
    'processed'        => 0,
    'imported'         => 0,
    'updated'          => 0,
    'already_exists'   => 0,
    'clients_created'  => 0,
    'clients_updated'  => 0,
    'invoices_created' => 0,
    'invoices_skipped' => 0,
    'emails_sent'      => 0,
    'errors'           => 0,
    'error_samples'    => [],
];

// ---------- Helper: ottieni ultimo timestamp sincronizzato ----------
function cron_last_sync_ts(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare("SELECT MAX(created_at) FROM pagamenti");
        $stmt->execute();
        $lastDate = $stmt->fetchColumn();
        if ($lastDate) {
            $ts = strtotime($lastDate);
            // Finestra ampia: recupera anche pagamenti sfuggiti a un run precedente.
            return $ts ? max(0, $ts - (14 * 86400)) : 0;
        }
    } catch (Throwable $e) {
    }
    return 0;
}

function cron_reconciliation_due(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM cron_sync_log
            WHERE job_name = 'stripe_sync_reconcile'
              AND status = 'ok'
              AND started_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            LIMIT 1");
        $stmt->execute();
        return !$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

// ---------- Helper: trova o crea cliente ----------
function cron_find_or_create_client(PDO $pdo, array $data, string $note): array
{
    $email    = strtolower(trim($data['email'] ?? ''));
    $nome     = trim($data['nome'] ?? '');
    $azienda  = trim($data['azienda'] ?? '');
    $telefono = trim($data['telefono'] ?? '');
    $piva     = trim($data['piva'] ?? '');
    $sdi      = strtoupper(trim($data['codice_sdi'] ?? ''));
    $pec      = strtolower(trim($data['pec'] ?? ''));
    $indirizzo = trim($data['indirizzo'] ?? '');

    $created = false;

    // 1. Match per email
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            cron_update_client_empty_fields($pdo, (int)$row['id'], $data);
            return [(int)$row['id'], false];
        }
    }

    // 2. Match per nome/azienda
    $nameProbe = $nome !== '' ? $nome : $azienda;
    if ($nameProbe !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clienti WHERE LOWER(TRIM(nome)) = LOWER(?) OR LOWER(TRIM(azienda)) = LOWER(?) LIMIT 1");
        $stmt->execute([$nameProbe, $nameProbe]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            cron_update_client_empty_fields($pdo, (int)$row['id'], $data);
            return [(int)$row['id'], false];
        }
    }

    // 3. Crea nuovo
    $nomeIns = $nome !== '' ? $nome : ($azienda !== '' ? $azienda : 'Cliente Stripe');
    $stmt = $pdo->prepare("INSERT INTO clienti (nome, email, telefono, azienda, indirizzo, piva, codice_sdi, pec, note, creato_il) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $nomeIns,
        $email !== '' ? $email : null,
        $telefono !== '' ? $telefono : null,
        $azienda !== '' ? $azienda : null,
        $indirizzo !== '' ? $indirizzo : null,
        $piva !== '' ? $piva : null,
        $sdi !== '' ? $sdi : null,
        $pec !== '' ? $pec : null,
        $note,
    ]);

    return [(int)$pdo->lastInsertId(), true];
}

function cron_update_client_empty_fields(PDO $pdo, int $id, array $data): void
{
    tdg_cliente_sync_apply_updates($pdo, $id, [
        'email' => $data['email'] ?? '',
        'telefono' => $data['telefono'] ?? '',
        'azienda' => $data['azienda'] ?? '',
        'indirizzo' => $data['indirizzo'] ?? '',
        'piva' => $data['piva'] ?? '',
        'codice_sdi' => $data['codice_sdi'] ?? '',
        'pec' => $data['pec'] ?? '',
    ]);
}

// ---------- Helper: estrai dati cliente da Stripe ----------
function cron_extract_client_data($customer, $charge): array
{
    $cust = is_object($customer) ? json_decode(json_encode($customer), true) : (array)($customer ?? []);
    $ch   = is_object($charge) ? json_decode(json_encode($charge), true) : (array)($charge ?? []);
    $billing = $ch['billing_details'] ?? [];
    $custMeta = $cust['metadata'] ?? [];
    $chMeta   = $ch['metadata'] ?? [];

    $pick = function (array $meta, array $keys): string {
        foreach ($keys as $k) {
            $v = trim((string)($meta[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    };

    $addr = function (array $a): string {
        $parts = [];
        foreach (['line1','line2','postal_code','city','state','country'] as $k) {
            $v = trim((string)($a[$k] ?? ''));
            if ($v !== '') $parts[] = $v;
        }
        return implode(', ', $parts);
    };

    return [
        'email'      => strtolower(trim($cust['email'] ?? $billing['email'] ?? '')),
        'nome'       => trim($cust['name'] ?? $billing['name'] ?? ''),
        'telefono'   => trim($cust['phone'] ?? $billing['phone'] ?? ''),
        'azienda'    => $pick($custMeta, ['azienda','company','company_name','ragione_sociale'])
                        ?: $pick($chMeta, ['azienda','company','company_name','ragione_sociale']),
        'piva'       => strtoupper(preg_replace('/\s+/', '', $pick($custMeta, ['piva','partita_iva','vat_number','tax_id'])
                        ?: $pick($chMeta, ['piva','partita_iva','vat_number','tax_id']))),
        'codice_sdi' => strtoupper(trim($pick($custMeta, ['codice_sdi','sdi','recipient_code','codice_destinatario'])
                        ?: $pick($chMeta, ['codice_sdi','sdi','recipient_code','codice_destinatario']))),
        'pec'        => strtolower(trim($pick($custMeta, ['pec','certified_email'])
                        ?: $pick($chMeta, ['pec','certified_email']))),
        'indirizzo'  => $addr($cust['address'] ?? []) ?: $addr($billing['address'] ?? []),
    ];
}

// ---------- Helper: genera numero fattura ----------
function cron_generate_invoice_number(PDO $pdo, int $ts): string
{
    $day = date('Ymd', $ts);
    $prefix = 'STR-INV-' . $day . '-';
    $stmt = $pdo->prepare("SELECT numero FROM fatture WHERE document_type = 'invoice' AND numero LIKE ? ORDER BY numero DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $prog = 1;
    if ($last && preg_match('/STR-INV-\d{8}-(\d+)/', (string)$last, $m)) {
        $prog = (int)$m[1] + 1;
    }
    return sprintf('STR-INV-%s-%03d', $day, $prog);
}

// ---------- Helper: check requisiti fatturazione ----------
function cron_client_can_invoice(PDO $pdo, int $clienteId): array
{
    $stmt = $pdo->prepare("SELECT nome, email, azienda, piva, codice_sdi, pec FROM clienti WHERE id = ? LIMIT 1");
    $stmt->execute([$clienteId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) return [false, 'Cliente non trovato', []];

    $missing = [];
    $email = trim($c['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'email valida';
    }
    if (trim($c['piva'] ?? '') === '') {
        $missing[] = 'P.IVA';
    }
    if (trim($c['azienda'] ?? '') === '' && trim($c['nome'] ?? '') === '') {
        $missing[] = 'anagrafica (nome/azienda)';
    }

    return [empty($missing), implode(', ', $missing), $c];
}

// ---------- Helper: crea fattura e invia ----------
function cron_create_invoice(PDO $pdo, int $clienteId, string $stripeId, float $totale, int $createdTs, string $descrizione, string $metodo): int
{
    // Verifica che non esista già
    $check = $pdo->prepare("SELECT id FROM fatture WHERE stripe_payment_intent_id = ? LIMIT 1");
    $check->execute([$stripeId]);
    if ($check->fetchColumn()) return 0; // già esiste

    $numero = cron_generate_invoice_number($pdo, $createdTs > 0 ? $createdTs : time());
    $dataEmissione = date('Y-m-d', $createdTs > 0 ? $createdTs : time());
    $dataPagamento = date('Y-m-d H:i:s', $createdTs > 0 ? $createdTs : time());

    $stmt = $pdo->prepare("INSERT INTO fatture (
        cliente_id, numero, data_emissione, stato, totale, note,
        stripe_payment_intent_id, document_type, metodo_pagamento, data_pagamento, creato_il
    ) VALUES (?, ?, ?, 'pagata', ?, ?, ?, 'invoice', ?, ?, ?)");
    $stmt->execute([
        $clienteId,
        $numero,
        $dataEmissione,
        $totale,
        "Fattura creata da cron automatico\n\n" . $descrizione,
        $stripeId,
        'Stripe - ' . $metodo,
        $dataPagamento,
        $dataPagamento,
    ]);

    $fatturaId = (int)$pdo->lastInsertId();
    if ($fatturaId <= 0) return 0;

    // Riga fattura: $totale e' lordo IVA inclusa al 22% (pagamento Stripe).
    // Salviamo prezzo unitario imponibile e IVA 22 cosi' la generazione FIC
    // produce il documento corretto e SDI puo' partire automaticamente.
    $ivaPercent     = 22.0;
    $imponibileUnit = round(((float)$totale) / (1 + ($ivaPercent / 100)), 2);
    if ($imponibileUnit < 0) {
        $imponibileUnit = 0.0;
    }
    $pdo->prepare("INSERT INTO fatture_righe (fattura_id, descrizione, quantita, prezzo_unitario, iva, totale_riga) VALUES (?,?,1,?,?,?)")
        ->execute([$fatturaId, $descrizione, $imponibileUnit, $ivaPercent, $totale]);

    return $fatturaId;
}

// ---------- Helper: invio automatico fattura ----------
function cron_try_send_invoice(PDO $pdo, int $fatturaId): string
{
    $mailPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($mailPath)) return 'no_smtp_config';

    require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';
    require_once __DIR__ . '/../modules/fatture/actions/fic_audit.php';

    $cfgMail = require $mailPath;
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') return 'no_fic_company';

    $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
        FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
    $stmt->execute([$fatturaId]);
    $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fattura) return 'fattura_not_found';

    $righe = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id");
    $righe->execute([$fatturaId]);
    $righe = $righe->fetchAll(PDO::FETCH_ASSOC);

    // Precheck cliente per SDI: se mancano dati, NON inviamo elettronica.
    // Lasciamo la fattura locale in stato pending elettronico con motivo chiaro,
    // poi proviamo comunque l'email standard (per B2C / fallback).
    $sdiResult  = 'skipped';
    $sdiError   = null;
    $eInvoiceMissing = function_exists('fic_einvoice_missing_data_error')
        ? fic_einvoice_missing_data_error($fattura)
        : null;

    if ($eInvoiceMissing === null) {
        try {
            fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'cron_stripe_auto');
            $sdiResult = 'sent';
            if (function_exists('fatture_log')) {
                fatture_log($pdo, $fatturaId, 'cron_auto_einvoice', 'ok', 'Fattura elettronica inviata automaticamente da cron Stripe.');
            }
        } catch (Throwable $e) {
            $sdiResult = 'error';
            $sdiError  = substr($e->getMessage(), 0, 200);
            if (function_exists('fatture_log')) {
                fatture_log($pdo, $fatturaId, 'cron_auto_einvoice', 'error', 'Errore invio SDI cron: ' . $sdiError);
            }
        }
    } else {
        try {
            $pdo->prepare("UPDATE fatture SET fic_status = 'pending', fic_error = :err, fic_synced_at = NOW() WHERE id = :id")
                ->execute([':err' => substr($eInvoiceMissing, 0, 500), ':id' => $fatturaId]);
        } catch (Throwable $e) {
            // ignora se colonne assenti
        }
        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'cron_auto_einvoice', 'skipped', 'SDI non inviata: ' . substr($eInvoiceMissing, 0, 180));
        }
        $sdiError = $eInvoiceMissing;
    }

    try {
        fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'cron');

        $pdo->prepare("UPDATE fatture SET mail_status = 'sent', mail_error = NULL, mail_sent_at = NOW() WHERE id = ?")
            ->execute([$fatturaId]);

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'cron_auto_send', 'ok', 'Invio automatico da cron completato. SDI: ' . $sdiResult . ($sdiError ? ' (' . substr($sdiError, 0, 120) . ')' : ''));
        }
        if ($sdiResult === 'sent') {
            return 'sent_einvoice+email';
        }
        return $sdiResult === 'skipped'
            ? 'sent_email_only_einvoice_pending'
            : 'sent_email_einvoice_error';
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE fatture SET mail_status = 'error', mail_error = ? WHERE id = ?")
            ->execute([substr($e->getMessage(), 0, 1000), $fatturaId]);

        if (function_exists('fatture_log')) {
            fatture_log($pdo, $fatturaId, 'cron_auto_send', 'error', 'Errore invio cron: ' . substr($e->getMessage(), 0, 180));
        }
        return 'error: ' . substr($e->getMessage(), 0, 200) . ' | sdi=' . $sdiResult;
    }
}

// ---------- MAIN: scarica pagamenti Stripe ----------
try {
    $forceFull = isset($_GET['full']) && (string)$_GET['full'] === '1';
    $backfillDays = isset($_GET['backfill_days']) ? (int)$_GET['backfill_days'] : 365;
    $backfillDays = max(30, min(730, $backfillDays));
    $isReconciliation = $forceFull || cron_reconciliation_due($pdo);
    $lastSyncTs = $isReconciliation
        ? max(0, time() - ($backfillDays * 86400))
        : cron_last_sync_ts($pdo);

    $stats['mode'] = $isReconciliation ? 'reconciliation' : 'incremental';
    $stats['sync_since'] = $lastSyncTs > 0 ? date('Y-m-d H:i:s', $lastSyncTs) : 'all';

    // Parametri per la query Stripe - solo pagamenti recenti
    $params = [
        'limit' => 100,
        'expand' => ['data.customer'],
    ];
    if ($lastSyncTs > 0) {
        $params['created'] = ['gte' => $lastSyncTs];
    }

    $charges = \Stripe\Charge::all($params);
    $processed = 0;

    // Verifica auto-send attivo
    $autoSendEnabled = false;
    try {
        $settStmt = $pdo->prepare("SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = 'fatture_send' LIMIT 1");
        $settStmt->execute();
        $sett = $settStmt->fetch(PDO::FETCH_ASSOC);
        $autoSendEnabled = ($sett && $sett['send_mode'] === 'automatico' && (int)$sett['enabled'] === 1);
    } catch (Throwable $e) {
        // tabella non esiste, auto-send disattivo
    }

    foreach ($charges->autoPagingIterator() as $charge) {
        $processed++;
        $stats['processed'] = $processed;

        $stripeId = (string)$charge->id;
        $stato = (string)$charge->status;
        $amount = (int)$charge->amount;
        $currency = strtoupper((string)$charge->currency);
        $metodo = 'card';
        if ($charge->payment_method_details) {
            $metodo = $charge->payment_method_details->type ?? 'card';
        }
        $createdTs = (int)$charge->created;
        $description = (string)($charge->description ?? 'Pagamento Stripe');

        // 1. Upsert pagamento
        $clienteId = 0;
        $stripeCustomerId = '';

        if ($charge->customer) {
            $stripeCustomerId = is_string($charge->customer)
                ? $charge->customer
                : ($charge->customer->id ?? '');

            // Prova match da stripe_sync
            if ($stripeCustomerId !== '') {
                try {
                    $clienteId = tdg_find_cliente_id_by_stripe_customer($pdo, $stripeCustomerId);
                } catch (Throwable $e) {}
            }

            // Estrai dati anagrafici da Stripe
            try {
                $customer = is_string($charge->customer)
                    ? \Stripe\Customer::retrieve($charge->customer)
                    : $charge->customer;

                $clientData = cron_extract_client_data($customer, $charge);

                if ($clienteId > 0) {
                    cron_update_client_empty_fields($pdo, $clienteId, $clientData);
                } else {
                    [$clienteId, $wasCreated] = cron_find_or_create_client(
                        $pdo,
                        $clientData,
                        'Importato da cron Stripe (Customer: ' . $stripeCustomerId . ')'
                    );
                    if ($wasCreated) $stats['clients_created']++;
                    else $stats['clients_updated']++;
                }

                // Aggiorna stripe_sync
                if ($clienteId > 0 && $stripeCustomerId !== '') {
                    try {
                        $pdo->prepare("INSERT INTO stripe_sync (cliente_id, stripe_customer_id, stripe_email, stripe_name, last_charge_amount, last_charge_date, payment_method, synced_at)
                            VALUES (?,?,?,?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE stripe_email = VALUES(stripe_email), stripe_name = VALUES(stripe_name),
                            last_charge_amount = VALUES(last_charge_amount), last_charge_date = VALUES(last_charge_date), synced_at = NOW()")
                            ->execute([
                                $clienteId,
                                $stripeCustomerId,
                                $clientData['email'],
                                $clientData['nome'] ?: $clientData['azienda'],
                                $amount,
                                date('Y-m-d H:i:s', $createdTs),
                                $metodo,
                            ]);
                        tdg_upsert_stripe_customer_link(
                            $pdo,
                            $clienteId,
                            $stripeCustomerId,
                            (string)$clientData['email'],
                            (string)($clientData['nome'] ?: $clientData['azienda']),
                            'cron_sync_stripe'
                        );
                    } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $stats['error_samples'][] = 'Customer ' . $stripeCustomerId . ': ' . substr($e->getMessage(), 0, 100);
            }
        }

        // Se ancora niente cliente, prova dai billing_details
        if ($clienteId <= 0) {
            $clientData = cron_extract_client_data(null, $charge);
            if ($clientData['email'] !== '' || $clientData['nome'] !== '') {
                [$clienteId, $wasCreated] = cron_find_or_create_client(
                    $pdo,
                    $clientData,
                    'Importato da cron (billing details charge ' . $stripeId . ')'
                );
                if ($wasCreated) $stats['clients_created']++;
            }
        }

        // 2. Insert pagamento
        try {
            $chargeCreatedAt = date('Y-m-d H:i:s', $createdTs > 0 ? $createdTs : time());
            $insStmt = $pdo->prepare("INSERT INTO pagamenti (stripe_id, cliente_id, importo, valuta, stato, metodo, descrizione, created_at)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    cliente_id = COALESCE(NULLIF(VALUES(cliente_id), 0), cliente_id),
                    importo = VALUES(importo),
                    valuta = VALUES(valuta),
                    stato = VALUES(stato),
                    metodo = VALUES(metodo),
                    descrizione = VALUES(descrizione),
                    created_at = VALUES(created_at)");
            $insStmt->execute([$stripeId, $clienteId > 0 ? $clienteId : null, $amount, $currency, $stato, $metodo, $description, $chargeCreatedAt]);

            if ($insStmt->rowCount() === 1) {
                $stats['imported']++;
            } elseif ($insStmt->rowCount() > 1) {
                $stats['updated']++;
            } else {
                $stats['already_exists']++;
                // Aggiorna cliente_id se mancava
                if ($clienteId > 0) {
                    $pdo->prepare("UPDATE pagamenti SET cliente_id = ? WHERE stripe_id = ? AND (cliente_id IS NULL OR cliente_id = 0)")
                        ->execute([$clienteId, $stripeId]);
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
        }

        // 3. Crea fattura automatica se possibile
        if ($stato === 'succeeded' && $clienteId > 0 && $autoSendEnabled) {
            try {
                [$canInvoice, $missingFields, $clientInfo] = cron_client_can_invoice($pdo, $clienteId);

                $invoiceId = cron_create_invoice(
                    $pdo, $clienteId, $stripeId,
                    $amount / 100, $createdTs, $description, $metodo
                );

                if ($invoiceId > 0) {
                    $stats['invoices_created']++;

                    // Se requisiti OK → invia subito
                    if ($canInvoice) {
                        $sendResult = cron_try_send_invoice($pdo, $invoiceId);
                        if ($sendResult === 'sent') {
                            $stats['emails_sent']++;
                        }
                    } else {
                        // Salva come pending con motivo
                        $pdo->prepare("UPDATE fatture SET mail_status = 'pending', mail_error = ? WHERE id = ?")
                            ->execute(['Anagrafica incompleta: ' . $missingFields, $invoiceId]);
                    }
                } else {
                    $stats['invoices_skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $stats['error_samples'][] = 'Invoice ' . $stripeId . ': ' . substr($e->getMessage(), 0, 100);
            }
        }
    }

    // Pulisci cache dashboard
    tdg_dashboard_cache_clear();

    // Aggiorna log run
    $pdo->prepare("UPDATE cron_sync_log SET finished_at = NOW(), status = 'ok', imported = ?, invoices_created = ?, clients_created = ?, errors = ?, details = ? WHERE id = ?")
        ->execute([
            $stats['imported'],
            $stats['invoices_created'],
            $stats['clients_created'],
            $stats['errors'],
            json_encode($stats),
            $runId,
        ]);

    if ($isReconciliation) {
        try {
            $pdo->prepare("INSERT INTO cron_sync_log (job_name, started_at, finished_at, status, imported, invoices_created, clients_created, errors, details)
                VALUES ('stripe_sync_reconcile', NOW(), NOW(), 'ok', ?, ?, ?, ?, ?)")
                ->execute([
                    $stats['imported'],
                    $stats['invoices_created'],
                    $stats['clients_created'],
                    $stats['errors'],
                    json_encode($stats),
                ]);
        } catch (Throwable $ignore) {
        }
    }

    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'run_id' => $runId,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    // Aggiorna log come errore
    try {
        $pdo->prepare("UPDATE cron_sync_log SET finished_at = NOW(), status = 'error', errors = 1, details = ? WHERE id = ?")
            ->execute([json_encode(['fatal' => substr($e->getMessage(), 0, 500)]), $runId]);
    } catch (Throwable $ignore) {}

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr($e->getMessage(), 0, 300),
        'run_id' => $runId,
    ]);
}
