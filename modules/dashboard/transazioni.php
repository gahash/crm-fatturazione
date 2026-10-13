<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../fatture/actions/fic_mail_helpers.php';

$user = current_user();
if (!has_admin_access()) {
    header('Location: ../clienti/clienti.php');
    exit;
}

// === Parametri filtro ===
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage    = 25;
$offset     = ($page - 1) * $perPage;
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$statoFilter = isset($_GET['stato']) ? trim($_GET['stato']) : '';
$metodoFilter = isset($_GET['metodo']) ? trim($_GET['metodo']) : '';
$meseFilter  = isset($_GET['mese']) && $_GET['mese'] !== '' ? (int)$_GET['mese'] : 0;
$annoFilter  = isset($_GET['anno']) && $_GET['anno'] !== '' ? (int)$_GET['anno'] : 0;
$tipo        = isset($_GET['tipo']) ? trim($_GET['tipo']) : 'pagamenti'; // pagamenti | fatture

$aliquotaIvaInput = isset($_GET['aliquota_iva']) ? str_replace(',', '.', (string)$_GET['aliquota_iva']) : '22';
$aliquotaIva = is_numeric($aliquotaIvaInput) ? (float)$aliquotaIvaInput : 22.0;
$aliquotaIva = max(0, min(100, $aliquotaIva));

$mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

function euro_from_cents_t(int $amountCents): string {
    return number_format($amountCents / 100, 2, ',', '.');
}

function vat_split_t(int $grossCents, float $vatRate): array {
    $divider = 1 + ($vatRate / 100);
    $taxable = $divider > 0 ? (int)round($grossCents / $divider) : $grossCents;
    return ['gross' => $grossCents, 'taxable' => $taxable, 'vat' => $grossCents - $taxable];
}

function txn_invoice_sdi_status(array $row): array
{
    $eiStatus = strtolower(trim((string)($row['ei_status'] ?? '')));
    $eiError = trim((string)($row['ei_error'] ?? ''));
    $mailError = trim((string)($row['mail_error'] ?? ''));
    $hasFic = !empty($row['fic_document_id']);

    if (in_array($eiStatus, ['ok', 'accepted', 'sent'], true)) {
        return ['class' => 'succeeded', 'label' => 'SDI ✓', 'title' => 'Fattura elettronica inviata o accettata'];
    }
    if ($eiStatus === 'processing') {
        return ['class' => 'pending', 'label' => 'SDI in corso', 'title' => 'Invio SDI in lavorazione'];
    }
    if ($eiStatus === 'duplicate_unresolved' || strtolower(trim((string)($row['fic_status'] ?? ''))) === 'duplicate_unresolved') {
        return ['class' => 'failed', 'label' => 'Duplicato FIC', 'title' => 'Numero fattura gia presente su Fatture in Cloud'];
    }

    $missingDataError = fic_einvoice_missing_data_error($row);
    if ($missingDataError === null && $hasFic && strtolower(trim((string)($row['stato'] ?? ''))) === 'pagata') {
        if ($eiError !== '') {
            return ['class' => 'failed', 'label' => 'Errore SDI', 'title' => $eiError];
        }
        return ['class' => 'pending', 'label' => 'Da inviare', 'title' => 'Prerequisiti presenti, invio SDI non completato'];
    }

    if (!$hasFic) {
        return ['class' => 'pending', 'label' => 'FIC mancante', 'title' => 'Documento non ancora presente o collegato su Fatture in Cloud'];
    }

    if ($eiError !== '') {
        return ['class' => 'failed', 'label' => 'Errore SDI', 'title' => $eiError];
    }
    if ($mailError !== '' && stripos($mailError, 'invio automatico non eseguito') !== false) {
        return ['class' => 'pending', 'label' => 'Auto-send fermo', 'title' => $mailError];
    }

    return ['class' => 'pending', 'label' => 'Non allineata', 'title' => 'Verifica stato FIC/SDI'];
}

function txn_invoice_prerequisite_status(array $row): array
{
    $missingDataError = fic_einvoice_missing_data_error($row);
    if ($missingDataError === null) {
        return ['class' => 'succeeded', 'label' => 'Anagrafica OK', 'title' => 'P.IVA, SDI/PEC e indirizzo completi per l e-fattura'];
    }

    return ['class' => 'pending', 'label' => 'Dati mancanti', 'title' => $missingDataError];
}

