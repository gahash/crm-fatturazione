<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_alert_helpers.php';
require_once __DIR__ . '/fic_mail_helpers.php';

function fic_delivery_report_is_sdi_sent(?string $status): bool
{
    $status = strtolower(trim((string)$status));
    return in_array($status, ['sent', 'processing', 'accepted'], true);
}

function fic_delivery_report_has_data_issue(array $fattura): array
{
    $precheck = fic_einvoice_missing_data_error($fattura);
    if ($precheck !== null) {
        return [true, $precheck];
    }

    $errors = trim((string)($fattura['ei_error'] ?? ''));
    if ($errors === '') {
        $errors = trim((string)($fattura['mail_error'] ?? ''));
    } else {
        $mailError = trim((string)($fattura['mail_error'] ?? ''));
        if ($mailError !== '') {
            $errors .= ' | ' . $mailError;
        }
    }

    if ($errors === '') {
        return [false, ''];
    }

    $haystack = strtolower($errors);
    $dataPatterns = [
        'dati cliente insufficienti',
        'anagrafica incompleta',
        'partita iva',
        'p.iva',
        'piva',
        'codice sdi',
        'codice destinatario',
        'pec',
        'email non valida',
        'email cliente non valida',
        'vat_number',
        'vat number',
        'verifica xml fallita',
        'xml non valido',
    ];

    foreach ($dataPatterns as $pattern) {
        if (strpos($haystack, $pattern) !== false) {
            return [true, tdg_sdi_normalize_reason_text(substr($errors, 0, 220))];
        }
    }

    return [false, ''];
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$autoSendFilter = isset($_GET['auto_send']) ? trim((string)$_GET['auto_send']) : '';
$sdiFlow = isset($_GET['sdi_flow']) ? trim((string)$_GET['sdi_flow']) : '';

$params = [':docType' => 'invoice'];
$where = " WHERE f.document_type = :docType";

if ($q !== '') {
    $where .= " AND (f.numero LIKE :q_num OR c.nome LIKE :q_name OR c.email LIKE :q_email)";
    $params[':q_num'] = '%' . $q . '%';
    $params[':q_name'] = '%' . $q . '%';
    $params[':q_email'] = '%' . $q . '%';
}

if ($autoSendFilter === 'stripe_skipped') {
    $where .= " AND f.stato = 'pagata' AND f.metodo_pagamento = 'Stripe' AND f.mail_status = 'pending' AND f.mail_error LIKE :mail_error";
    $params[':mail_error'] = 'Pagamento Stripe registrato: invio automatico non eseguito%';
}

if ($sdiFlow === 'ready') {
    $where .= " AND f.fic_document_id IS NOT NULL AND TRIM(f.fic_document_id) <> ''";
    $where .= " AND (f.fic_status IS NULL OR f.fic_status <> 'duplicate_unresolved')";
    $where .= " AND (f.ei_status IS NULL OR TRIM(f.ei_status) = '' OR LOWER(f.ei_status) NOT IN ('sent','processing','accepted'))";
} elseif ($sdiFlow === 'sent') {
    $where .= " AND LOWER(COALESCE(f.ei_status, '')) IN ('sent','processing','accepted')";
} elseif ($sdiFlow === 'issues') {
    $where .= " AND (LOWER(COALESCE(f.ei_status, '')) = 'error' OR LOWER(COALESCE(f.fic_status, '')) = 'duplicate_unresolved' OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> ''))";
}

$sql = "SELECT
            f.id,
            f.numero,
            f.data_emissione,
            f.totale,
            f.stato,
            f.mail_status,
            f.mail_error,
            f.ei_status,
            f.ei_error,
            f.fic_document_id,
            c.nome,
            c.email AS cliente_email,
            c.piva,
            c.codice_sdi,
            c.pec
        FROM fatture f
        JOIN clienti c ON c.id = f.cliente_id
        {$where}
        ORDER BY f.data_emissione DESC, f.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'ricevute' => 0,
    'non_ricevute_dati' => 0,
    'non_ricevute_altro' => 0,
];

