<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../fatture/actions/fic_alert_helpers.php';

function clienti_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function clienti_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function clienti_silently_reconcile_duplicate_payment_intents(PDO $pdo): void
{
    try {
        $clearLinkedSql = "
            UPDATE fatture
            SET fic_status = CASE
                    WHEN LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved' THEN 'linked'
                    ELSE fic_status
                END,
                ei_status = CASE
                    WHEN LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved' THEN NULL
                    ELSE ei_status
                END,
                ei_error = CASE
                    WHEN LOWER(COALESCE(ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(ei_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE ei_error
                END,
                mail_error = CASE
                    WHEN LOWER(COALESCE(mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(mail_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE mail_error
                END,
                fic_synced_at = NOW()
            WHERE document_type = 'invoice'
              AND fic_document_id IS NOT NULL
              AND TRIM(fic_document_id) <> ''
              AND (
                    LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved'
                    OR LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved'
              )
        ";
        $pdo->exec($clearLinkedSql);

        if (!clienti_column_exists($pdo, 'fatture', 'stripe_payment_intent_id')) {
            return;
        }

        $sql = "
            UPDATE fatture f_old
            INNER JOIN (
                SELECT stripe_payment_intent_id, MAX(id) AS latest_id
                FROM fatture
                WHERE document_type = 'invoice'
                  AND stripe_payment_intent_id IS NOT NULL
                  AND TRIM(stripe_payment_intent_id) <> ''
                  AND fic_document_id IS NOT NULL
                  AND TRIM(fic_document_id) <> ''
                  AND LOWER(COALESCE(fic_status, '')) <> 'duplicate_unresolved'
                GROUP BY stripe_payment_intent_id
            ) map ON map.stripe_payment_intent_id = f_old.stripe_payment_intent_id
            INNER JOIN fatture f_new ON f_new.id = map.latest_id
            SET f_old.fic_document_id = f_new.fic_document_id,
                f_old.fic_status = CASE
                    WHEN TRIM(COALESCE(f_new.fic_status, '')) <> '' THEN f_new.fic_status
                    ELSE 'linked'
                END,
                f_old.fic_synced_at = NOW(),
                f_old.ei_status = CASE
                    WHEN LOWER(COALESCE(f_old.ei_status, '')) = 'duplicate_unresolved' THEN NULL
                    ELSE f_old.ei_status
                END,
                f_old.ei_error = CASE
                    WHEN LOWER(COALESCE(f_old.ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(f_old.ei_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE f_old.ei_error
                END,
                f_old.mail_error = CASE
                    WHEN LOWER(COALESCE(f_old.mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                        OR LOWER(COALESCE(f_old.mail_error, '')) LIKE '%duplicato fic non risolto%'
                    THEN NULL
                    ELSE f_old.mail_error
                END
            WHERE f_old.document_type = 'invoice'
              AND f_old.id <> f_new.id
              AND LOWER(COALESCE(f_old.fic_status, '')) = 'duplicate_unresolved'
              AND (f_old.fic_document_id IS NULL OR TRIM(f_old.fic_document_id) = '')
        ";

        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Riconciliazione silenziosa: non deve mai bloccare la pagina clienti.
    }
}

$search = '';
if (isset($_GET['search'])) {
    $search = trim((string)$_GET['search']);
} elseif (isset($_GET['q'])) {
    $search = trim((string)$_GET['q']);
}
$sdiAlertOnly = isset($_GET['sdi_alert']) && (string)($_GET['sdi_alert'] ?? '') === '1';
$sdiAlertReason = trim((string)($_GET['sdi_reason'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPageOptions = [10, 20, 30, 50, 100];
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : (isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20);
$perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 20;
$params = [];
$searchWhere = '';
if ($search !== '') {
    $fields = ['nome', 'email', 'azienda', 'telefono', 'indirizzo', 'piva'];
    $clauses = [];
    foreach ($fields as $i => $field) {
        $key = ':q' . $i;
        $clauses[] = "$field LIKE $key";
        $params[$key] = '%' . $search . '%';
    }
    $searchWhere = implode(' OR ', $clauses);
}

$where = $searchWhere !== '' ? " WHERE $searchWhere" : '';
$sdiIssuesByCliente = [];
$clienteInvoiceStatsByCliente = [];

clienti_silently_reconcile_duplicate_payment_intents($pdo);

if ($sdiAlertOnly) {
    $where .= ($where === '' ? ' WHERE ' : ' AND ') . "EXISTS (
        SELECT 1
        FROM fatture f_issue
        WHERE f_issue.cliente_id = clienti.id
          AND f_issue.document_type = 'invoice'
          AND (
              (f_issue.ei_error IS NOT NULL AND TRIM(f_issue.ei_error) <> '')
                  OR (
                          LOWER(COALESCE(f_issue.ei_status, '')) = 'duplicate_unresolved'
                          AND (f_issue.fic_document_id IS NULL OR TRIM(f_issue.fic_document_id) = '')
                      )
                  OR (
                          LOWER(COALESCE(f_issue.fic_status, '')) = 'duplicate_unresolved'
                          AND (f_issue.fic_document_id IS NULL OR TRIM(f_issue.fic_document_id) = '')
                      )
              OR (f_issue.mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')
          )
          AND NOT (
                (
                    LOWER(COALESCE(f_issue.ei_error, '')) LIKE '%dati cliente insufficienti per e-fattura%'
                    OR LOWER(COALESCE(f_issue.mail_error, '')) LIKE '%anagrafica incompleta%'
                )
                AND " . clienti_current_record_resolves_missing_data_sql('f_issue') . "
          )
          AND NOT " . clienti_sdi_resolved_sql('f_issue') . "
          AND NOT " . clienti_sdi_in_progress_sql('f_issue') . "
          AND NOT " . clienti_sdi_transient_db_error_sql('f_issue') . "
          AND " . clienti_sdi_reason_sql('f_issue', $sdiAlertReason) . "
    )";
}

$dbError = null;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clienti" . $where);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $ficDashboardEligibilitySql = "TRIM(COALESCE(piva, '')) <> ''"
        . " AND (TRIM(COALESCE(azienda, '')) <> '' OR TRIM(COALESCE(nome, '')) <> '')"
        . " AND (UPPER(TRIM(COALESCE(codice_sdi, ''))) REGEXP '^[A-Z0-9]{7}$' OR TRIM(COALESCE(pec, '')) REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$')";
    $ficDashboardWhere = $where . ($where === '' ? ' WHERE ' : ' AND ') . $ficDashboardEligibilitySql;
    $ficDashboardStmt = $pdo->prepare("SELECT COUNT(*) FROM clienti" . $ficDashboardWhere);
    $ficDashboardStmt->execute($params);
    $ficDashboardEligible = (int)$ficDashboardStmt->fetchColumn();

    $ficDashboardNotEligibleWhere = $where . ($where === '' ? ' WHERE ' : ' AND ') . 'NOT (' . $ficDashboardEligibilitySql . ')';
    $ficDashboardNotEligibleStmt = $pdo->prepare("SELECT COUNT(*) FROM clienti" . $ficDashboardNotEligibleWhere);
    $ficDashboardNotEligibleStmt->execute($params);
    $ficDashboardNotEligible = (int)$ficDashboardNotEligibleStmt->fetchColumn();

    $ficEligibilitySql = "piva IS NOT NULL AND TRIM(piva) <> ''"
        . " AND ((azienda IS NOT NULL AND TRIM(azienda) <> '') OR (nome IS NOT NULL AND TRIM(nome) <> ''))"
        . " AND ((codice_sdi IS NOT NULL AND TRIM(codice_sdi) <> '') OR (pec IS NOT NULL AND TRIM(pec) <> ''))";
    $ficWhere = " WHERE $ficEligibilitySql";
    if ($searchWhere !== '') {
        $ficWhere .= " AND $searchWhere";
    }
    $ficCountStmt = $pdo->prepare("SELECT COUNT(*) FROM clienti" . $ficWhere);
    $ficCountStmt->execute($params);
    $ficEligible = (int)$ficCountStmt->fetchColumn();

    $ficLinked = 0;
    if (clienti_table_exists($pdo, 'fic_clienti_map')) {
        $ficLinkedWhere = '';
        if ($searchWhere !== '') {
            $ficLinkedWhere = " WHERE $searchWhere";
        }
        $ficLinkedStmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clienti c JOIN fic_clienti_map m ON m.cliente_id = c.id" . $ficLinkedWhere);
        $ficLinkedStmt->execute($params);
        $ficLinked = (int)$ficLinkedStmt->fetchColumn();
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT * FROM clienti" . $where . " ORDER BY creato_il DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $clienti = $stmt->fetchAll();

    if (!empty($clienti)) {
        $clienteIds = array_values(array_unique(array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $clienti)));
        $clienteIds = array_values(array_filter($clienteIds, static function ($id) {
            return $id > 0;
        }));

        if (!empty($clienteIds)) {
            $placeholders = implode(',', array_fill(0, count($clienteIds), '?'));
            $hasStripePaymentIntent = clienti_column_exists($pdo, 'fatture', 'stripe_payment_intent_id');

            $statsSql = "
                SELECT
                    cliente_id,
                    COUNT(DISTINCT id) AS invoice_count,
                    COUNT(DISTINCT CASE
                        WHEN fic_document_id IS NOT NULL AND TRIM(fic_document_id) <> '' THEN fic_document_id
                        ELSE NULL
                    END) AS linked_fic_docs,
                    COUNT(DISTINCT CASE
                        WHEN (
                            LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved'
                            OR LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved'
                            OR LOWER(COALESCE(ei_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                            OR LOWER(COALESCE(mail_error, '')) LIKE '%fic_duplicate_not_recoverable%'
                            OR (ei_error IS NOT NULL AND TRIM(ei_error) <> '')
                            OR (mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')
                        )
                        AND NOT (
                            (
                                LOWER(COALESCE(ei_error, '')) LIKE '%dati cliente insufficienti per e-fattura%'
                                OR LOWER(COALESCE(mail_error, '')) LIKE '%anagrafica incompleta%'
                            )
                            AND " . clienti_current_record_resolves_missing_data_sql('') . "
                        )
                        AND NOT " . clienti_sdi_resolved_sql('') . "
                        AND NOT " . clienti_sdi_in_progress_sql('') . "
                        AND NOT " . clienti_sdi_transient_db_error_sql('') . "
                        AND " . clienti_sdi_reason_sql('', $sdiAlertReason) . " THEN id
                        ELSE NULL
                    END) AS issue_count";

            if ($hasStripePaymentIntent) {
                $statsSql .= ", COUNT(DISTINCT CASE
                        WHEN stripe_payment_intent_id IS NOT NULL AND TRIM(stripe_payment_intent_id) <> '' THEN stripe_payment_intent_id
                        ELSE NULL
                    END) AS stripe_invoice_count";
            } else {
                $statsSql .= ", 0 AS stripe_invoice_count";
            }

            $statsSql .= "
                FROM fatture
                WHERE document_type = 'invoice'
                  AND cliente_id IN ($placeholders)
                GROUP BY cliente_id
            ";

            $statsStmt = $pdo->prepare($statsSql);
            foreach ($clienteIds as $i => $cid) {
                $statsStmt->bindValue($i + 1, $cid, PDO::PARAM_INT);
            }
            $statsStmt->execute();
            $statsRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($statsRows as $statsRow) {
                $clienteInvoiceStatsByCliente[(int)($statsRow['cliente_id'] ?? 0)] = [
                    'invoice_count' => (int)($statsRow['invoice_count'] ?? 0),
                    'linked_fic_docs' => (int)($statsRow['linked_fic_docs'] ?? 0),
                    'issue_count' => (int)($statsRow['issue_count'] ?? 0),
                    'stripe_invoice_count' => (int)($statsRow['stripe_invoice_count'] ?? 0),
                ];
            }

            $issuesSql = "
                                SELECT id, cliente_id, numero, data_emissione, fic_status, ei_status, ei_error, mail_error,
                                             (
                                                     SELECT fl.messaggio
                                                     FROM fatture_log fl
                                                     WHERE fl.fattura_id = fatture.id
                                                         AND fl.azione IN ('stripe_auto_send', 'stripe_auto_send_sync', 'send_einvoice', 'cron_auto_einvoice', 'cron_retry_send')
                                                     ORDER BY fl.creato_il DESC, fl.id DESC
                                                     LIMIT 1
                                             ) AS auto_sdi_last_event_msg
                FROM fatture
                WHERE document_type = 'invoice'
                  AND cliente_id IN ($placeholders)
                  AND (
                    (ei_error IS NOT NULL AND TRIM(ei_error) <> '')
                                        OR (
                                                LOWER(COALESCE(ei_status, '')) = 'duplicate_unresolved'
                                                AND (fic_document_id IS NULL OR TRIM(fic_document_id) = '')
                                        )
                                        OR (
                                                LOWER(COALESCE(fic_status, '')) = 'duplicate_unresolved'
                                                AND (fic_document_id IS NULL OR TRIM(fic_document_id) = '')
                                        )
                    OR (mail_error LIKE 'Pagamento Stripe registrato: invio automatico non eseguito%')
                  )
                                    AND NOT (
                                        (
                                                LOWER(COALESCE(ei_error, '')) LIKE '%dati cliente insufficienti per e-fattura%'
                                                OR LOWER(COALESCE(mail_error, '')) LIKE '%anagrafica incompleta%'
                                        )
                                        AND " . clienti_current_record_resolves_missing_data_sql('') . "
                                    )
                                    AND NOT " . clienti_sdi_resolved_sql('') . "
                                    AND NOT " . clienti_sdi_in_progress_sql('') . "
                                    AND NOT " . clienti_sdi_transient_db_error_sql('') . "
                                    AND " . clienti_sdi_reason_sql('', $sdiAlertReason) . "
                ORDER BY data_emissione DESC, id DESC
            ";
            $issuesStmt = $pdo->prepare($issuesSql);
            foreach ($clienteIds as $i => $cid) {
                $issuesStmt->bindValue($i + 1, $cid, PDO::PARAM_INT);
            }
            $issuesStmt->execute();
            $issueRows = $issuesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($issueRows as $issue) {
                $cid = (int)($issue['cliente_id'] ?? 0);
                if (clienti_is_transient_sdi_db_error_message((string)($issue['ei_error'] ?? ''))
                    || clienti_is_transient_sdi_db_error_message((string)($issue['mail_error'] ?? ''))) {
                    continue;
                }
                if ($cid <= 0 || isset($sdiIssuesByCliente[$cid])) {
                    continue;
                }
                $sdiIssuesByCliente[$cid] = [
                    'fattura_id' => (int)($issue['id'] ?? 0),
                    'numero' => trim((string)($issue['numero'] ?? '')),
                    'reason' => clienti_extract_sdi_block_reason($issue),
                    'is_duplicate' => strtolower(trim((string)($issue['fic_status'] ?? ''))) === 'duplicate_unresolved'
                        || strtolower(trim((string)($issue['ei_status'] ?? ''))) === 'duplicate_unresolved',
                ];
            }
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $totalRows = 0;
    $ficEligible = 0;
    $ficDashboardEligible = 0;
    $ficDashboardNotEligible = 0;
    $ficLinked = 0;
    $totalPages = 1;
    $page = 1;
    $clienti = [];
    $sdiIssuesByCliente = [];
}

$stripeAvailable = false;
$stripeError = null;
$stripeByName = [];
$stripeByEmail = [];
$stripeCachePath = __DIR__ . '/../../tmp/stripe_clienti_cache.json';
$stripeCacheTtl = 600;
$stripeForceRefresh = isset($_GET['refresh_stripe']);
$stripeCacheLoaded = false;
$stripeLoadRequested = isset($_GET['load_stripe']) || $stripeForceRefresh;

// Calcola KPI
$kpiTotalAmountCharged = 0;
$kpiTotalCharges = 0;
$kpiClientiConStripe = 0;

function normalize_cliente_nome(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/[^a-z0-9\s\-\._]/', '', $name);
    return trim($name);
}

function euro_from_cents(int $amountCents): string
{
    return number_format($amountCents / 100, 2, ',', '.');
}

function stripe_method_label(string $type): string
{
    $map = [
        'card' => 'Carta',
        'sepa_debit' => 'Addebito SEPA',
        'sepa_credit_transfer' => 'Bonifico SEPA',
        'bancontact' => 'Bancontact',
        'ideal' => 'iDEAL',
    ];
    if (isset($map[$type])) {
        return $map[$type];
    }
    $label = str_replace('_', ' ', $type);
    return $label !== '' ? ucfirst($label) : '-';
}

function stripe_country_label(?string $country): string
{
    $country = strtoupper(trim((string)$country));
    if ($country === '') {
        return '-';
    }
    $map = [
        'IT' => 'Italia',
    ];
    return $map[$country] ?? $country;
}

function cliente_has_valid_sdi(?string $value): bool
{
    $sdi = strtoupper(trim((string)$value));
    if ($sdi === '') {
        return false;
    }
    return preg_match('/^[A-Z0-9]{7}$/', $sdi) === 1;
}

function cliente_has_valid_pec(?string $value): bool
{
    $pec = trim((string)$value);
    if ($pec === '') {
        return false;
    }
    return filter_var($pec, FILTER_VALIDATE_EMAIL) !== false;
}

function cliente_normalize_vat_number($value): string
{
    $vat = strtoupper(trim((string)$value));
    if ($vat === '') {
        return '';
    }

    $vat = preg_replace('/[\s\.\-\/]/', '', $vat);
    if (!is_string($vat) || $vat === '') {
        return '';
    }

    if (preg_match('/^IT(\d{11})$/', $vat, $matches)) {
        return (string)$matches[1];
    }

    if (preg_match('/^\d{10}$/', $vat)) {
        $paddedVat = '0' . $vat;
        if (cliente_is_valid_italian_vat_number($paddedVat)) {
            return $paddedVat;
        }
    }

    if (preg_match('/^\d{11}$/', $vat)) {
        return $vat;
    }

    if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,28}$/', $vat)) {
        return $vat;
    }

    return '';
}

function cliente_is_valid_italian_vat_number(string $vat): bool
{
    if (!preg_match('/^\d{11}$/', $vat)) {
        return false;
    }

    $sum = 0;
    for ($i = 0; $i <= 9; $i++) {
        $digit = (int)$vat[$i];
        if (($i % 2) === 0) {
            $sum += $digit;
            continue;
        }

        $dbl = $digit * 2;
        $sum += ($dbl > 9) ? ($dbl - 9) : $dbl;
    }

    $check = (10 - ($sum % 10)) % 10;
    return $check === (int)$vat[10];
}

function cliente_is_valid_city_name(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    return preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u", $value) === 1;
}

function cliente_parse_address_parts($value): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return [
            'address_street' => '',
            'address_postal_code' => '',
            'address_city' => '',
            'address_province' => '',
        ];
    }

    $normalized = preg_replace('/\s+/', ' ', str_replace([';', '|'], ',', $raw));
    $tokens = array_values(array_filter(array_map(static function ($token) {
        return trim((string)$token);
    }, explode(',', (string)$normalized)), static function ($token) {
        return $token !== '';
    }));

    $street = '';
    $postalCode = '';
    $city = '';
    $province = '';

    if ($tokens) {
        $street = rtrim((string)$tokens[0], " ,.-");
        for ($i = 1; $i < count($tokens); $i++) {
            $token = trim((string)$tokens[$i]);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^\d+[A-Za-z]?$/', $token)) {
                if ($street !== '') {
                    $street .= ' ' . $token;
                }
                continue;
            }

            if (preg_match('/^(\d{5})\s+(.+?)\s+\(?([A-Za-z]{2})\)?$/', $token, $matches)) {
                if ($postalCode === '') {
                    $postalCode = (string)$matches[1];
                }
                if ($city === '' || !cliente_is_valid_city_name($city)) {
                    $city = trim((string)$matches[2]);
                }
                if ($province === '') {
                    $province = strtoupper((string)$matches[3]);
                }
                continue;
            }

            if (preg_match('/^(\d{5})\s+(.+)$/', $token, $matches)) {
                if ($postalCode === '') {
                    $postalCode = (string)$matches[1];
                }
                if ($city === '' || !cliente_is_valid_city_name($city)) {
                    $city = trim((string)$matches[2]);
                }
                continue;
            }

            if ($postalCode === '' && preg_match('/^\d{5}$/', $token)) {
                $postalCode = $token;
                continue;
            }

            if ($province === '' && preg_match('/^[A-Za-z]{2}$/', $token)) {
                $province = strtoupper($token);
                continue;
            }

            if ($city === '' && preg_match('/^(.+?)\s+\(?([A-Za-z]{2})\)?$/', $token, $matches)) {
                $city = trim((string)$matches[1]);
                if ($province === '') {
                    $province = strtoupper((string)$matches[2]);
                }
                continue;
            }

            if ($city === '' || !cliente_is_valid_city_name($city)) {
                $city = $token;
            }
        }
    } else {
        $street = rtrim($raw, " ,.-");
    }

    if ($postalCode === '' && preg_match('/\b(\d{5})\b/', $raw, $matches)) {
        $postalCode = (string)$matches[1];
    }
    if ($province === '' && preg_match('/(?:\(|,\s*|\s+)([A-Za-z]{2})\)?\s*$/', trim($raw), $matches)) {
        $province = strtoupper((string)$matches[1]);
    }
    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\s+(.+?)\s*\(?([A-Za-z]{2})\)?(?:\s*,|$)/i', $raw, $matches)) {
        $city = trim((string)$matches[1]);
        if ($province === '') {
            $province = strtoupper((string)$matches[2]);
        }
    }
    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\b\s*,?\s*([^,]+?)(?:\s*,\s*[A-Za-z]{2})?(?:\s*,|$)/i', $raw, $matches)) {
        $city = trim((string)$matches[1]);
    }

    return [
        'address_street' => trim($street),
        'address_postal_code' => preg_match('/^\d{5}$/', $postalCode) ? $postalCode : '',
        'address_city' => trim($city),
        'address_province' => preg_match('/^[A-Z]{2}$/', $province) ? $province : '',
    ];
}

function cliente_einvoice_missing_fields(array $cliente): array
{
    $missing = [];
    $vatNumber = cliente_normalize_vat_number($cliente['piva'] ?? '');
    $sdi = (string)($cliente['codice_sdi'] ?? '');
    $pec = (string)($cliente['pec'] ?? '');
    $address = cliente_parse_address_parts($cliente['indirizzo'] ?? '');

    if ($vatNumber === '') {
        $missing[] = 'P.IVA';
    } elseif (preg_match('/^\d{11}$/', $vatNumber) && !cliente_is_valid_italian_vat_number($vatNumber)) {
        $missing[] = 'P.IVA valida';
    }
    if (!cliente_has_valid_sdi($sdi) && !cliente_has_valid_pec($pec)) {
        $missing[] = 'Codice SDI valido (7 caratteri) o PEC valida';
    }
    if (trim((string)($address['address_street'] ?? '')) === ''
        || !preg_match('/^\d{5}$/', (string)($address['address_postal_code'] ?? ''))
        || !cliente_is_valid_city_name((string)($address['address_city'] ?? ''))
        || !preg_match('/^[A-Z]{2}$/', (string)($address['address_province'] ?? ''))) {
        $missing[] = 'Indirizzo completo (CAP + Comune + Provincia)';
    }

    return $missing;
}

function cliente_address_looks_complete_for_sdi(?string $value): bool
{
    $address = cliente_parse_address_parts($value);
    return trim((string)($address['address_street'] ?? '')) !== ''
        && preg_match('/^\d{5}$/', (string)($address['address_postal_code'] ?? '')) === 1
        && cliente_is_valid_city_name((string)($address['address_city'] ?? ''))
        && preg_match('/^[A-Z]{2}$/', (string)($address['address_province'] ?? '')) === 1;
}

function clienti_is_missing_data_issue_reason(string $message): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        return false;
    }

    return strpos($normalized, 'dati cliente insufficienti per e-fattura') !== false
        || strpos($normalized, 'anagrafica incompleta') !== false;
}

function clienti_current_record_resolves_missing_data_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';

    return "EXISTS (
        SELECT 1
        FROM clienti c_fix
        WHERE c_fix.id = {$prefix}cliente_id
          AND TRIM(COALESCE(c_fix.piva, '')) <> ''
          AND (
                UPPER(TRIM(COALESCE(c_fix.codice_sdi, ''))) REGEXP '^[A-Z0-9]{7}$'
                OR TRIM(COALESCE(c_fix.pec, '')) REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$'
          )
          AND TRIM(COALESCE(c_fix.indirizzo, '')) <> ''
          AND TRIM(COALESCE(c_fix.indirizzo, '')) REGEXP '[0-9]{5}'
          AND TRIM(COALESCE(c_fix.indirizzo, '')) REGEXP '(^|[^A-Za-z])[A-Za-z]{2}([^A-Za-z]|$)'
    )";
}

function clienti_sdi_resolved_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';

    return "(LOWER(COALESCE({$prefix}ei_status, '')) IN ('sent', 'accepted')"
        . " OR ({$prefix}ei_sent_at IS NOT NULL AND LOWER(COALESCE({$prefix}ei_status, '')) NOT IN ('error', 'duplicate_unresolved')))";
}

function clienti_extract_sdi_block_reason(array $fattura): string
{
    $ficStatus = strtolower(trim((string)($fattura['fic_status'] ?? '')));
    $eiStatus = strtolower(trim((string)($fattura['ei_status'] ?? '')));
    $eiError = trim((string)($fattura['ei_error'] ?? ''));
    $mailError = trim((string)($fattura['mail_error'] ?? ''));
    $lastEventMsg = trim((string)($fattura['auto_sdi_last_event_msg'] ?? ''));

    if ($eiError !== '') {
        return tdg_sdi_normalize_reason_text($eiError);
    }
    if ($ficStatus === 'duplicate_unresolved' || $eiStatus === 'duplicate_unresolved') {
        return tdg_sdi_normalize_reason_text('duplicate_unresolved');
    }
    if (strpos($mailError, 'Pagamento Stripe registrato: invio automatico non eseguito') === 0) {
        return tdg_sdi_normalize_reason_text($mailError);
    }
    if ($lastEventMsg !== '') {
        return tdg_sdi_normalize_reason_text($lastEventMsg);
    }
    if ($eiStatus === 'error') {
        return 'Invio SDI fallito: verifica dati anagrafici cliente e stato documento su FIC.';
    }

    return 'Invio SDI non completato.';
}

function clienti_is_sdi_in_progress_message(string $message): bool
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

function clienti_is_transient_sdi_db_error_message(string $message): bool
{
    $reason = strtolower(trim($message));
    if ($reason === '') {
        return false;
    }

    return (strpos($reason, 'server has gone away') !== false)
        || (strpos($reason, 'general error: 2006') !== false)
        || (strpos($reason, 'lost connection') !== false)
        || (strpos($reason, 'gone away') !== false);
}

function clienti_sdi_in_progress_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "(LOWER(COALESCE({$prefix}ei_status, '')) = 'processing' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%tentativo di invio per questa fattura%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%gia in corso un tentativo di invio%')";
}

function clienti_sdi_transient_db_error_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "(LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%server has gone away%'"
        . " OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%general error: 2006%'"
        . " OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%lost connection%'"
        . " OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%gone away%'"
        . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%server has gone away%'"
        . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%general error: 2006%'"
        . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%lost connection%'"
        . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%gone away%')";
}

function clienti_extract_sdi_issue_bucket(array $fattura): string
{
    $reason = clienti_extract_sdi_block_reason($fattura);

    if (clienti_is_sdi_in_progress_message((string)($fattura['ei_error'] ?? ''))) {
        return 'SDI gia in lavorazione';
    }

    return tdg_sdi_issue_bucket_from_reason($reason, $fattura);
}

function clienti_sdi_reason_options(): array
{
    return [
        '' => 'Tutti i motivi',
        'duplicate' => 'Duplicato FIC',
        'fic_quota' => 'Limite licenza FIC raggiunto',
        'customer_data' => 'Dati cliente incompleti',
        'tax_data' => 'Dati fiscali mancanti o errati',
        'xml_validation' => 'Validazione XML / dati FIC',
        'fic_config' => 'Configurazione FIC mancante',
        'automation_off' => 'Automazione SDI disattivata',
        'generic' => 'Errore SDI da verificare',
    ];
}
function clienti_sdi_reason_sql(string $alias, string $reason): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';

    switch ($reason) {
        case 'duplicate':
            return "(LOWER(COALESCE({$prefix}fic_status, '')) = 'duplicate_unresolved' OR LOWER(COALESCE({$prefix}ei_status, '')) = 'duplicate_unresolved')";
        case 'fic_quota':
            return "((LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%http 409%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%http 409%')"
                . " AND (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%limite di documenti inclusi%'"
                . " OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%superato il limite di documenti%'"
                . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%limite di documenti inclusi%'"
                . " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%superato il limite di documenti%'))";
        case 'customer_data':
            return "(LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%dati cliente insufficienti%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (anagrafica incompleta:%')";
        case 'tax_data':
            return "(LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%p.iva%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%codice sdi%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%pec%')";
        case 'xml_validation':
            return "(LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%xml%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%validation%')";
        case 'fic_config':
            return "LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (company_id fic mancante%'";
        case 'automation_off':
            return "(LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivata%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivato%')";
        case 'generic':
            return "NOT (" .
                "(LOWER(COALESCE({$prefix}fic_status, '')) = 'duplicate_unresolved' OR LOWER(COALESCE({$prefix}ei_status, '')) = 'duplicate_unresolved')" .
                " OR ((LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%http 409%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%http 409%') AND (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%limite di documenti inclusi%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%superato il limite di documenti%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%limite di documenti inclusi%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%superato il limite di documenti%'))" .
                " OR (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%dati cliente insufficienti%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (anagrafica incompleta:%')" .
                " OR (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%p.iva%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%codice sdi%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%pec%')" .
                " OR (LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%xml%' OR LOWER(COALESCE({$prefix}ei_error, '')) LIKE '%validation%')" .
                " OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE 'pagamento stripe registrato: invio automatico non eseguito (company_id fic mancante%'" .
                " OR (LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivata%' OR LOWER(COALESCE({$prefix}mail_error, '')) LIKE '%automazione disattivato%')" .
            ")";
        default:
            return '1=1';
    }
}

try {
    // Load Stripe data from DB (no API calls needed)
    $stmtStripe = $pdo->prepare("
        SELECT cliente_id, stripe_customer_id, stripe_email, stripe_name, stripe_balance, stripe_country, 
               last_charge_amount, last_charge_date, payment_method, total_charges, total_amount_charged, synced_at
        FROM stripe_sync
        WHERE stripe_customer_id IS NOT NULL
    ");
    $stmtStripe->execute();
    $stripeRows = $stmtStripe->fetchAll(PDO::FETCH_ASSOC);
    $stripeStatsByCliente = [];

    foreach ($stripeRows as $row) {
        $clienteId = (int)($row['cliente_id'] ?? 0);
        $candidate = [
            'id' => $row['stripe_customer_id'],
            'name' => $row['stripe_name'] ?? '',
            'email' => $row['stripe_email'] ?? '',
            'balance' => (int)($row['stripe_balance'] ?? 0),
            'country' => $row['stripe_country'],
            'amount' => (int)($row['total_amount_charged'] ?? 0),
            'count' => (int)($row['total_charges'] ?? 0),
            'last_activity' => $row['last_charge_date'] ? strtotime($row['last_charge_date']) : 0,
            'method' => $row['payment_method'] ?? '',
        ];

        if ($clienteId > 0 && (!isset($stripeStatsByCliente[$clienteId]) || $candidate['last_activity'] > $stripeStatsByCliente[$clienteId]['last_activity'])) {
            $stripeStatsByCliente[$clienteId] = [
                'total_charges' => (int)($row['total_charges'] ?? 0),
                'total_amount_charged' => (int)($row['total_amount_charged'] ?? 0),
                'last_charge_date' => $row['last_charge_date'] ?? null,
                'last_activity' => $candidate['last_activity'],
            ];
        }

        $nameKey = normalize_cliente_nome($candidate['name']);
        if ($nameKey !== '') {
            if (!isset($stripeByName[$nameKey]) || $candidate['last_activity'] > $stripeByName[$nameKey]['last_activity']) {
                $stripeByName[$nameKey] = $candidate;
            }
        }

        $emailKey = strtolower(trim($candidate['email']));
        if ($emailKey !== '') {
            if (!isset($stripeByEmail[$emailKey]) || $candidate['last_activity'] > $stripeByEmail[$emailKey]['last_activity']) {
                $stripeByEmail[$emailKey] = $candidate;
            }
        }
    }

    $stripeAvailable = count($stripeByName) > 0 || count($stripeByEmail) > 0;

    // Accumulate KPI from stripe_sync
    foreach ($stripeRows as $row) {
        $amt = (int)($row['total_amount_charged'] ?? 0);
        $cnt = (int)($row['total_charges'] ?? 0);
        $kpiTotalAmountCharged += $amt;
        $kpiTotalCharges += $cnt;
        if ($cnt > 0) {
            $kpiClientiConStripe++;
        }
    }
} catch (Throwable $e) {
    $stripeError = $e->getMessage();
}

$sdiAlertClientCount = 0;
$sdiAlertBuckets = [];
$sdiAlertRows = [];
$sdiPendingClientCount = 0;
$sdiPendingRows = [];
// I pannelli SDI aggregati sono stati rimossi dalla pagina Clienti.
// Teniamo solo il badge per-riga calcolato in $sdiIssuesByCliente per evitare query globali costose.

// Clienti con pagamenti Stripe non idonei per e-fattura.
$clientiNonIdonei = [];
try {
    $useCurrentClientScopeForNonIdonei = $sdiAlertOnly || $search !== '';

    if ($useCurrentClientScopeForNonIdonei && !empty($clienti)) {
        foreach ($clienti as $clienteRow) {
            $clienteId = (int)($clienteRow['id'] ?? 0);
            $stripeStat = $stripeStatsByCliente[$clienteId] ?? null;
            if ($clienteId <= 0 || !$stripeStat || (int)($stripeStat['total_charges'] ?? 0) <= 0) {
                continue;
            }

            $niRow = $clienteRow;
            $niRow['total_charges'] = (int)($stripeStat['total_charges'] ?? 0);
            $niRow['total_amount_charged'] = (int)($stripeStat['total_amount_charged'] ?? 0);
            $niRow['last_charge_date'] = $stripeStat['last_charge_date'] ?? null;

            if (!empty(cliente_einvoice_missing_fields($niRow))) {
                $clientiNonIdonei[] = $niRow;
            }
        }
    } else {
        $niStmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.nome, c.email, c.telefono, c.azienda, c.indirizzo, c.piva, c.codice_sdi, c.pec, c.note,
                   ss.total_charges, ss.total_amount_charged, ss.last_charge_date
            FROM clienti c
            JOIN stripe_sync ss ON ss.cliente_id = c.id AND ss.total_charges > 0
            ORDER BY ss.last_charge_date DESC
        ");
        $niStmt->execute();
        $niRows = $niStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($niRows as $niRow) {
            if (!empty(cliente_einvoice_missing_fields($niRow))) {
                $clientiNonIdonei[] = $niRow;
            }
        }
    }
} catch (Throwable $e) {
    // tabella potrebbe non esistere
}

function q(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) {
            unset($query[$k]);
        }
    }
    return '?' . http_build_query($query);
}

require __DIR__ . '/../../layouts/header.php';
?>
<div class="wrap">
    <?php if (!empty($dbError)): ?>
        <div class="alert error">&#9888;&#65039; Errore DB: <?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['fic_bulk_ok'])): ?>
        <div class="alert success">&#9989; Fatture create: <?php echo htmlspecialchars((string)($_GET['fic_bulk_ok'] ?? '0')); ?>. Saltati: <?php echo htmlspecialchars((string)($_GET['fic_bulk_skipped'] ?? '0')); ?>.</div>
    <?php elseif (isset($_GET['fic_bulk_error'])): ?>
        <div class="alert error">&#9888;&#65039; Errore preparazione fatture. Riprovare.</div>
    <?php elseif (isset($_GET['fic_client_sync_ok'])): ?>
        <?php $ficClientSyncFailed = (int)($_GET['fic_client_sync_failed'] ?? 0); ?>
        <?php $ficClientSyncErrorDetailInline = trim((string)($_GET['fic_client_sync_error_detail'] ?? '')); ?>
        <div class="alert success">
            &#9989; Clienti sincronizzati FIC: <?php echo htmlspecialchars((string)($_GET['fic_client_sync_ok'] ?? '0')); ?>. Errori: <?php echo htmlspecialchars((string)$ficClientSyncFailed); ?>.
            <?php if ($ficClientSyncFailed > 0 && $ficClientSyncErrorDetailInline !== ''): ?>
                <br><small>Dettaglio primo errore: <?php echo htmlspecialchars($ficClientSyncErrorDetailInline); ?></small>
            <?php endif; ?>
        </div>
    <?php elseif (isset($_GET['fic_client_sync_error'])): ?>
        <?php $ficClientSyncError = (string)($_GET['fic_client_sync_error'] ?? ''); ?>
        <?php $ficClientSyncErrorDetail = trim((string)($_GET['fic_client_sync_error_detail'] ?? '')); ?>
        <?php if ($ficClientSyncError === 'missing_company_id'): ?>
            <div class="alert error">&#9888;&#65039; FIC non configurato: company_id mancante. Completa la connessione in Impostazioni.</div>
        <?php elseif ($ficClientSyncError === 'missing_token'): ?>
            <div class="alert error">&#9888;&#65039; FIC non configurato: access_token mancante o scaduto. Ricollega Fatture in Cloud.</div>
        <?php else: ?>
            <div class="alert error">&#9888;&#65039; Errore durante la sincronizzazione clienti su FIC. <?php echo $ficClientSyncErrorDetail !== '' ? htmlspecialchars($ficClientSyncErrorDetail) : 'Riprova tra poco.'; ?></div>
        <?php endif; ?>
    <?php elseif (isset($_GET['fic_repair_ok'])): ?>
        <?php $ficRepairFailed = (int)($_GET['fic_repair_failed'] ?? 0); ?>
        <?php $ficRepairErrorDetailInline = trim((string)($_GET['fic_repair_error_detail'] ?? '')); ?>
        <div class="alert success">
            ✅ Anagrafiche FIC riparate: <?php echo htmlspecialchars((string)($_GET['fic_repair_ok'] ?? '0')); ?>. Errori: <?php echo htmlspecialchars((string)$ficRepairFailed); ?>.
            <?php if ($ficRepairFailed > 0 && $ficRepairErrorDetailInline !== ''): ?>
                <br><small>Dettaglio primo errore: <?php echo htmlspecialchars($ficRepairErrorDetailInline); ?></small>
            <?php endif; ?>
        </div>
    <?php elseif (isset($_GET['fic_repair_error'])): ?>
        <?php $ficRepairError = (string)($_GET['fic_repair_error'] ?? ''); ?>
        <?php $ficRepairErrorDetail = trim((string)($_GET['fic_repair_error_detail'] ?? '')); ?>
        <?php if ($ficRepairError === 'missing_company_id'): ?>
            <div class="alert error">⚠️ FIC non configurato: company_id mancante. Completa la connessione in Impostazioni.</div>
        <?php elseif ($ficRepairError === 'missing_token'): ?>
            <div class="alert error">⚠️ FIC non configurato: access_token mancante o scaduto. Ricollega Fatture in Cloud.</div>
        <?php else: ?>
            <div class="alert error">⚠️ Errore durante la riparazione anagrafiche FIC. <?php echo $ficRepairErrorDetail !== '' ? htmlspecialchars($ficRepairErrorDetail) : 'Riprova tra poco.'; ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php /* Gli avvisi SDI dettagliati restano nella sezione Fatture/topbar: qui in Clienti evitiamo di duplicare pannelli molto rumorosi. */ ?>

    <?php if (!empty($clientiNonIdonei)): ?>
        <div class="alert error" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div>
                <strong>&#9888;&#65039; <?php echo count($clientiNonIdonei); ?> client<?php echo count($clientiNonIdonei) !== 1 ? 'i' : 'e'; ?> con pagamenti Stripe non idone<?php echo count($clientiNonIdonei) !== 1 ? 'i' : 'a'; ?> a e-fattura</strong>
                <span class="muted" style="display:block;font-size:0.85rem;margin-top:2px;">Mancano uno o pi&ugrave; requisiti: P.IVA, anagrafica (nome/azienda), Codice SDI o PEC valida.</span>
            </div>
            <button class="btn" type="button" data-modal-open="modal-non-idonei" style="white-space:nowrap;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Dettaglio non idonei (<?php echo count($clientiNonIdonei); ?>)
            </button>
        </div>
    <?php endif; ?>

    <div class="card full-width">
        <div class="top-bar">
            <h2>
                <svg width="22" height="22" fill="none" stroke="#0ea5e9" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Clienti
                <?php if ($sdiAlertOnly || $sdiAlertReason !== ''): ?>
                    <span class="badge ko" style="margin-left:8px;vertical-align:middle;">SDI filtrati: <?php echo number_format($totalRows, 0, ',', '.'); ?></span>
                <?php endif; ?>
            </h2>
            <form method="get" class="top-bar-form">
                <input class="input-search" type="text" name="search" placeholder="Cerca per nome, email, azienda o telefono..." value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                <label style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <input type="checkbox" name="sdi_alert" value="1" <?php echo $sdiAlertOnly ? 'checked' : ''; ?>>
                    <span>Solo blocchi SDI</span>
                </label>
                <label class="per-page">
                    <span>Motivo SDI</span>
                    <select name="sdi_reason" onchange="this.form.submit()">
                        <?php foreach (clienti_sdi_reason_options() as $reasonValue => $reasonLabel): ?>
                            <option value="<?php echo htmlspecialchars($reasonValue, ENT_QUOTES); ?>" <?php echo $sdiAlertReason === $reasonValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($reasonLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="per-page">
                    <span>Per pagina</span>
                    <select name="perPage" onchange="this.form.submit()">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn" type="submit">Filtra</button>
                <?php if ($search !== '' || $sdiAlertOnly || $sdiAlertReason !== ''): ?>
                    <a class="btn secondary" href="clienti.php">Azzera</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-card accent-blue">
            <span class="kpi-label">Totale Clienti</span>
            <span class="kpi-value"><?php echo number_format($totalRows, 0, ',', '.'); ?></span>
            <span class="kpi-sub">nel database</span>
        </div>
        <div class="kpi-card accent-amber kpi-card-fiscal">
            <span class="kpi-label">Requisiti e-fattura</span>
            <div class="kpi-split">
                <div class="kpi-split-item kpi-split-ok">
                    <span>Idonei</span>
                    <strong><?php echo number_format($ficDashboardEligible, 0, ',', '.'); ?></strong>
                </div>
                <div class="kpi-split-item kpi-split-ko">
                    <span>Non idonei</span>
                    <strong><?php echo number_format($ficDashboardNotEligible, 0, ',', '.'); ?></strong>
                </div>
            </div>
            <span class="kpi-sub">P.IVA, anagrafica e SDI/PEC validi</span>
        </div>
        <div class="kpi-card accent-green">
            <span class="kpi-label">Clienti con Stripe</span>
            <span class="kpi-value"><?php echo number_format($kpiClientiConStripe, 0, ',', '.'); ?></span>
            <span class="kpi-sub"><?php echo $totalRows > 0 ? round($kpiClientiConStripe / $totalRows * 100) : 0; ?>% del totale</span>
        </div>
        <div class="kpi-card accent-purple">
            <span class="kpi-label">Volume Incassato</span>
            <span class="kpi-value">&euro;<?php echo number_format($kpiTotalAmountCharged / 100, 2, ',', '.'); ?></span>
            <span class="kpi-sub"><?php echo number_format($kpiTotalCharges, 0, ',', '.'); ?> pagamenti totali</span>
        </div>
    </div>

    <div class="card full-width">
        <div class="actions-bar">
            <button class="btn" type="button" data-modal-open="modal-create">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                Nuovo cliente
            </button>
            <button class="btn secondary" type="button" data-modal-open="modal-fic-bulk">Prepara fatture FIC</button>
            <a class="btn secondary" href="verifica.php">Verifica P.IVA/SDI</a>
            <a class="btn secondary" href="esporta_csv.php">Esporta CSV</a>
            <a class="btn secondary" href="sync_stripe.php?api=1" id="sync-stripe-btn">Sync Stripe</a>
            <a class="btn secondary" href="fic_sync_clients.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" onclick="return confirm('Sincronizzare <?php echo $ficEligible; ?> <?php echo $ficEligible !== 1 ? 'clienti' : 'cliente'; ?> su Fatture in Cloud?\n\nVerranno sincronizzati solo i clienti con P.IVA, anagrafica e SDI/PEC compilati.');">Sync FIC (<?php echo $ficEligible; ?>)</a>
            <a class="btn secondary" href="fic_repair_entities.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" onclick="return confirm('Riparare <?php echo $ficLinked; ?> <?php echo $ficLinked !== 1 ? 'anagrafiche clienti' : 'anagrafica cliente'; ?> gia collegate su Fatture in Cloud?\n\nVerranno aggiornati Comune, CAP, Provincia, indirizzo, P.IVA, SDI e PEC dai dati CRM.');">Ripara anagrafiche FIC (<?php echo $ficLinked; ?>)</a>
            <span class="badge <?php echo $stripeAvailable ? 'ok' : 'ko'; ?>" id="stripe-status">
                <span class="status-dot <?php echo $stripeAvailable ? 'active' : 'inactive'; ?>"></span>
                Stripe <?php echo $stripeAvailable ? 'OK' : 'non sync'; ?>
            </span>
        </div>

        <div class="list-meta">
            <div>
                <strong><?php echo number_format($totalRows, 0, ',', '.'); ?></strong> risultati
                <?php if ($totalRows > 0): ?>
                    &middot; Pag. <?php echo $page; ?>/<?php echo $totalPages; ?>
                <?php endif; ?>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="pager">
                    <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>
                    <a class="btn btn-sm <?php echo $prevDisabled ? 'btn-disabled' : 'secondary'; ?>" href="<?php echo $prevDisabled ? '#' : q(['page' => $page - 1]); ?>" <?php echo $prevDisabled ? 'aria-disabled="true"' : ''; ?>>&larr; Prec.</a>
                    <a class="btn btn-sm <?php echo $nextDisabled ? 'btn-disabled' : 'secondary'; ?>" href="<?php echo $nextDisabled ? '#' : q(['page' => $page + 1]); ?>" <?php echo $nextDisabled ? 'aria-disabled="true"' : ''; ?>>Succ. &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="table-wrapper">
        <table class="clienti-table">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all-clienti" aria-label="Seleziona tutti"></th>
                    <th>Cliente</th>
                    <th>Email</th>
                    <th>Metodo</th>
                    <th>Paese</th>
                    <th title="Data e ora dell'ultima attivita Stripe"><span class="clienti-th-stack"><span>Ultima</span><span>attivit&agrave;</span></span></th>
                    <th style="text-align:right;">Importo</th>
                    <th style="text-align:center;">Pagamenti</th>
                    <th style="text-align:right;">Saldo</th>
                    <th style="text-align:center;" title="Stato requisiti per e-fattura"><span class="clienti-th-stack"><span>Requisiti</span><span>e-fattura</span></span></th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$clienti): ?>
                    <tr class="clienti-empty-row"><td class="clienti-empty-cell" colspan="11" style="text-align:center;padding:40px 10px;color:#94a3b8;">
                        <svg width="40" height="40" fill="none" stroke="#cbd5e1" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 10px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Nessun cliente trovato.
                    </td></tr>
                <?php else: foreach ($clienti as $c): ?>
                    <?php
                    $stripeMatch = null;
                    if ($stripeAvailable) {
                        $nameKey = normalize_cliente_nome((string)($c['nome'] ?? ''));
                        $stripeMatch = $nameKey !== '' ? ($stripeByName[$nameKey] ?? null) : null;
                        if (!$stripeMatch) {
                            $emailKey = strtolower(trim((string)($c['email'] ?? '')));
                            $stripeMatch = $emailKey !== '' ? ($stripeByEmail[$emailKey] ?? null) : null;
                        }
                    }
                    $emailToShow = $stripeMatch['email'] ?? ($c['email'] ?? '');
                    $methodToShow = $stripeMatch ? stripe_method_label((string)($stripeMatch['method'] ?? '')) : '-';
                    $countryRaw = $stripeMatch['country'] ?? null;
                    $countryToShow = $stripeMatch ? stripe_country_label($countryRaw) : '-';
                    $lastActivity = $stripeMatch && !empty($stripeMatch['last_activity'])
                        ? date('d/m/Y H:i', (int)$stripeMatch['last_activity'])
                        : '-';
                    $amountToShow = $stripeMatch ? euro_from_cents((int)($stripeMatch['amount'] ?? 0)) : '0,00';
                    $countToShow = $stripeMatch ? (int)($stripeMatch['count'] ?? 0) : 0;
                    $balanceToShow = $stripeMatch ? euro_from_cents((int)($stripeMatch['balance'] ?? 0)) : '0,00';
                    $balanceCents = $stripeMatch ? (int)($stripeMatch['balance'] ?? 0) : 0;
                    $hasStripePayments = $countToShow > 0;
                    $reqMissing = cliente_einvoice_missing_fields($c);
                    $reqAll = empty($reqMissing);
                    $sdiIssue = $sdiIssuesByCliente[(int)($c['id'] ?? 0)] ?? null;
                    $invoiceStats = $clienteInvoiceStatsByCliente[(int)($c['id'] ?? 0)] ?? null;
                    ?>
                    <tr class="<?php echo $reqAll ? '' : 'cliente-ko-row'; ?>">
                        <td class="clienti-row-select"><input type="checkbox" class="fic-select" value="<?php echo $c['id']; ?>" aria-label="Seleziona cliente"></td>
                        <td class="clienti-primary-cell" data-label="Cliente">
                            <span class="cliente-name"><?php echo htmlspecialchars($c['nome']); ?></span>
                            <?php if (!empty($c['azienda'])): ?>
                                <span class="cliente-company"><?php echo htmlspecialchars($c['azienda']); ?></span>
                            <?php endif; ?>
                            <?php if (!$reqAll): ?>
                                <div style="margin-top:4px;">
                                    <span class="cliente-ko-badge<?php echo $hasStripePayments ? '' : ' warning'; ?>"><?php echo $hasStripePayments ? 'Non idoneo e-fattura' : 'Anagrafica incompleta'; ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="email-cell" data-label="Email"><?php echo htmlspecialchars($emailToShow); ?></td>
                        <td data-label="Metodo"><?php echo htmlspecialchars($methodToShow); ?></td>
                        <td data-label="Paese"><?php echo htmlspecialchars($countryToShow); ?></td>
                        <td data-label="Ultima attivita"><?php echo htmlspecialchars($lastActivity); ?></td>
                        <td data-label="Importo" style="text-align:right;" class="amount-cell">&euro;<?php echo htmlspecialchars($amountToShow); ?></td>
                        <td data-label="Pagamenti" style="text-align:center;"><span class="count-badge"><?php echo (int)$countToShow; ?></span></td>
                        <td data-label="Saldo" style="text-align:right;" class="<?php echo $balanceCents > 0 ? 'saldo-positive' : 'saldo-zero'; ?>">&euro;<?php echo htmlspecialchars($balanceToShow); ?></td>
                        <td data-label="Requisiti e-fattura" style="text-align:center;">
                            <?php if ($reqAll): ?>
                                <span title="Pronto per invio e-fattura SDI" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;">
                                    <span class="status-dot active"></span>OK
                                </span>
                            <?php else: ?>
                                <span title="Mancano: <?php echo htmlspecialchars(implode(', ', $reqMissing)); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b;cursor:help;">
                                    <span class="status-dot inactive"></span><?php echo count($reqMissing); ?>/3
                                </span>
                                <div class="cliente-ko-missing"><?php echo htmlspecialchars(implode(' | ', $reqMissing)); ?></div>
                                <?php if (!$hasStripePayments): ?>
                                    <div class="cliente-sdi-context" style="margin-top:4px;font-size:11px;line-height:1.35;color:#92400e;font-weight:600;">
                                        Nessun pagamento Stripe collegato a questo cliente: i valori Importo, Pagamenti e Saldo restano a zero.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($sdiIssue): ?>
                                <?php if ($invoiceStats): ?>
                                    <div class="cliente-sdi-context" style="margin-top:6px;font-size:11px;line-height:1.35;color:#6b7280;">
                                        <?php
                                        $stripeInvoiceCount = (int)($invoiceStats['stripe_invoice_count'] ?? 0);
                                        $invoiceCount = (int)($invoiceStats['invoice_count'] ?? 0);
                                        $linkedFicDocs = (int)($invoiceStats['linked_fic_docs'] ?? 0);
                                        $issueCount = max(1, (int)($invoiceStats['issue_count'] ?? 0));
                                        $expectedFicDocs = max((int)$countToShow, $stripeInvoiceCount, $invoiceCount, $linkedFicDocs);
                                        ?>
                                        <?php echo (int)$countToShow; ?> pagamenti Stripe,
                                        <?php echo $stripeInvoiceCount > 0 ? $stripeInvoiceCount : $invoiceCount; ?> fatture CRM,
                                        <?php echo $linkedFicDocs; ?> document<?php echo $linkedFicDocs === 1 ? 'o' : 'i'; ?> FIC collegat<?php echo $linkedFicDocs === 1 ? 'o' : 'i'; ?>,
                                        <?php echo $issueCount; ?> problem<?php echo $issueCount === 1 ? 'a' : 'i'; ?> residu<?php echo $issueCount === 1 ? 'o' : 'i'; ?>.
                                    </div>
                                    <div class="cliente-sdi-context" style="margin-top:4px;font-size:11px;line-height:1.35;color:#92400e;font-weight:600;">
                                        <?php if ($expectedFicDocs > 0 && $linkedFicDocs > 0 && $linkedFicDocs < $expectedFicDocs): ?>
                                            Cliente quasi allineato: <?php echo $linkedFicDocs; ?>/<?php echo $expectedFicDocs; ?> document<?php echo $expectedFicDocs === 1 ? 'o' : 'i'; ?> FIC presenti, <?php echo max(1, $expectedFicDocs - $linkedFicDocs); ?> da rigenerare.
                                        <?php elseif ($expectedFicDocs > 0 && $linkedFicDocs === 0): ?>
                                            Cliente non ancora allineato: nessun documento FIC collegato su <?php echo $expectedFicDocs; ?> attes<?php echo $expectedFicDocs === 1 ? 'o' : 'i'; ?>.
                                        <?php elseif ($expectedFicDocs > 0 && $linkedFicDocs >= $expectedFicDocs && $issueCount > 0): ?>
                                            Cliente quasi allineato: documenti FIC presenti, resta <?php echo $issueCount; ?> anomal<?php echo $issueCount === 1 ? 'ia' : 'ie'; ?> da ripulire.
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="cliente-sdi-issue" title="<?php echo htmlspecialchars((string)$sdiIssue['reason']); ?>">
                                    <strong>Motivo blocco SDI:</strong>
                                    <?php echo htmlspecialchars(mb_strimwidth((string)$sdiIssue['reason'], 0, 120, '...')); ?>
                                    <?php if (!empty($sdiIssue['numero'])): ?>
                                        <span class="cliente-sdi-issue-num">(Fattura <?php echo htmlspecialchars((string)$sdiIssue['numero']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($sdiIssue['is_duplicate']) && !empty($sdiIssue['fattura_id'])): ?>
                                    <button
                                        class="btn secondary btn-sm clienti-fix-duplicate-btn"
                                        type="button"
                                        data-fattura-id="<?php echo (int)$sdiIssue['fattura_id']; ?>"
                                        data-fattura-numero="<?php echo htmlspecialchars((string)($sdiIssue['numero'] ?? ''), ENT_QUOTES); ?>"
                                        style="margin-top:6px;border-color:#f59e0b;color:#92400e;"
                                    >Risolvi duplicato FIC</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="azioni" data-label="Azioni">
                            <button
                                class="btn secondary"
                                type="button"
                                data-modal-open="modal-scheda"
                                data-id="<?php echo $c['id']; ?>"
                                data-nome="<?php echo htmlspecialchars($c['nome'], ENT_QUOTES); ?>"
                            >Scheda</button>
                            <button
                                class="btn btn-edit"
                                type="button"
                                data-modal-open="modal-edit"
                                data-id="<?php echo $c['id']; ?>"
                                data-nome="<?php echo htmlspecialchars($c['nome'], ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"
                                data-telefono="<?php echo htmlspecialchars($c['telefono'], ENT_QUOTES); ?>"
                                data-azienda="<?php echo htmlspecialchars($c['azienda'], ENT_QUOTES); ?>"
                                data-indirizzo="<?php echo htmlspecialchars($c['indirizzo'], ENT_QUOTES); ?>"
                                data-piva="<?php echo htmlspecialchars($c['piva'], ENT_QUOTES); ?>"
                                data-codice-sdi="<?php echo htmlspecialchars($c['codice_sdi'] ?? '', ENT_QUOTES); ?>"
                                data-pec="<?php echo htmlspecialchars($c['pec'] ?? '', ENT_QUOTES); ?>"
                                data-note="<?php echo htmlspecialchars($c['note'], ENT_QUOTES); ?>"
                            >Modifica</button>
                            <form method="post" action="clienti_store.php" onsubmit="return confirm('Eliminare questo cliente?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <button class="btn btn-delete" type="submit">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="list-footer">
                <form method="get" class="per-page-form">
                    <label class="per-page">
                        <span>Righe per pagina</span>
                        <select name="perPage" onchange="this.form.submit()">
                            <?php foreach ($perPageOptions as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                    <input type="hidden" name="sdi_reason" value="<?php echo htmlspecialchars($sdiAlertReason, ENT_QUOTES); ?>">
                    <?php if ($sdiAlertOnly): ?>
                        <input type="hidden" name="sdi_alert" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="page" value="<?php echo $page; ?>">
                </form>
                <div class="pager">
                    <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>
                    <a class="btn btn-sm <?php echo $prevDisabled ? 'btn-disabled' : 'secondary'; ?>" href="<?php echo $prevDisabled ? '#' : q(['page' => $page - 1]); ?>" <?php echo $prevDisabled ? 'aria-disabled="true"' : ''; ?>>&larr; Prec.</a>
                    <span class="page-count">Pag. <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong></span>
                    <a class="btn btn-sm <?php echo $nextDisabled ? 'btn-disabled' : 'secondary'; ?>" href="<?php echo $nextDisabled ? '#' : q(['page' => $page + 1]); ?>" <?php echo $nextDisabled ? 'aria-disabled="true"' : ''; ?>>Succ. &rarr;</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="modal-scheda" aria-hidden="true" style="display:none;">
    <div class="modal-card" style="max-width:980px;width:95%;">
        <div class="modal-header">
            <h3 style="margin:0;">Scheda cliente</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <div style="margin-top:12px;">
            <iframe
                id="scheda-frame"
                title="Scheda cliente"
                src="about:blank"
                style="width:100%;height:70vh;border:0;background:#fff;border-radius:12px;"
            ></iframe>
        </div>
    </div>
</div>

<div class="modal" id="modal-fic-bulk" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Prepara fatture per Fatture in Cloud</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="fic_bulk_prepare.php" id="fic-bulk-form">
            <input type="hidden" name="client_ids" id="fic-selected-ids" value="">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Ambito</label>
                    <select name="scope" id="fic-scope" required>
                        <option value="selected">Solo selezionati</option>
                        <option value="filtered">Tutti filtrati</option>
                        <option value="all">Tutti</option>
                    </select>
                </div>
                <div>
                    <label>Data emissione</label>
                    <input type="date" name="data_emissione" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div>
                    <label>Data scadenza</label>
                    <input type="date" name="data_scadenza" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                </div>
                <div>
                    <label>Descrizione riga</label>
                    <input type="text" name="descrizione" value="Fattura servizi" required>
                </div>
            </div>
            <p class="muted" style="margin-top:10px;">Importo: ultimo pagamento riuscito su Stripe per ogni cliente. P.IVA e SDI sono obbligatori.</p>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Prepara fatture</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-create" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Nuovo cliente</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="clienti_store.php">
            <input type="hidden" name="action" value="create">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" required>
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono">
                </div>
                <div>
                    <label>Azienda</label>
                    <input type="text" name="azienda">
                </div>
                <div>
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo">
                </div>
                <div>
                    <label>P.IVA</label>
                    <input type="text" name="piva">
                </div>
                <div>
                    <label>Codice SDI</label>
                    <input type="text" name="codice_sdi" maxlength="20">
                </div>
                <div>
                    <label>PEC</label>
                    <input type="email" name="pec">
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="3"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Salva</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-edit" aria-hidden="true" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;">Modifica cliente</h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <form method="post" action="clienti_store.php" id="form-edit-cliente">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="">
            <div class="grid" style="margin-top:12px;">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" required>
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div>
                    <label>Telefono</label>
                    <input type="text" name="telefono">
                </div>
                <div>
                    <label>Azienda</label>
                    <input type="text" name="azienda">
                </div>
                <div>
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo">
                </div>
                <div>
                    <label>P.IVA</label>
                    <input type="text" name="piva">
                </div>
                <div>
                    <label>Codice SDI</label>
                    <input type="text" name="codice_sdi" maxlength="20">
                </div>
                <div>
                    <label>PEC</label>
                    <input type="email" name="pec">
                </div>
                <div style="grid-column:1/-1;">
                    <label>Note</label>
                    <textarea name="note" rows="3"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button class="btn secondary" type="button" data-modal-close>Annulla</button>
                <button class="btn" type="submit">Salva</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Focus search on Ctrl+K / Cmd+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            var searchInput = document.querySelector('.input-search');
            if (searchInput) { searchInput.focus(); searchInput.select(); }
        }
    });

    // Sync Stripe button with progress
    var syncBtn = document.getElementById('sync-stripe-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var originalHTML = syncBtn.innerHTML;
            syncBtn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite"></span> Sincronizzazione...';
            syncBtn.style.pointerEvents = 'none';
            syncBtn.style.opacity = '0.7';
            fetch('sync_stripe.php?api=1')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        var statusBadge = document.getElementById('stripe-status');
                        if (statusBadge) {
                            statusBadge.innerHTML = '<span class="status-dot active"></span> Stripe OK';
                            statusBadge.className = 'badge ok';
                        }
                        var syncLabel = 'Sincronizzati ' + data.synced;
                        if (Number(data.created || 0) > 0) {
                            syncLabel += ' - creati ' + data.created;
                        }
                        syncBtn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> ' + syncLabel;
                        syncBtn.style.background = '#22c55e';
                        syncBtn.style.color = '#fff';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        syncBtn.innerHTML = 'Errore';
                        syncBtn.style.background = '#ef4444';
                        syncBtn.style.color = '#fff';
                        setTimeout(function() {
                            syncBtn.innerHTML = originalHTML;
                            syncBtn.style.pointerEvents = '';
                            syncBtn.style.opacity = '';
                            syncBtn.style.background = '';
                            syncBtn.style.color = '';
                        }, 2000);
                    }
                })
                .catch(function() {
                    syncBtn.innerHTML = 'Errore rete';
                    syncBtn.style.background = '#ef4444';
                    syncBtn.style.color = '#fff';
                    setTimeout(function() {
                        syncBtn.innerHTML = originalHTML;
                        syncBtn.style.pointerEvents = '';
                        syncBtn.style.opacity = '';
                        syncBtn.style.background = '';
                        syncBtn.style.color = '';
                    }, 2000);
                });
        });
    }

    // Scheda modal
    document.querySelectorAll('button[data-modal-open="modal-scheda"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id') || '';
            var nome = btn.getAttribute('data-nome') || '';
            var frame = document.getElementById('scheda-frame');
            var modalTitle = document.querySelector('#modal-scheda h3');
            if (modalTitle) {
                modalTitle.textContent = nome ? 'Scheda cliente - ' + nome : 'Scheda cliente';
            }
            if (frame && id) {
                frame.src = 'cliente_scheda.php?id=' + encodeURIComponent(id) + '&embed=1';
            }
        });
    });

    // Popola il form di modifica quando si clicca il pulsante Edit
    document.querySelectorAll('button[data-modal-open="modal-edit"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('form-edit-cliente');
            if (form) {
                form.querySelector('input[name="id"]').value = btn.getAttribute('data-id') || '';
                form.querySelector('input[name="nome"]').value = btn.getAttribute('data-nome') || '';
                form.querySelector('input[name="email"]').value = btn.getAttribute('data-email') || '';
                form.querySelector('input[name="telefono"]').value = btn.getAttribute('data-telefono') || '';
                form.querySelector('input[name="azienda"]').value = btn.getAttribute('data-azienda') || '';
                form.querySelector('input[name="indirizzo"]').value = btn.getAttribute('data-indirizzo') || '';
                form.querySelector('input[name="piva"]').value = btn.getAttribute('data-piva') || '';
                form.querySelector('input[name="codice_sdi"]').value = btn.getAttribute('data-codice-sdi') || '';
                form.querySelector('input[name="pec"]').value = btn.getAttribute('data-pec') || '';
                form.querySelector('textarea[name="note"]').value = btn.getAttribute('data-note') || '';
            }
        });
    });

    // Select all checkbox
    var selectAll = document.getElementById('select-all-clienti');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            document.querySelectorAll('.fic-select').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    }

    // Bulk FIC form
    var bulkForm = document.getElementById('fic-bulk-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            var scope = document.getElementById('fic-scope');
            var selected = [];
            document.querySelectorAll('.fic-select:checked').forEach(function (cb) {
                selected.push(cb.value);
            });
            document.getElementById('fic-selected-ids').value = selected.join(',');
            if (scope && scope.value === 'selected' && selected.length === 0) {
                e.preventDefault();
                alert('Seleziona almeno un cliente.');
            }
        });
    }

    document.querySelectorAll('.clienti-fix-duplicate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fatturaId = btn.getAttribute('data-fattura-id') || '';
            var numero = btn.getAttribute('data-fattura-numero') || 'selezionata';
            if (!fatturaId) {
                return;
            }
            if (!confirm('Rigenerare la fattura ' + numero + ' per risolvere il duplicato FIC?\n\nVerrà assegnato un nuovo numero univoco e sarà ritentata la creazione su FIC con invio SDI.')) {
                return;
            }

            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Rigenerazione...';

            fetch('../fatture/actions/fic_rigenera_duplicati.php?fattura_id=' + encodeURIComponent(fatturaId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error(data && data.error ? data.error : 'risposta non valida');
                    }

                    if (Number(data.fixed || 0) > 0) {
                        btn.textContent = 'Rigenerata';
                        btn.style.background = '#16a34a';
                        btn.style.color = '#fff';
                        setTimeout(function () { window.location.reload(); }, 1200);
                        return;
                    }

                    throw new Error(data.message || 'Nessuna fattura rigenerata');
                })
                .catch(function (err) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    alert('Errore rigenerazione duplicato FIC: ' + (err && err.message ? err.message : err));
                });
        });
    });
});
</script>
<style>
@keyframes spin{to{transform:rotate(360deg)}}
.clienti-table thead th{
    vertical-align:middle;
}
.clienti-th-stack{
    display:inline-flex;
    flex-direction:column;
    gap:2px;
    line-height:1.15;
}
.clienti-table tr.cliente-ko-row td{
    background:#fff7f7;
}
.clienti-table tr.cliente-ko-row:hover td{
    background:#ffecec;
}
.cliente-ko-badge{
    display:inline-block;
    padding:2px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
}
.cliente-ko-badge.warning{
    background:#fef3c7;
    color:#92400e;
    border-color:#fcd34d;
}
.cliente-ko-missing{
    margin-top:4px;
    font-size:11px;
    color:#9a3412;
    font-weight:600;
    line-height:1.3;
}
.clienti-sdi-alert{
    display:block;
}
.clienti-sdi-alert-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    flex-wrap:wrap;
}
.clienti-sdi-alert-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.clienti-sdi-alert-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:10px;
    margin-top:14px;
}
.clienti-sdi-alert-card{
    padding:12px;
    border-radius:10px;
    border:1px solid #fecaca;
    background:#fff7f7;
    display:flex;
    flex-direction:column;
    gap:4px;
}
.clienti-sdi-alert-card strong{
    font-size:24px;
    line-height:1;
    color:#991b1b;
}
.clienti-sdi-alert-card span{
    font-size:12px;
    font-weight:700;
    color:#7f1d1d;
}
.clienti-sdi-alert-list{
    display:flex;
    flex-direction:column;
    gap:10px;
    margin-top:14px;
}
.clienti-sdi-alert-item{
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #fecaca;
    background:#fff;
}
.clienti-sdi-alert-reason{
    margin-top:6px;
    display:flex;
    gap:8px;
    align-items:flex-start;
    flex-wrap:wrap;
    color:#7f1d1d;
}
.clienti-sdi-alert-links{
    margin-top:8px;
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}
.clienti-sdi-alert-links a{
    font-size:12px;
    font-weight:700;
    color:#9a3412;
    text-decoration:none;
}
.clienti-sdi-alert-links a:hover{
    text-decoration:underline;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-card{
    border-color:#fcd34d;
    background:#fffbeb;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-card strong{
    color:#92400e;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-card span{
    color:#b45309;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-item{
    border-color:#fcd34d;
    background:#fffdf5;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-reason{
    color:#9a3412;
}
.clienti-sdi-alert.warning .clienti-sdi-alert-links a{
    color:#92400e;
}
@media (max-width: 1360px){
    .clienti-table thead th{
        font-size:12px;
    }
    .clienti-table td{
        font-size:13px;
    }
}
</style>

<?php if (!empty($clientiNonIdonei)): ?>
<div class="modal" id="modal-non-idonei" aria-hidden="true" style="display:none;">
    <div class="modal-card" style="max-width:900px;width:95%;">
        <div class="modal-header">
            <h3 style="margin:0;">
                <svg width="18" height="18" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-3px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Clienti non idonei alla fatturazione (<?php echo count($clientiNonIdonei); ?>)
            </h3>
            <button class="icon-btn" type="button" data-modal-close aria-label="Chiudi">&times;</button>
        </div>
        <p class="muted" style="margin:8px 0 12px;">Questi clienti hanno effettuato pagamenti Stripe ma non rispettano i requisiti e-fattura (P.IVA, anagrafica e SDI/PEC). Clicca <strong>Completa</strong> per correggere i dati mancanti.</p>
        <div class="clienti-modal-table-wrap">
            <table class="clienti-non-idonei-table" style="width:100%;font-size:0.9rem;">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Email</th>
                        <th>P.IVA</th>
                        <th>Codice SDI</th>
                        <th>PEC</th>
                        <th>Dati mancanti</th>
                        <th>Pagamenti</th>
                        <th style="text-align:right;">Totale</th>
                        <th>Ultimo pag.</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientiNonIdonei as $ni): ?>
                        <?php
                        $niPiva = trim((string)($ni['piva'] ?? ''));
                        $niSdi = trim((string)($ni['codice_sdi'] ?? ''));
                        $niPec = trim((string)($ni['pec'] ?? ''));
                        $niMissing = cliente_einvoice_missing_fields($ni);
                        $niTotale = (int)($ni['total_amount_charged'] ?? 0);
                        $niUltimo = $ni['last_charge_date'] ? date('d/m/Y', strtotime($ni['last_charge_date'])) : '-';
                        ?>
                        <tr class="cliente-ko-row">
                            <td data-label="Cliente">
                                <strong><?php echo htmlspecialchars($ni['nome'] ?? ''); ?></strong>
                                <?php if (!empty($ni['azienda'])): ?>
                                    <br><span class="muted" style="font-size:0.8rem;"><?php echo htmlspecialchars($ni['azienda']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Email"><?php echo htmlspecialchars($ni['email'] ?? '-'); ?></td>
                            <td data-label="P.IVA">
                                <?php if ($niPiva !== ''): ?>
                                    <span style="color:#166534;"><?php echo htmlspecialchars($niPiva); ?></span>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:600;">Mancante</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Codice SDI">
                                <?php if (cliente_has_valid_sdi($niSdi)): ?>
                                    <span style="color:#166534;"><?php echo htmlspecialchars($niSdi); ?></span>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:600;">Mancante</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="PEC">
                                <?php if (cliente_has_valid_pec($niPec)): ?>
                                    <span style="color:#166534;"><?php echo htmlspecialchars($niPec); ?></span>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:600;">Non valida/mancante</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Dati mancanti">
                                <span class="cliente-ko-missing"><?php echo htmlspecialchars(implode(' | ', $niMissing)); ?></span>
                            </td>
                            <td data-label="Pagamenti" style="text-align:center;"><?php echo (int)($ni['total_charges'] ?? 0); ?></td>
                            <td data-label="Totale" style="text-align:right;">&euro;<?php echo number_format($niTotale / 100, 2, ',', '.'); ?></td>
                            <td data-label="Ultimo pag."><?php echo htmlspecialchars($niUltimo); ?></td>
                            <td data-label="Azioni">
                                <button class="btn btn-edit" type="button" style="font-size:0.8rem;padding:4px 10px;"
                                    data-modal-open="modal-edit"
                                    data-id="<?php echo (int)$ni['id']; ?>"
                                    data-nome="<?php echo htmlspecialchars($ni['nome'] ?? '', ENT_QUOTES); ?>"
                                    data-email="<?php echo htmlspecialchars($ni['email'] ?? '', ENT_QUOTES); ?>"
                                    data-telefono="<?php echo htmlspecialchars($ni['telefono'] ?? '', ENT_QUOTES); ?>"
                                    data-azienda="<?php echo htmlspecialchars($ni['azienda'] ?? '', ENT_QUOTES); ?>"
                                    data-indirizzo="<?php echo htmlspecialchars($ni['indirizzo'] ?? '', ENT_QUOTES); ?>"
                                    data-piva="<?php echo htmlspecialchars($ni['piva'] ?? '', ENT_QUOTES); ?>"
                                    data-codice-sdi="<?php echo htmlspecialchars($ni['codice_sdi'] ?? '', ENT_QUOTES); ?>"
                                    data-pec="<?php echo htmlspecialchars($ni['pec'] ?? '', ENT_QUOTES); ?>"
                                    data-note="<?php echo htmlspecialchars($ni['note'] ?? '', ENT_QUOTES); ?>"
                                >Completa</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