function txn_payment_invoice_status(array $row): array
{
    $paymentState = strtolower(trim((string)($row['stato'] ?? '')));
    if ($paymentState !== 'succeeded') {
        return ['class' => 'pending', 'label' => 'Pagamento non chiuso', 'title' => 'La fattura elettronica e richiesta solo per pagamenti riusciti'];
    }

    if (empty($row['fattura_id'])) {
        return ['class' => 'failed', 'label' => 'Fattura mancante', 'title' => 'Pagamento riuscito ma senza fattura locale collegata'];
    }

    $invoiceRow = [
        'stato' => $row['invoice_state'] ?? '',
        'fic_document_id' => $row['invoice_fic_document_id'] ?? '',
        'fic_status' => $row['invoice_fic_status'] ?? '',
        'ei_status' => $row['invoice_ei_status'] ?? '',
        'ei_error' => $row['invoice_ei_error'] ?? '',
        'mail_error' => $row['invoice_mail_error'] ?? '',
        'piva' => $row['piva'] ?? '',
        'codice_sdi' => $row['codice_sdi'] ?? '',
        'pec' => $row['pec'] ?? '',
        'indirizzo' => $row['indirizzo'] ?? '',
    ];

    return txn_invoice_sdi_status($invoiceRow);
}

function txn_payment_prerequisite_status(array $row): array
{
    $paymentState = strtolower(trim((string)($row['stato'] ?? '')));
    if ($paymentState !== 'succeeded') {
        return ['class' => 'pending', 'label' => 'N/D', 'title' => 'Prerequisiti rilevanti solo sui pagamenti riusciti'];
    }

    return txn_invoice_prerequisite_status($row);
}

// === Export CSV ===
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

// === Build query ===
$rows = [];
$totalRows = 0;
$kpi = ['total_cents' => 0, 'count' => 0, 'succeeded' => 0, 'pending' => 0, 'failed' => 0];
$invoiceCoverage = ['eligible' => 0, 'sdi_done' => 0, 'sdi_pending' => 0, 'blocked_data' => 0, 'fic_missing' => 0];
$paymentCoverage = ['invoice_done' => 0, 'invoice_missing' => 0, 'sdi_pending' => 0, 'blocked_data' => 0];

