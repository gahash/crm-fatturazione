<?php
// cron/cron_send_einvoice_paid.php
// Esegue l'invio SDI per tutte le fatture già pagate e sincronizzate su Fatture in Cloud ma non ancora inviate come e-fattura

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

require_once __DIR__ . '/../modules/fatture/actions/fic_mail_helpers.php';

$pdo = require __DIR__ . '/../config/db.php';
$clienteIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['cliente_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));
$fatturaIds = array_values(array_filter(array_map(static function ($value): int {
    return max(0, (int)trim((string)$value));
}, explode(',', (string)($_GET['fattura_ids'] ?? ''))), static function (int $value): bool {
    return $value > 0;
}));
$jsonOutput = isset($_GET['format']) && (string)($_GET['format']) === 'json';
$cfg = fic_config();
$companyId = (string)($cfg['company_id'] ?? '');
if ($companyId === '') {
    $companyId = (string)fic_get_company_id();
}
if ($companyId === '') {
    http_response_code(500);
    if ($jsonOutput) {
        echo json_encode(['ok' => false, 'error' => 'company_id Fatture in Cloud mancante'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    echo "[ERRORE] company_id Fatture in Cloud mancante\n";
    exit(1);
}

// Seleziona tutte le fatture pagate, sincronizzate su FIC, ma non ancora inviate SDI
$clienteFilterSql = '';
if ($clienteIds) {
    $clienteFilterSql = ' AND f.cliente_id IN (' . implode(',', array_fill(0, count($clienteIds), '?')) . ')';
}
$fatturaFilterSql = '';
if ($fatturaIds) {
    $fatturaFilterSql = ' AND f.id IN (' . implode(',', array_fill(0, count($fatturaIds), '?')) . ')';
}

$sql = "SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
        WHERE f.stato = 'pagata'
          AND f.fic_document_id IS NOT NULL
          AND (f.ei_status IS NULL OR f.ei_status = '' OR f.ei_status = 'todo' OR f.ei_status = 'error')
          {$clienteFilterSql}
          {$fatturaFilterSql}
          AND f.document_type = 'invoice'";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($clienteIds, $fatturaIds));
$fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
$failed = 0;
$items = [];
foreach ($fatture as $fattura) {
    $fatturaId = (int)$fattura['id'];
    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
    $righeStmt->execute([':id' => $fatturaId]);
    $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        fic_send_invoice_electronic($pdo, $fattura, $righe, $cfg, $companyId, 'cron_auto_paid');
        $items[] = [
            'fattura_id' => $fatturaId,
            'numero' => (string)($fattura['numero'] ?? ''),
            'status' => 'ok',
        ];
        echo "[OK] Fattura #{$fattura['numero']} inviata SDI\n";
        $sent++;
    } catch (Throwable $e) {
        $items[] = [
            'fattura_id' => $fatturaId,
            'numero' => (string)($fattura['numero'] ?? ''),
            'status' => 'error',
            'error' => substr((string)$e->getMessage(), 0, 400),
        ];
        echo "[ERRORE] Fattura #{$fattura['numero']}: ".$e->getMessage()."\n";
        $failed++;
    }
}
if ($jsonOutput) {
    echo json_encode([
        'ok' => true,
        'cliente_ids' => $clienteIds,
        'fattura_ids' => $fatturaIds,
        'selected' => count($fatture),
        'sent' => $sent,
        'failed' => $failed,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
echo "Totale inviate: $sent, errori: $failed\n";
