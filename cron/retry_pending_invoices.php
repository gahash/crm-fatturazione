<?php
/**
 * CRON: Riprova invio fatture pending
 *
 * Cron job (ogni 30 minuti):
 *   wget -q -O /dev/null "https://crm.example.com/cron/retry_pending_invoices.php?key=CHIAVE"
 *
 * Cerca fatture con mail_status='pending', ricontrolla i requisiti del cliente,
 * e se ora completi → invia automaticamente.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 90);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_audit.php';

$pdo = require __DIR__ . '/../config/db.php';

$clienteIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['cliente_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));

$stats = [
    'checked'     => 0,
    'sent'        => 0,
    'sdi_sent'    => 0,
    'fic_linked'  => 0,
    'still_pending' => 0,
    'errors'      => 0,
];

try {
    // Verifica auto-send attivo
    $autoSendEnabled = false;
    try {
        $stmt = $pdo->prepare("SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = 'fatture_send' LIMIT 1");
        $stmt->execute();
        $sett = $stmt->fetch(PDO::FETCH_ASSOC);
        $autoSendEnabled = ($sett && $sett['send_mode'] === 'automatico' && (int)$sett['enabled'] === 1);
    } catch (Throwable $e) {}

    if (!$autoSendEnabled) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Invio automatico disattivato']);
        exit;
    }

    $mailPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($mailPath)) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Config SMTP mancante']);
        exit;
    }
    $cfgMail = require $mailPath;
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '') ?: (string)fic_get_company_id();
    if ($companyId === '') {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Company ID FIC mancante']);
        exit;
    }

    // Fatture pending recenti e storiche: alcune possono restare bloccate per settimane
    // finche' l'anagrafica non viene completata, quindi non limitiamo a 30 giorni.
    $pendingStmt = $pdo->prepare("
        SELECT f.id, f.cliente_id
        FROM fatture f
        WHERE f.mail_status = 'pending'
          AND f.document_type = 'invoice'
                    " . (!empty($clienteIds) ? 'AND f.cliente_id IN (' . implode(',', array_fill(0, count($clienteIds), '?')) . ')' : '') . "
        ORDER BY f.creato_il DESC
        LIMIT 200
    ");
        $pendingStmt->execute($clienteIds);
    $pendingRows = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingRows as $row) {
        $stats['checked']++;
        $fatturaId = (int)$row['id'];
        $clienteId = (int)$row['cliente_id'];

        $fStmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
            FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = ? LIMIT 1");
        $fStmt->execute([$fatturaId]);
        $fattura = $fStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fattura) {
            $stats['still_pending']++;
            continue;
        }

        $missingDataError = fic_einvoice_missing_data_error($fattura);
        if ($missingDataError !== null) {
            try {
                $pdo->prepare("UPDATE fatture SET mail_error = ? WHERE id = ?")
                    ->execute([substr($missingDataError, 0, 1000), $fatturaId]);
            } catch (Throwable $ignore) {
            }
            $stats['still_pending']++;
            continue;
        }

        $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = ? ORDER BY id");
        $righeStmt->execute([$fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);

        // Requisiti OK -> riallinea FIC, invia SDI se non gia' inviato, poi email.
        try {
            [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
            if (!empty($docId)) {
                $stats['fic_linked']++;
            }

            $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
            if (!in_array($eiStatus, ['sent', 'processing', 'accepted', 'ok'], true)) {
                fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'cron_retry');
                $stats['sdi_sent']++;
            }

            fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'cron_retry');

            $pdo->prepare("UPDATE fatture SET mail_status = 'sent', mail_error = NULL, mail_sent_at = NOW() WHERE id = ?")
                ->execute([$fatturaId]);

            if (function_exists('fatture_log')) {
                fatture_log($pdo, $fatturaId, 'cron_retry_send', 'ok', 'Fattura pending riallineata su FIC e reinviata automaticamente.');
            }
            $stats['sent']++;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE fatture SET mail_error = ? WHERE id = ?")
                ->execute([substr('Retry fallito: ' . $e->getMessage(), 0, 1000), $fatturaId]);
            $stats['errors']++;
        }
    }

    echo json_encode([
        'ok' => true,
        'cliente_ids' => $clienteIds,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 300)]);
}
