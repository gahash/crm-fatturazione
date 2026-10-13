<?php

function tdg_sdi_normalize_reason_text(string $message): string
{
    $reason = trim($message);
    if ($reason === '') {
        return '';
    }

    $normalized = strtolower($reason);

    if ((strpos($normalized, 'http 409') !== false)
        && (strpos($normalized, 'limite di documenti inclusi') !== false
            || strpos($normalized, 'superato il limite di documenti') !== false)) {
        $year = '';
        if (preg_match('/anno\s+(\d{4})/i', $reason, $matches)) {
            $year = $matches[1];
        }

        return 'Fatture in Cloud: limite documenti della licenza'
            . ($year !== '' ? ' ' . $year : '')
            . ' superato (HTTP 409).';
    }

    if (strpos($normalized, 'pagamento stripe registrato: invio automatico non eseguito (anagrafica incompleta:') === 0) {
        $detail = trim((string)preg_replace('/^pagamento stripe registrato: invio automatico non eseguito \(anagrafica incompleta:\s*/i', '', $reason));
        $detail = rtrim($detail, ') .');
        return 'Invio automatico non eseguito: anagrafica cliente incompleta' . ($detail !== '' ? ' (' . $detail . ')' : '.') ;
    }

    if (strpos($normalized, 'pagamento stripe registrato: invio automatico non eseguito (company_id fic mancante') === 0) {
        return 'Invio automatico non eseguito: configurazione Fatture in Cloud incompleta (company_id mancante).';
    }

    if (strpos($normalized, 'automazione disattiv') !== false) {
        return 'Invio automatico non eseguito: automazione SDI disattivata.';
    }

    if (strpos($normalized, 'fic_duplicate_not_recoverable') !== false
        || strpos($normalized, 'duplicato fic non risolto') !== false
        || strpos($normalized, 'duplicate_unresolved') !== false) {
        return 'Duplicato FIC non risolto: il numero fattura esiste gia su Fatture in Cloud.';
    }

    if (strpos($normalized, 'tentativo di invio per questa fattura') !== false
        || strpos($normalized, 'gia in corso un tentativo di invio') !== false
        || strpos($normalized, 'già in corso un tentativo di invio') !== false
        || strpos($normalized, 'already in progress') !== false) {
        return 'Invio SDI gia in lavorazione.';
    }

    return $reason;
}

function tdg_sdi_is_license_limit_reason(string $message): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        return false;
    }

    return strpos($normalized, 'http 409') !== false
        && (strpos($normalized, 'limite di documenti inclusi') !== false
            || strpos($normalized, 'superato il limite di documenti') !== false);
}