try {
    if ($tipo === 'fatture') {
        // --- FATTURE ---
        $conditions = ["f.document_type = 'invoice'"];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(c.nome LIKE ? OR c.email LIKE ? OR f.numero LIKE ?)';
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s]);
        }
        if ($statoFilter !== '') {
            $conditions[] = 'f.stato = ?';
            $params[] = $statoFilter;
        }
        if ($annoFilter > 0) {
            $conditions[] = 'YEAR(f.data_emissione) = ?';
            $params[] = $annoFilter;
        }
        if ($meseFilter > 0) {
            $conditions[] = 'MONTH(f.data_emissione) = ?';
            $params[] = $meseFilter;
        }
        if ($metodoFilter !== '') {
            $conditions[] = 'f.metodo_pagamento = ?';
            $params[] = $metodoFilter;
        }

        $where = implode(' AND ', $conditions);

        // Count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture f LEFT JOIN clienti c ON c.id = f.cliente_id WHERE $where");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        // KPI
        $kpiStmt = $pdo->prepare("SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(f.totale), 0) AS total_euro,
            SUM(CASE WHEN f.stato = 'pagata' THEN 1 ELSE 0 END) AS pagate,
            SUM(CASE WHEN f.stato = 'emessa' THEN 1 ELSE 0 END) AS emesse,
            SUM(CASE WHEN f.stato = 'scaduta' OR (f.stato <> 'pagata' AND f.data_scadenza IS NOT NULL AND f.data_scadenza < CURDATE()) THEN 1 ELSE 0 END) AS scadute
            FROM fatture f LEFT JOIN clienti c ON c.id = f.cliente_id WHERE $where");
        $kpiStmt->execute($params);
        $kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi = [
            'count' => (int)($kpiRow['cnt'] ?? 0),
            'total_euro' => (float)($kpiRow['total_euro'] ?? 0),
            'pagate' => (int)($kpiRow['pagate'] ?? 0),
            'emesse' => (int)($kpiRow['emesse'] ?? 0),
            'scadute' => (int)($kpiRow['scadute'] ?? 0),
        ];

        // Data
        $sql = "SELECT f.id, f.numero, f.data_emissione, f.data_scadenza, f.stato, f.totale,
                       f.metodo_pagamento, f.data_pagamento,
                       f.fic_document_id, f.fic_status, f.mail_status, f.mail_sent_at,
                       f.ei_status, f.ei_error, f.mail_error,
                       f.stripe_payment_intent_id,
                       c.nome AS cliente_nome, c.email AS cliente_email,
                       c.piva, c.codice_sdi, c.pec, c.indirizzo
                FROM fatture f
                LEFT JOIN clienti c ON c.id = f.cliente_id
                WHERE $where
                ORDER BY f.data_emissione DESC, f.id DESC
                LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $coverageSql = "SELECT f.stato, f.fic_document_id, f.fic_status, f.ei_status, f.ei_error, f.mail_error,
                               c.piva, c.codice_sdi, c.pec, c.indirizzo
                        FROM fatture f
                        LEFT JOIN clienti c ON c.id = f.cliente_id
                        WHERE $where";
        $coverageStmt = $pdo->prepare($coverageSql);
        $coverageStmt->execute($params);
        while ($coverageRow = $coverageStmt->fetch(PDO::FETCH_ASSOC)) {
            $missingDataError = fic_einvoice_missing_data_error($coverageRow);
            $hasFic = !empty($coverageRow['fic_document_id']);
            $eiStatus = strtolower(trim((string)($coverageRow['ei_status'] ?? '')));
            if ($missingDataError === null) {
                $invoiceCoverage['eligible']++;
            } else {
                $invoiceCoverage['blocked_data']++;
            }
            if (in_array($eiStatus, ['ok', 'accepted', 'sent'], true)) {
                $invoiceCoverage['sdi_done']++;
            } elseif ($missingDataError === null) {
                $invoiceCoverage['sdi_pending']++;
            }
            if (!$hasFic) {
                $invoiceCoverage['fic_missing']++;
            }
        }

    } else {
        // --- PAGAMENTI ---
        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(c.nome LIKE ? OR c.email LIKE ? OR p.stripe_id LIKE ?)';
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s]);
        }
        if ($statoFilter !== '') {
            $conditions[] = 'p.stato = ?';
            $params[] = $statoFilter;
        }
        if ($metodoFilter !== '') {
            $conditions[] = 'p.metodo = ?';
            $params[] = $metodoFilter;
        }
        if ($annoFilter > 0) {
            $conditions[] = 'YEAR(p.created_at) = ?';
            $params[] = $annoFilter;
        }
        if ($meseFilter > 0) {
            $conditions[] = 'MONTH(p.created_at) = ?';
            $params[] = $meseFilter;
        }

        $where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

        // Count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pagamenti p LEFT JOIN clienti c ON c.id = p.cliente_id WHERE $where");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        // KPI
        $kpiStmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN p.stato = 'succeeded' THEN p.importo ELSE 0 END), 0) AS total_cents,
            COUNT(*) AS cnt,
            SUM(CASE WHEN p.stato = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
            SUM(CASE WHEN p.stato = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN p.stato = 'failed' THEN 1 ELSE 0 END) AS failed
            FROM pagamenti p LEFT JOIN clienti c ON c.id = p.cliente_id WHERE $where");
        $kpiStmt->execute($params);
        $kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi = [
            'total_cents' => (int)($kpiRow['total_cents'] ?? 0),
            'count' => (int)($kpiRow['cnt'] ?? 0),
            'succeeded' => (int)($kpiRow['succeeded'] ?? 0),
            'pending' => (int)($kpiRow['pending'] ?? 0),
            'failed' => (int)($kpiRow['failed'] ?? 0),
        ];

        // Data
        $sql = "SELECT p.id, p.stripe_id, p.importo, p.valuta, p.stato, p.metodo, p.descrizione,
                       p.created_at, p.fic_document_id, p.fic_status, p.numero_fattura,
                       c.nome AS cliente_nome, c.email AS cliente_email, c.piva, c.codice_sdi, c.pec, c.indirizzo,
                       f.id AS fattura_id, f.numero AS fattura_numero,
                       f.stato AS invoice_state,
                       f.fic_document_id AS invoice_fic_document_id,
                       f.fic_status AS invoice_fic_status,
                       f.ei_status AS invoice_ei_status,
                       f.ei_error AS invoice_ei_error,
                       f.mail_status AS f_mail_status,
                       f.mail_error AS invoice_mail_error
                FROM pagamenti p
                LEFT JOIN clienti c ON c.id = p.cliente_id
                LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id
                WHERE $where
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $coverageSql = "SELECT p.stato,
                               c.piva, c.codice_sdi, c.pec, c.indirizzo,
                               f.id AS fattura_id,
                               f.stato AS invoice_state,
                               f.fic_document_id AS invoice_fic_document_id,
                               f.fic_status AS invoice_fic_status,
                               f.ei_status AS invoice_ei_status,
                               f.ei_error AS invoice_ei_error,
                               f.mail_error AS invoice_mail_error
                        FROM pagamenti p
                        LEFT JOIN clienti c ON c.id = p.cliente_id
                        LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id
                        WHERE $where";
        $coverageStmt = $pdo->prepare($coverageSql);
        $coverageStmt->execute($params);
        while ($coverageRow = $coverageStmt->fetch(PDO::FETCH_ASSOC)) {
            if (strtolower(trim((string)($coverageRow['stato'] ?? ''))) !== 'succeeded') {
                continue;
            }

            $precheck = txn_payment_prerequisite_status($coverageRow);
            $status = txn_payment_invoice_status($coverageRow);

            if ($precheck['class'] === 'succeeded') {
                if ($status['label'] === 'SDI ✓') {
                    $paymentCoverage['invoice_done']++;
                } else {
                    $paymentCoverage['sdi_pending']++;
                }
            } else {
                $paymentCoverage['blocked_data']++;
            }

            if ($status['label'] === 'Fattura mancante') {
                $paymentCoverage['invoice_missing']++;
            }
        }
    }
} catch (Exception $e) {
    $rows = [];
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// === CSV EXPORT ===
if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transazioni_' . $tipo . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        if ($tipo === 'fatture') {
            fputcsv($out, ['N. Fattura', 'Cliente', 'Email', 'Data Emissione', 'Scadenza', 'Stato', 'Totale EUR', 'Metodo Pagamento', 'FIC', 'Email Invio'], ';');
            // Re-fetch all without LIMIT for export
            $sqlAll = "SELECT f.numero, c.nome, c.email, f.data_emissione, f.data_scadenza, f.stato, f.totale,
                              f.metodo_pagamento, f.fic_status, f.mail_status
                       FROM fatture f LEFT JOIN clienti c ON c.id = f.cliente_id
                       WHERE " . implode(' AND ', array_merge(["f.document_type = 'invoice'"], array_slice($conditions ?? [], 1))) . "
                       ORDER BY f.data_emissione DESC";
            // Rebuild conditions properly for export
            $expConditions = ["f.document_type = 'invoice'"];
            $expParams = [];
            if ($search !== '') { $expConditions[] = '(c.nome LIKE ? OR c.email LIKE ? OR f.numero LIKE ?)'; $s = '%'.$search.'%'; $expParams = [$s,$s,$s]; }
            if ($statoFilter !== '') { $expConditions[] = 'f.stato = ?'; $expParams[] = $statoFilter; }
            if ($annoFilter > 0) { $expConditions[] = 'YEAR(f.data_emissione) = ?'; $expParams[] = $annoFilter; }
            if ($meseFilter > 0) { $expConditions[] = 'MONTH(f.data_emissione) = ?'; $expParams[] = $meseFilter; }
            if ($metodoFilter !== '') { $expConditions[] = 'f.metodo_pagamento = ?'; $expParams[] = $metodoFilter; }
            $expWhere = implode(' AND ', $expConditions);
            $expStmt = $pdo->prepare("SELECT f.numero, c.nome, c.email, f.data_emissione, f.data_scadenza, f.stato, f.totale, f.metodo_pagamento, f.fic_status, f.mail_status FROM fatture f LEFT JOIN clienti c ON c.id = f.cliente_id WHERE $expWhere ORDER BY f.data_emissione DESC");
            $expStmt->execute($expParams);
            while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $r['numero'], $r['nome'], $r['email'], $r['data_emissione'], $r['data_scadenza'] ?? '',
                    $r['stato'], number_format((float)$r['totale'], 2, '.', ''), $r['metodo_pagamento'] ?? '', $r['fic_status'] ?? '', $r['mail_status'] ?? ''
                ], ';');
            }
        } else {
            fputcsv($out, ['Stripe ID', 'Cliente', 'Email', 'Importo EUR', 'Imponibile EUR', 'IVA EUR', 'Valuta', 'Stato', 'Metodo', 'Data', 'N. Fattura', 'FIC'], ';');
            $expConditions = [];
            $expParams = [];
            if ($search !== '') { $expConditions[] = '(c.nome LIKE ? OR c.email LIKE ? OR p.stripe_id LIKE ?)'; $s = '%'.$search.'%'; $expParams = [$s,$s,$s]; }
            if ($statoFilter !== '') { $expConditions[] = 'p.stato = ?'; $expParams[] = $statoFilter; }
            if ($metodoFilter !== '') { $expConditions[] = 'p.metodo = ?'; $expParams[] = $metodoFilter; }
            if ($annoFilter > 0) { $expConditions[] = 'YEAR(p.created_at) = ?'; $expParams[] = $annoFilter; }
            if ($meseFilter > 0) { $expConditions[] = 'MONTH(p.created_at) = ?'; $expParams[] = $meseFilter; }
            $expWhere = !empty($expConditions) ? implode(' AND ', $expConditions) : '1=1';
            $expStmt = $pdo->prepare("SELECT p.stripe_id, p.importo, p.valuta, p.stato, p.metodo, p.created_at, c.nome, c.email, f.numero AS fattura_numero, f.fic_status FROM pagamenti p LEFT JOIN clienti c ON c.id = p.cliente_id LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id WHERE $expWhere ORDER BY p.created_at DESC");
            $expStmt->execute($expParams);
            while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
                $split = vat_split_t((int)$r['importo'], $aliquotaIva);
                fputcsv($out, [
                    $r['stripe_id'], $r['nome'], $r['email'],
                    number_format($split['gross'] / 100, 2, '.', ''),
                    number_format($split['taxable'] / 100, 2, '.', ''),
                    number_format($split['vat'] / 100, 2, '.', ''),
                    $r['valuta'], $r['stato'], $r['metodo'] ?? '', $r['created_at'],
                    $r['fattura_numero'] ?? '', $r['fic_status'] ?? ''
                ], ';');
            }
        }
        fclose($out);
    }
    exit;
}