$reportRows = [];
foreach ($rows as $r) {
    $sdiSent = fic_delivery_report_is_sdi_sent($r['ei_status'] ?? '');
    $mailSent = strtolower(trim((string)($r['mail_status'] ?? ''))) === 'sent';
    $received = $sdiSent || $mailSent;

    [$hasDataIssue, $dataIssueReason] = fic_delivery_report_has_data_issue($r);

    if ($received) {
        $summary['ricevute']++;
        $deliveryStatus = $sdiSent ? 'RICEVUTA (SDI)' : 'RICEVUTA (EMAIL)';
        $reason = $sdiSent
            ? 'Documento in flusso SDI (' . strtolower(trim((string)($r['ei_status'] ?? 'sent'))) . ')'
            : 'Email inviata al cliente';
    } elseif ($hasDataIssue) {
        $summary['non_ricevute_dati']++;
        $deliveryStatus = 'NON RICEVUTA - DATI NON CORRETTI';
        $reason = $dataIssueReason !== '' ? $dataIssueReason : 'Dati cliente non validi o incompleti';
    } else {
        $summary['non_ricevute_altro']++;
        $reason = trim((string)($r['ei_error'] ?? ''));
        if ($reason === '') {
            $reason = trim((string)($r['mail_error'] ?? ''));
        }
        if ($reason === '') {
            $reason = 'Nessun invio registrato';
        }
        $reason = tdg_sdi_normalize_reason_text($reason);
        $deliveryStatus = tdg_sdi_is_license_limit_reason($reason)
            ? 'NON RICEVUTA - BLOCCO FIC'
            : 'NON RICEVUTA - ALTRO';
    }

    $reportRows[] = [
        'id' => (int)$r['id'],
        'numero' => (string)($r['numero'] ?? ''),
        'data_emissione' => (string)($r['data_emissione'] ?? ''),
        'cliente' => (string)($r['nome'] ?? ''),
        'email_cliente' => (string)($r['cliente_email'] ?? ''),
        'totale' => number_format((float)($r['totale'] ?? 0), 2, '.', ''),
        'stato_consegna' => $deliveryStatus,
        'motivo' => $reason,
        'stato_sdi' => (string)($r['ei_status'] ?? ''),
        'stato_email' => (string)($r['mail_status'] ?? ''),
        'piva' => (string)($r['piva'] ?? ''),
        'codice_sdi' => (string)($r['codice_sdi'] ?? ''),
        'pec' => (string)($r['pec'] ?? ''),
        'fic_document_id' => (string)($r['fic_document_id'] ?? ''),
    ];
}

$filename = 'report_consegna_fatture_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
if ($out === false) {
    http_response_code(500);
    exit('Impossibile creare il report CSV');
}

fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Report consegna fatture']);
fputcsv($out, ['Generato il', date('Y-m-d H:i:s')]);
fputcsv($out, ['Totale righe', count($reportRows)]);
fputcsv($out, ['Ricevute', $summary['ricevute']]);
fputcsv($out, ['Non ricevute per dati non corretti', $summary['non_ricevute_dati']]);
fputcsv($out, ['Non ricevute per altri motivi', $summary['non_ricevute_altro']]);
fputcsv($out, []);

fputcsv($out, [
    'ID',
    'Numero',
    'Data emissione',
    'Cliente',
    'Email cliente',
    'Totale',
    'Stato consegna',
    'Motivo',
    'Stato SDI',
    'Stato email',
    'P.IVA',
    'Codice SDI',
    'PEC',
    'ID documento FIC',
]);

foreach ($reportRows as $line) {
    fputcsv($out, [
        $line['id'],
        $line['numero'],
        $line['data_emissione'],
        $line['cliente'],
        $line['email_cliente'],
        $line['totale'],
        $line['stato_consegna'],
        $line['motivo'],
        $line['stato_sdi'],
        $line['stato_email'],
        $line['piva'],
        $line['codice_sdi'],
        $line['pec'],
        $line['fic_document_id'],
    ]);
}

fclose($out);
exit;