function tdg_sdi_issue_bucket_case_sql(string $alias = 'f'): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';

    return "CASE
                WHEN LOWER(COALESCE({$prefix}fic_status, '')) = 'duplicate_unresolved' OR LOWER(COALESCE({$prefix}ei_status, '')) = 'duplicate_unresolved' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%fic_duplicate_not_recoverable%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%fic_duplicate_not_recoverable%' THEN 'Duplicato FIC'
                WHEN ((LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%http 409%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%http 409%') AND (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%limite di documenti inclusi%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%superato il limite di documenti%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%limite di documenti inclusi%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%superato il limite di documenti%')) THEN 'Limite licenza FIC raggiunto'
                WHEN LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%dati cliente insufficienti%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (anagrafica incompleta:%' THEN 'Dati cliente incompleti'
                WHEN LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%p.iva%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%codice sdi%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%pec%' THEN 'Dati fiscali mancanti o errati'
                WHEN LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%xml%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%validation%' THEN 'Validazione XML / dati FIC'
                WHEN LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (company_id fic mancante%' THEN 'Configurazione FIC mancante'
                WHEN LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivata%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivato%' THEN 'Automazione SDI disattivata'
                ELSE 'Errore invio SDI da verificare'
            END";
}

function tdg_sdi_issue_bucket_from_reason(string $reason, array $row = []): string
{
    $normalizedReason = strtolower(tdg_sdi_normalize_reason_text($reason));
    $mailError = strtolower(trim((string)($row['mail_error'] ?? '')));

    if (tdg_sdi_is_license_limit_reason($reason)) {
        return 'Limite licenza FIC raggiunto';
    }
    if (strpos($normalizedReason, 'duplicato') !== false || strpos($normalizedReason, 'duplicate_unresolved') !== false) {
        return 'Duplicato FIC';
    }
    if (strpos($normalizedReason, 'dati cliente insufficienti') !== false || strpos($normalizedReason, 'anagrafica cliente incompleta') !== false || strpos($normalizedReason, 'anagrafica incompleta') !== false) {
        return 'Dati cliente incompleti';
    }
    if (strpos($normalizedReason, 'p.iva') !== false || strpos($normalizedReason, 'codice sdi') !== false || strpos($normalizedReason, 'pec') !== false) {
        return 'Dati fiscali mancanti o errati';
    }
    if (strpos($normalizedReason, 'xml') !== false || strpos($normalizedReason, 'validation') !== false) {
        return 'Validazione XML / dati FIC';
    }
    if (strpos($mailError, 'company_id fic mancante') !== false || strpos($normalizedReason, 'company_id fic mancante') !== false || strpos($normalizedReason, 'configurazione fatture in cloud incompleta') !== false) {
        return 'Configurazione FIC mancante';
    }
    if (strpos($mailError, 'automazione disattiv') !== false || strpos($normalizedReason, 'automazione sdi disattivata') !== false) {
        return 'Automazione SDI disattivata';
    }

    return 'Errore invio SDI da verificare';
}

function fatture_index_sdi_issue_reason(array $row): string
{
    $eiError = trim((string)($row['ei_error'] ?? ''));
    if ($eiError !== '') {
        return tdg_sdi_normalize_reason_text($eiError);
    }

    $ficStatus = strtolower(trim((string)($row['fic_status'] ?? '')));
    $eiStatus = strtolower(trim((string)($row['ei_status'] ?? '')));
    if ($ficStatus === 'duplicate_unresolved' || $eiStatus === 'duplicate_unresolved') {
        return tdg_sdi_normalize_reason_text('duplicate_unresolved');
    }

    $mailError = trim((string)($row['mail_error'] ?? ''));
    if ($mailError !== '') {
        return tdg_sdi_normalize_reason_text($mailError);
    }

    $lastMsg = trim((string)($row['auto_sdi_last_event_msg'] ?? ''));
    if ($lastMsg !== '') {
        return tdg_sdi_normalize_reason_text($lastMsg);
    }

    if ($eiStatus === 'error') {
        return 'Invio SDI fallito: verifica dati cliente e stato del documento su Fatture in Cloud.';
    }

    return 'Invio SDI non completato: motivo non classificato automaticamente.';
}

function fatture_index_is_sdi_in_progress_message(string $message): bool
{
    $reason = strtolower(trim($message));
    if ($reason === '') {
        return false;
    }

    return (strpos($reason, 'tentativo di invio per questa fattura') !== false)
        || (strpos($reason, 'gia in corso un tentativo di invio') !== false)
        || (strpos($reason, 'già in corso un tentativo di invio') !== false)
        || (strpos($reason, 'already in progress') !== false);
}

function fatture_index_sdi_in_progress_sql(string $alias = 'f'): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "(LOWER(COALESCE({$prefix}ei_status, '')) = 'processing' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%tentativo di invio per questa fattura%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%gia in corso un tentativo di invio%')";
}

function fatture_index_sdi_in_progress_threshold_hours(): int
{
    return 6;
}

function fatture_index_sdi_last_event_at_sql(string $alias = 'f'): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "(SELECT fl.creato_il
        FROM fatture_log fl
        WHERE fl.fattura_id = {$prefix}id
          AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')
        ORDER BY fl.creato_il DESC, fl.id DESC
        LIMIT 1)";
}

function fatture_index_sdi_in_progress_recent_sql(string $alias = 'f'): string
{
    $hours = fatture_index_sdi_in_progress_threshold_hours();
    $lastEventAtSql = fatture_index_sdi_last_event_at_sql($alias);
    return '(' . fatture_index_sdi_in_progress_sql($alias) . " AND {$lastEventAtSql} IS NOT NULL AND {$lastEventAtSql} >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR))";
}

function fatture_index_sdi_in_progress_stale_sql(string $alias = 'f'): string
{
    $hours = fatture_index_sdi_in_progress_threshold_hours();
    $lastEventAtSql = fatture_index_sdi_last_event_at_sql($alias);
    return '(' . fatture_index_sdi_in_progress_sql($alias) . " AND ({$lastEventAtSql} IS NULL OR {$lastEventAtSql} < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)))";
}

function fatture_index_is_stale_in_progress_row(array $row): bool
{
    if (!fatture_index_is_sdi_in_progress_message((string)($row['ei_error'] ?? ''))
        && !fatture_index_is_sdi_in_progress_message((string)($row['auto_sdi_last_event_msg'] ?? ''))
        && strtolower(trim((string)($row['ei_status'] ?? ''))) !== 'processing') {
        return false;
    }

    $lastEventAt = trim((string)($row['auto_sdi_last_event_at'] ?? ''));
    if ($lastEventAt === '') {
        return true;
    }

    $lastEventTs = strtotime($lastEventAt);
    if ($lastEventTs === false) {
        return true;
    }

    return (time() - $lastEventTs) >= (fatture_index_sdi_in_progress_threshold_hours() * 3600);
}