// === Build query string helper ===
function txn_qs(array $overrides = []): string {
    $defaults = [
        'tipo' => $_GET['tipo'] ?? 'pagamenti',
        'search' => $_GET['search'] ?? '',
        'stato' => $_GET['stato'] ?? '',
        'metodo' => $_GET['metodo'] ?? '',
        'mese' => $_GET['mese'] ?? '',
        'anno' => $_GET['anno'] ?? '',
        'aliquota_iva' => $_GET['aliquota_iva'] ?? '22',
        'page' => $_GET['page'] ?? '1',
    ];
    $merged = array_merge($defaults, $overrides);
    // Remove empty values except tipo and page
    foreach ($merged as $k => $v) {
        if ($v === '' && $k !== 'tipo' && $k !== 'page') unset($merged[$k]);
    }
    return http_build_query($merged);
}

require __DIR__ . '/../../layouts/header.php';
?>
<style>
.txn-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.txn-tabs { display:flex; gap:0; margin-top:14px; }
.txn-tab { padding:10px 20px; font-size:14px; font-weight:600; text-decoration:none; color:#64748b; background:#f1f5f9; border:1px solid #e2e8f0; border-bottom:none; border-radius:8px 8px 0 0; transition:all .15s; }
.txn-tab:hover { background:#e2e8f0; color:#334155; }
.txn-tab.active { background:#fff; color:#1e40af; border-color:#d1d5db; position:relative; }
.txn-tab.active::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px; background:#fff; }
.txn-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:14px 0; }
.txn-filters select, .txn-filters input[type="text"], .txn-filters input[type="number"] {
    padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff;
}
.txn-filters input[type="text"] { min-width:200px; }
.txn-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
.txn-kpi { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; background:#fff; }
.txn-kpi .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.03em; }
.txn-kpi .value { font-size:22px; font-weight:700; color:#0f172a; margin-top:2px; }
.txn-table { width:100%; border-collapse:collapse; font-size:13px; }
.txn-table th, .txn-table td { border-bottom:1px solid #e5e7eb; padding:8px 10px; text-align:left; white-space:nowrap; }
.txn-table th { background:#f8fafc; color:#334155; font-weight:700; position:sticky; top:0; }
.txn-table tbody tr:hover { background:#f0f9ff; }
.txn-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; }
.txn-badge.succeeded, .txn-badge.pagata { background:#dcfce7; color:#166534; }
.txn-badge.pending, .txn-badge.emessa, .txn-badge.bozza { background:#fef3c7; color:#92400e; }
.txn-badge.failed, .txn-badge.scaduta { background:#fee2e2; color:#991b1b; }
.txn-pag { display:flex; justify-content:space-between; align-items:center; margin-top:14px; flex-wrap:wrap; gap:10px; }
.txn-pag-info { font-size:13px; color:#64748b; }
.txn-pag-btns { display:flex; gap:4px; }
.txn-pag-btns a, .txn-pag-btns span {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:34px; height:34px; padding:0 8px;
    border:1px solid #d1d5db; border-radius:6px; font-size:13px; text-decoration:none;
    color:#374151; background:#fff; transition:all .12s;
}
.txn-pag-btns a:hover { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
.txn-pag-btns span.current { background:#3b82f6; color:#fff; border-color:#3b82f6; font-weight:700; }
.txn-pag-btns span.disabled { color:#cbd5e1; cursor:default; }
.txn-detail-link { color:#2563eb; text-decoration:none; font-size:12px; }
.txn-detail-link:hover { text-decoration:underline; }
.txn-table-wrap { overflow-x:auto; max-height:70vh; }
@media(max-width:768px) {
    .txn-filters input[type="text"] { min-width:140px; width:100%; }
    .txn-kpi .value { font-size:18px; }
}
</style>
<div class="wrap">
    <div class="card" style="padding-bottom:0;">
        <div class="txn-header">
            <div>
                <h2 style="margin:0;">Dettaglio Transazioni</h2>
                <p class="muted" style="margin:4px 0 0 0;">Tutte le transazioni con paginazione e filtri avanzati.</p>
            </div>
            <div style="display:flex;gap:8px;">
                <?php
                $exportParams = txn_qs(['export' => 'csv']);
                ?>
                <a class="btn secondary" href="?<?php echo htmlspecialchars($exportParams); ?>">Esporta CSV</a>
                <a class="btn secondary" href="<?php echo htmlspecialchars($config['app']['base_url']); ?>/modules/dashboard/index.php">← Dashboard</a>
            </div>
        </div>
        <div class="txn-tabs">
            <a class="txn-tab <?php echo $tipo === 'pagamenti' ? 'active' : ''; ?>"
               href="?<?php echo htmlspecialchars(txn_qs(['tipo' => 'pagamenti', 'page' => '1'])); ?>">
                Pagamenti Stripe
            </a>
            <a class="txn-tab <?php echo $tipo === 'fatture' ? 'active' : ''; ?>"
               href="?<?php echo htmlspecialchars(txn_qs(['tipo' => 'fatture', 'page' => '1'])); ?>">
                Fatture
            </a>
        </div>
    </div>

    <div class="card" style="border-top-left-radius:0;">
        <form method="GET" class="txn-filters">
            <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo); ?>">
            <input type="text" name="search" placeholder="Cerca cliente, email, ID..." value="<?php echo htmlspecialchars($search); ?>">

            <?php if ($tipo === 'pagamenti'): ?>
                <select name="stato">
                    <option value="">Tutti gli stati</option>
                    <option value="succeeded" <?php echo $statoFilter === 'succeeded' ? 'selected' : ''; ?>>Riuscito</option>
                    <option value="pending" <?php echo $statoFilter === 'pending' ? 'selected' : ''; ?>>In attesa</option>
                    <option value="failed" <?php echo $statoFilter === 'failed' ? 'selected' : ''; ?>>Fallito</option>
                </select>
            <?php else: ?>
                <select name="stato">
                    <option value="">Tutti gli stati</option>
                    <option value="pagata" <?php echo $statoFilter === 'pagata' ? 'selected' : ''; ?>>Pagata</option>
                    <option value="emessa" <?php echo $statoFilter === 'emessa' ? 'selected' : ''; ?>>Emessa</option>
                    <option value="bozza" <?php echo $statoFilter === 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                    <option value="scaduta" <?php echo $statoFilter === 'scaduta' ? 'selected' : ''; ?>>Scaduta</option>
                </select>
            <?php endif; ?>

            <select name="mese">
                <option value="">Tutti i mesi</option>
                <?php foreach ($mesi as $num => $nome): ?>
                    <option value="<?php echo $num; ?>" <?php echo $num === $meseFilter ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="anno">
                <option value="">Tutti gli anni</option>
                <?php for ($a = (int)date('Y'); $a >= 2020; $a--): ?>
                    <option value="<?php echo $a; ?>" <?php echo $a === $annoFilter ? 'selected' : ''; ?>><?php echo $a; ?></option>
                <?php endfor; ?>
            </select>

            <?php if ($tipo === 'pagamenti'): ?>
                <input type="number" name="aliquota_iva" min="0" max="100" step="0.01"
                       value="<?php echo htmlspecialchars(number_format($aliquotaIva, 2, '.', '')); ?>"
                       style="width:90px;" title="Aliquota IVA %">
            <?php endif; ?>

            <button type="submit" style="padding:7px 16px;background:#3b82f6;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Filtra</button>
            <?php if ($search !== '' || $statoFilter !== '' || $meseFilter > 0 || $annoFilter > 0 || $metodoFilter !== ''): ?>
                <a href="?tipo=<?php echo htmlspecialchars($tipo); ?>" style="font-size:13px;color:#ef4444;">Reset filtri</a>
            <?php endif; ?>
        </form>

        <!-- KPI -->
        <div class="txn-kpi-grid">
            <?php if ($tipo === 'pagamenti'): ?>
                <div class="txn-kpi">
                    <div class="label">Totale incassato</div>
                    <div class="value" style="color:#166534;">&euro;<?php echo euro_from_cents_t($kpi['total_cents']); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Transazioni</div>
                    <div class="value"><?php echo number_format($kpi['count'], 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Riuscite</div>
                    <div class="value" style="color:#166534;"><?php echo number_format($kpi['succeeded'], 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">In attesa</div>
                    <div class="value" style="color:#92400e;"><?php echo number_format($kpi['pending'], 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Fallite</div>
                    <div class="value" style="color:#991b1b;"><?php echo number_format($kpi['failed'], 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Con SDI inviata</div>
                    <div class="value" style="color:#166534;"><?php echo number_format($paymentCoverage['invoice_done'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Da completare</div>
                    <div class="value" style="color:#92400e;"><?php echo number_format($paymentCoverage['sdi_pending'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Fattura mancante</div>
                    <div class="value" style="color:#991b1b;"><?php echo number_format($paymentCoverage['invoice_missing'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Dati mancanti</div>
                    <div class="value" style="color:#b45309;"><?php echo number_format($paymentCoverage['blocked_data'] ?? 0, 0, ',', '.'); ?></div>
                </div>
            <?php else: ?>
                <div class="txn-kpi">
                    <div class="label">Totale fatturato</div>
                    <div class="value" style="color:#166534;">&euro;<?php echo number_format($kpi['total_euro'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Fatture</div>
                    <div class="value"><?php echo number_format($kpi['count'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Pagate</div>
                    <div class="value" style="color:#166534;"><?php echo number_format($kpi['pagate'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Emesse</div>
                    <div class="value" style="color:#92400e;"><?php echo number_format($kpi['emesse'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Scadute</div>
                    <div class="value" style="color:#991b1b;"><?php echo number_format($kpi['scadute'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">SDI inviate</div>
                    <div class="value" style="color:#166534;"><?php echo number_format($invoiceCoverage['sdi_done'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">SDI da inviare</div>
                    <div class="value" style="color:#92400e;"><?php echo number_format($invoiceCoverage['sdi_pending'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">Anagrafiche incomplete</div>
                    <div class="value" style="color:#b45309;"><?php echo number_format($invoiceCoverage['blocked_data'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="txn-kpi">
                    <div class="label">FIC mancanti</div>
                    <div class="value" style="color:#991b1b;"><?php echo number_format($invoiceCoverage['fic_missing'] ?? 0, 0, ',', '.'); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- TABLE -->
        <div class="txn-table-wrap">
            <table class="txn-table">
                <?php if ($tipo === 'pagamenti'): ?>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Stripe ID</th>
                            <th style="text-align:right;">Lordo</th>
                            <th style="text-align:right;">Imponibile</th>
                            <th style="text-align:right;">IVA</th>
                            <th>Stato</th>
                            <th>Metodo</th>
                            <th>Fattura</th>
                            <th>SDI</th>
                            <th>Presupposti</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" class="muted" style="text-align:center;padding:24px;">Nessun pagamento trovato.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $split = vat_split_t((int)($r['importo'] ?? 0), $aliquotaIva);
                            $cliente = trim((string)($r['cliente_nome'] ?? ''));
                            $cliente = $cliente !== '' ? $cliente : ($r['cliente_email'] ?? '-');
                            $invoiceBadge = txn_payment_invoice_status($r);
                            $precheckBadge = txn_payment_prerequisite_status($r);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)($r['created_at'] ?? 'now')))); ?></td>
                                <td title="<?php echo htmlspecialchars($r['cliente_email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($cliente, 0, 28, '…')); ?>
                                </td>
                                <td>
                                    <span style="font-family:monospace;font-size:11px;" title="<?php echo htmlspecialchars($r['stripe_id'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(mb_strimwidth((string)($r['stripe_id'] ?? '-'), 0, 22, '…')); ?>
                                    </span>
                                </td>
                                <td style="text-align:right;font-weight:600;">&euro;<?php echo euro_from_cents_t($split['gross']); ?></td>
                                <td style="text-align:right;">&euro;<?php echo euro_from_cents_t($split['taxable']); ?></td>
                                <td style="text-align:right;">&euro;<?php echo euro_from_cents_t($split['vat']); ?></td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($r['stato'] ?? ''); ?>">
                                        <?php
                                        $statoLabels = ['succeeded' => 'Riuscito', 'pending' => 'In attesa', 'failed' => 'Fallito'];
                                        echo htmlspecialchars($statoLabels[$r['stato'] ?? ''] ?? $r['stato'] ?? '-');
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($r['metodo'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!empty($r['fattura_numero'])): ?>
                                        <a class="txn-detail-link" href="<?php echo htmlspecialchars($config['app']['base_url']); ?>/modules/fatture/index.php?modifica=<?php echo (int)$r['fattura_id']; ?>">
                                            <?php echo htmlspecialchars($r['fattura_numero']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($invoiceBadge['class']); ?>" title="<?php echo htmlspecialchars($invoiceBadge['title']); ?>">
                                        <?php echo htmlspecialchars($invoiceBadge['label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($precheckBadge['class']); ?>" title="<?php echo htmlspecialchars($precheckBadge['title']); ?>">
                                        <?php echo htmlspecialchars($precheckBadge['label']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php else: ?>
                    <thead>
                        <tr>
                            <th>N. Fattura</th>
                            <th>Data</th>
                            <th>Scadenza</th>
                            <th>Cliente</th>
                            <th style="text-align:right;">Totale</th>
                            <th>Stato</th>
                            <th>Metodo pag.</th>
                            <th>FIC</th>
                            <th>SDI</th>
                            <th>Presupposti</th>
                            <th>Stripe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" class="muted" style="text-align:center;padding:24px;">Nessuna fattura trovata.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $cliente = trim((string)($r['cliente_nome'] ?? ''));
                            $cliente = $cliente !== '' ? $cliente : ($r['cliente_email'] ?? '-');
                            $scaduta = ($r['stato'] !== 'pagata' && !empty($r['data_scadenza']) && $r['data_scadenza'] < date('Y-m-d'));
                            $statoDisplay = $scaduta ? 'scaduta' : ($r['stato'] ?? 'bozza');
                            $sdiBadge = txn_invoice_sdi_status($r);
                            $precheckBadge = txn_invoice_prerequisite_status($r);
                            ?>
                            <tr>
                                <td>
                                    <a class="txn-detail-link" href="<?php echo htmlspecialchars($config['app']['base_url']); ?>/modules/fatture/index.php?modifica=<?php echo (int)$r['id']; ?>" style="font-weight:600;">
                                        <?php echo htmlspecialchars($r['numero'] ?? '-'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($r['data_emissione'] ?? '-'); ?></td>
                                <td><?php echo !empty($r['data_scadenza']) ? htmlspecialchars($r['data_scadenza']) : '—'; ?></td>
                                <td title="<?php echo htmlspecialchars($r['cliente_email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($cliente, 0, 28, '…')); ?>
                                </td>
                                <td style="text-align:right;font-weight:600;">&euro;<?php echo number_format((float)($r['totale'] ?? 0), 2, ',', '.'); ?></td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($statoDisplay); ?>">
                                        <?php echo htmlspecialchars(ucfirst($statoDisplay)); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($r['metodo_pagamento'] ?? '—'); ?></td>
                                <td>
                                    <?php if (!empty($r['fic_document_id'])): ?>
                                        <span class="txn-badge succeeded" title="<?php echo htmlspecialchars($r['fic_status'] ?? ''); ?>">FIC ✓</span>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($sdiBadge['class']); ?>" title="<?php echo htmlspecialchars($sdiBadge['title']); ?>">
                                        <?php echo htmlspecialchars($sdiBadge['label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="txn-badge <?php echo htmlspecialchars($precheckBadge['class']); ?>" title="<?php echo htmlspecialchars($precheckBadge['title']); ?>">
                                        <?php echo htmlspecialchars($precheckBadge['label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($r['stripe_payment_intent_id'])): ?>
                                        <span style="font-family:monospace;font-size:11px;" title="<?php echo htmlspecialchars($r['stripe_payment_intent_id']); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($r['stripe_payment_intent_id'], 0, 16, '…')); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="txn-pag">
            <div class="txn-pag-info">
                <?php
                $from = $totalRows > 0 ? $offset + 1 : 0;
                $to = min($offset + $perPage, $totalRows);
                ?>
                Mostra <?php echo $from; ?>–<?php echo $to; ?> di <?php echo number_format($totalRows, 0, ',', '.'); ?> risultati
                &middot; Pagina <?php echo $page; ?> di <?php echo $totalPages; ?>
            </div>
            <div class="txn-pag-btns">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo htmlspecialchars(txn_qs(['page' => '1'])); ?>" title="Prima">&laquo;</a>
                    <a href="?<?php echo htmlspecialchars(txn_qs(['page' => (string)($page - 1)])); ?>" title="Precedente">&lsaquo;</a>
                <?php else: ?>
                    <span class="disabled">&laquo;</span>
                    <span class="disabled">&lsaquo;</span>
                <?php endif; ?>

                <?php
                $range = 2;
                $startPage = max(1, $page - $range);
                $endPage = min($totalPages, $page + $range);
                if ($startPage > 1) echo '<span class="disabled">…</span>';
                for ($p = $startPage; $p <= $endPage; $p++):
                    if ($p === $page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo htmlspecialchars(txn_qs(['page' => (string)$p])); ?>"><?php echo $p; ?></a>
                    <?php endif;
                endfor;
                if ($endPage < $totalPages) echo '<span class="disabled">…</span>';
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo htmlspecialchars(txn_qs(['page' => (string)($page + 1)])); ?>" title="Successiva">&rsaquo;</a>
                    <a href="?<?php echo htmlspecialchars(txn_qs(['page' => (string)$totalPages])); ?>" title="Ultima">&raquo;</a>
                <?php else: ?>
                    <span class="disabled">&rsaquo;</span>
                    <span class="disabled">&raquo;</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>