function fatture_index_sdi_issue_bucket(array $row): string
{
    $reason = fatture_index_sdi_issue_reason($row);

    if (fatture_index_is_stale_in_progress_row($row)) {
        return 'Invio SDI fermo oltre 6 ore';
    }

    if (fatture_index_is_sdi_in_progress_message((string)($row['ei_error'] ?? '')) || fatture_index_is_sdi_in_progress_message((string)($row['auto_sdi_last_event_msg'] ?? '')) || strtolower(trim((string)($row['ei_status'] ?? ''))) === 'processing') {
        return 'SDI gia in lavorazione';
    }

    return tdg_sdi_issue_bucket_from_reason($reason, $row);
}

function fatture_load_topbar_alerts(PDO $pdo, string $docType = 'invoice', int $issueLimit = 5, int $inProgressLimit = 4): array
{
    $summary = [
        'issue_count' => 0,
        'in_progress_count' => 0,
        'issue_buckets' => [],
        'issue_rows' => [],
        'in_progress_rows' => [],
    ];

    $issueWhere = "f.document_type = :docType
          AND (
              LOWER(COALESCE(f.ei_status, '')) = 'error'
              OR (f.ei_error IS NOT NULL AND TRIM(f.ei_error) <> '')
              OR (f.mail_error IS NOT NULL AND f.mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')
          )
          AND NOT " . fatture_index_sdi_in_progress_sql('f');

    $sdiIssueCountStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture f WHERE {$issueWhere}");
    $sdiIssueCountStmt->execute([':docType' => $docType]);
    $summary['issue_count'] = (int)$sdiIssueCountStmt->fetchColumn();

    $sdiInProgressCountStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture f WHERE f.document_type = :docType AND " . fatture_index_sdi_in_progress_sql('f'));
    $sdiInProgressCountStmt->execute([':docType' => $docType]);
    $summary['in_progress_count'] = (int)$sdiInProgressCountStmt->fetchColumn();

    if ($summary['issue_count'] > 0) {
        $issueBucketStmt = $pdo->prepare("\n        SELECT\n            " . tdg_sdi_issue_bucket_case_sql('f') . " AS reason_bucket,\n            COUNT(*) AS total_rows\n        FROM fatture f\n        WHERE {$issueWhere}\n        GROUP BY reason_bucket\n        ORDER BY total_rows DESC, reason_bucket ASC\n        LIMIT 4\n    ");
        $issueBucketStmt->execute([':docType' => $docType]);
        $summary['issue_buckets'] = $issueBucketStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    }

    if ($summary['issue_count'] > 0 && $issueLimit > 0) {
        $issueRowsStmt = $pdo->prepare("\n        SELECT f.id, f.numero, f.data_emissione, f.ei_status, f.fic_status, f.ei_error, f.mail_error,\n               c.nome,\n               (\n                   SELECT fl.messaggio\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_msg,\n               (\n                   SELECT fl.creato_il\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_at\n        FROM fatture f\n        JOIN clienti c ON c.id = f.cliente_id\n        WHERE {$issueWhere}\n        ORDER BY f.data_emissione DESC, f.id DESC\n        LIMIT {$issueLimit}\n    ");
        $issueRowsStmt->execute([':docType' => $docType]);
        $summary['issue_rows'] = $issueRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($summary['issue_rows'] as &$row) {
            $row['alert_reason'] = fatture_index_sdi_issue_reason($row);
            $row['alert_bucket'] = fatture_index_sdi_issue_bucket($row);
        }
        unset($row);
    }

    if ($summary['in_progress_count'] > 0 && $inProgressLimit > 0) {
        $inProgressRowsStmt = $pdo->prepare("\n        SELECT f.id, f.numero, f.data_emissione, f.ei_status, f.fic_status, f.ei_error, f.mail_error,\n               c.nome,\n               (\n                   SELECT fl.messaggio\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_msg,\n               (\n                   SELECT fl.creato_il\n                   FROM fatture_log fl\n                   WHERE fl.fattura_id = f.id\n                     AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')\n                   ORDER BY fl.creato_il DESC, fl.id DESC\n                   LIMIT 1\n               ) AS auto_sdi_last_event_at\n        FROM fatture f\n        JOIN clienti c ON c.id = f.cliente_id\n        WHERE f.document_type = :docType\n          AND " . fatture_index_sdi_in_progress_sql('f') . "\n        ORDER BY f.data_emissione DESC, f.id DESC\n        LIMIT {$inProgressLimit}\n    ");
        $inProgressRowsStmt->execute([':docType' => $docType]);
        $summary['in_progress_rows'] = $inProgressRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($summary['in_progress_rows'] as &$row) {
            $row['alert_reason'] = fatture_index_sdi_issue_reason($row);
            $row['alert_bucket'] = fatture_index_sdi_issue_bucket($row);
        }
        unset($row);
    }

    return $summary;
}