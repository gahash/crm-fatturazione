<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
$config = require __DIR__ . '/../../config/config.php';

$user = current_user();
if (!has_admin_access()) {
    header('Location: ../clienti/clienti.php');
    exit;
}

// Filtro per mese e anno (default: Tutti gli anni, Tutto)
$meseSelezionato = isset($_GET['mese']) && $_GET['mese'] !== '' ? (int)$_GET['mese'] : 0;
$annoSelezionato = isset($_GET['anno']) && $_GET['anno'] !== '' ? (int)$_GET['anno'] : 0;
$meseSelezionato = max(0, min(12, $meseSelezionato));
$annoSelezionato = max(0, $annoSelezionato);

$isAllMonths = $meseSelezionato === 0;
$isAllYears = $annoSelezionato === 0;
$meseLabel = $isAllMonths ? 'Tutto' : $meseSelezionato;
require_once __DIR__ . '/../connect/connect_service.php';

// Metriche Stripe: valori di default, caricati in modo asincrono via stripe_data.php
$stripeMetrics = [
    'connected' => false,
    'error' => null,
    'gross' => 0,
    'net' => 0,
    'fees' => 0,
    'refunds' => 0,
    'charges_total' => 0,
    'charges_succeeded' => 0,
    'charges_failed' => 0,
    'charges_pending' => 0,
    'charges_amount_succeeded' => 0,
    'customers_new' => 0,
    'balance_available' => false,
];

$connectOverview = [
    'available' => false,
    'error' => null,
    'stats' => [
        'accounts_total' => 0,
        'accounts_active' => 0,
        'accounts_incomplete' => 0,
        'balance_available' => 0,
        'balance_pending' => 0,
    ],
    'accounts' => [],
];
$partnerConnect = null;
$connectFinance = [
    'available' => false,
    'error' => null,
    'payouts_count' => 0,
    'payouts_total' => 0,
    'balance_available' => 0,
    'balance_pending' => 0,
];

// Dati Connect da DB locale (veloci, nessuna chiamata Stripe API)
try {
    tdg_stripe_connect_ensure_schema($pdo);
    $connectOverview['available'] = true;
    $agg = $pdo->query("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN onboarding_completed = 1 THEN 1 ELSE 0 END), 0) AS active,
        COALESCE(SUM(CASE WHEN onboarding_completed = 0 THEN 1 ELSE 0 END), 0) AS incomplete,
        COALESCE(SUM(balance_available), 0) AS balance_available,
        COALESCE(SUM(balance_pending), 0) AS balance_pending
        FROM stripe_connected_accounts")->fetch(PDO::FETCH_ASSOC);
    $connectOverview['stats'] = [
        'accounts_total'      => (int)($agg['total'] ?? 0),
        'accounts_active'     => (int)($agg['active'] ?? 0),
        'accounts_incomplete' => (int)($agg['incomplete'] ?? 0),
        'balance_available'   => (int)($agg['balance_available'] ?? 0),
        'balance_pending'     => (int)($agg['balance_pending'] ?? 0),
    ];
    $connectOverview['accounts'] = tdg_stripe_connect_list_local($pdo, 6);
    $partnerLike = $pdo->quote('%' . strtolower((string)(getenv('STRIPE_PARTNER_MATCH') ?: 'partner')) . '%');
    $partnerStmt = $pdo->query("SELECT
        id, stripe_account_id, business_name, email, onboarding_completed, balance_available, balance_pending, default_currency, charges_enabled, payouts_enabled
        FROM stripe_connected_accounts
        WHERE LOWER(COALESCE(business_name, '')) LIKE {$partnerLike}
           OR LOWER(COALESCE(email, '')) LIKE {$partnerLike}
        ORDER BY updated_at DESC
        LIMIT 1");
    $partnerConnect = $partnerStmt ? $partnerStmt->fetch(PDO::FETCH_ASSOC) : null;
    
    // Dati transazioni Partner
    $partnerTransactions = [];
    $partnerMetrics = [
        'total_transactions' => 0,
        'gross_cents' => 0,
        'vat_cents' => 0,
        'net_cents' => 0,
        'commission_cents' => 0,
    ];
    if ($partnerConnect && !empty($partnerConnect['stripe_account_id'])) {
        try {
            $query = "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(importo), 0) AS total_cents
                FROM pagamenti
                WHERE stato = 'succeeded'
                  AND stripe_id IS NOT NULL";
            $params = [];

            if (!$isAllYears) {
                $query .= " AND YEAR(created_at) = ?";
                $params[] = $annoSelezionato;
            }
            if (!$isAllMonths) {
                $query .= " AND MONTH(created_at) = ?";
                $params[] = $meseSelezionato;
            }

            $partnerMetricsStmt = $pdo->prepare($query);
            $partnerMetricsStmt->execute($params);
            
            $partnerMetricsRow = $partnerMetricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $partnerMetrics['total_transactions'] = (int)($partnerMetricsRow['total_count'] ?? 0);
            $partnerMetrics['gross_cents'] = (int)($partnerMetricsRow['total_cents'] ?? 0);
            $partnerMetrics['vat_cents'] = (int)round($partnerMetrics['gross_cents'] * (22 / 122));
            $partnerMetrics['net_cents'] = $partnerMetrics['gross_cents'] - $partnerMetrics['vat_cents'];
        } catch (Throwable $e) {
            // Silently fail gracefully
        }
    }
} catch (Throwable $e) {
    $connectOverview['error'] = $e->getMessage();
}

function euro_from_cents(int $amountCents): string
{
    return number_format($amountCents / 100, 2, ',', '.');
}

function to_euro_decimal(int $amountCents): string
{
    return number_format($amountCents / 100, 2, '.', '');
}

function vat_split_from_gross_cents(int $grossCents, float $vatRate): array
{
    $rate = max(0, min(100, $vatRate));
    $divider = 1 + ($rate / 100);
    $taxableCents = $divider > 0 ? (int)round($grossCents / $divider) : $grossCents;
    $vatCents = $grossCents - $taxableCents;

    return [
        'gross' => $grossCents,
        'taxable' => $taxableCents,
        'vat' => $vatCents,
    ];
}

function build_period_where(string $column, int $year, int $month, bool $isDateColumn = false): array
{
    $where = '1=1';
    $params = [];

    if ($year > 0 && $month > 0) {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('first day of next month');
        $where = "$column >= ? AND $column < ?";
        if ($isDateColumn) {
            $params[] = $start->format('Y-m-d');
            $params[] = $end->format('Y-m-d');
        } else {
            $params[] = $start->format('Y-m-d 00:00:00');
            $params[] = $end->format('Y-m-d 00:00:00');
        }
    } elseif ($year > 0) {
        $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $end = $start->modify('first day of january next year');
        $where = "$column >= ? AND $column < ?";
        if ($isDateColumn) {
            $params[] = $start->format('Y-m-d');
            $params[] = $end->format('Y-m-d');
        } else {
            $params[] = $start->format('Y-m-d 00:00:00');
            $params[] = $end->format('Y-m-d 00:00:00');
        }
    } elseif ($month > 0) {
        // Mese su tutti gli anni: fallback necessario, non indicizzabile in modo diretto.
        $where = "MONTH($column) = ?";
        $params[] = $month;
    }

    return [$where, $params];
}

function dashboard_cache_path(string $key): string
{
    $dir = __DIR__ . '/../../tmp/dashboard_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . sha1($key) . '.json';
}

function dashboard_cache_get(string $key, int $ttlSeconds): ?array
{
    $path = dashboard_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['cached_at']) || !array_key_exists('payload', $decoded)) {
        return null;
    }

    $cachedAt = (int)$decoded['cached_at'];
    if ($cachedAt <= 0 || (time() - $cachedAt) > $ttlSeconds) {
        @unlink($path);
        return null;
    }

    return is_array($decoded['payload']) ? $decoded['payload'] : null;
}

function dashboard_cache_set(string $key, array $payload): void
{
    $path = dashboard_cache_path($key);
    $body = json_encode([
        'cached_at' => time(),
        'payload' => $payload,
    ]);
    if ($body !== false) {
        @file_put_contents($path, $body, LOCK_EX);
    }
}

function dashboard_cached_compute(string $key, int $ttlSeconds, callable $resolver): array
{
    $cached = dashboard_cache_get($key, $ttlSeconds);
    if (is_array($cached)) {
        return $cached;
    }

    $fresh = $resolver();
    if (!is_array($fresh)) {
        return [];
    }

    dashboard_cache_set($key, $fresh);
    return $fresh;
}

[$whereDataMese, $paramsDataMese] = build_period_where('data_emissione', $annoSelezionato, $meseSelezionato, true);
[$whereDataAnno, $paramsDataAnno] = build_period_where('data_emissione', $annoSelezionato, 0, true);
[$whereCreatedMese, $paramsCreatedMese] = build_period_where('created_at', $annoSelezionato, $meseSelezionato);
[$whereCreatedAnno, $paramsCreatedAnno] = build_period_where('created_at', $annoSelezionato, 0);

$dashboardCacheTtl = 75;

// DATI DEL MESE
$meseStatsKey = 'dash_mese_stats_v2_' . md5(json_encode([$whereDataMese, $paramsDataMese, $whereCreatedMese, $paramsCreatedMese]));
$meseStats = dashboard_cached_compute($meseStatsKey, $dashboardCacheTtl, static function () use ($pdo, $whereDataMese, $paramsDataMese, $whereCreatedMese, $paramsCreatedMese): array {
    $fattureStmt = $pdo->prepare("SELECT
        COUNT(*) AS tot_fatture,
        COALESCE(SUM(CASE WHEN stato = 'pagata' THEN totale ELSE 0 END), 0) AS totale_incassato,
        COALESCE(SUM(CASE WHEN stato <> 'pagata' THEN totale ELSE 0 END), 0) AS totale_da_incassare
        FROM fatture
        WHERE document_type = 'invoice' AND $whereDataMese");
    $fattureStmt->execute($paramsDataMese);
    $fattureRow = $fattureStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $pagamentiStmt = $pdo->prepare("SELECT
        COALESCE(SUM(importo),0) AS total_cents,
        COALESCE(SUM(stripe_fee),0) AS total_fees,
        COUNT(*) AS total_count
        FROM pagamenti
        WHERE stato = 'succeeded' AND $whereCreatedMese");
    $pagamentiStmt->execute($paramsCreatedMese);
    $pagamentiRow = $pagamentiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'tot_fatture' => (int)($fattureRow['tot_fatture'] ?? 0),
        'totale_incassato' => (float)($fattureRow['totale_incassato'] ?? 0),
        'totale_da_incassare' => (float)($fattureRow['totale_da_incassare'] ?? 0),
        'stripe_total_cents' => (int)($pagamentiRow['total_cents'] ?? 0),
        'stripe_total_fees' => (int)($pagamentiRow['total_fees'] ?? 0),
        'stripe_total_count' => (int)($pagamentiRow['total_count'] ?? 0),
    ];
});

$totFattureMese = (int)($meseStats['tot_fatture'] ?? 0);
$totaleIncassatoStripeMeseCents = (int)($meseStats['stripe_total_cents'] ?? 0);
$totaleCommissioniStripeMeseCents = (int)($meseStats['stripe_total_fees'] ?? 0);
$totaleIncassatoStripeMese = $totaleIncassatoStripeMeseCents / 100;
$totaleCommissioniStripeMese = $totaleCommissioniStripeMeseCents / 100;
$totaleNettoStripeMese = ($totaleIncassatoStripeMeseCents - $totaleCommissioniStripeMeseCents) / 100;
$totalePagamentiStripeMese = (int)($meseStats['stripe_total_count'] ?? 0);
$totaleIncassatoMese = (float)($meseStats['totale_incassato'] ?? 0);
$totaleDaIncassareMese = (float)($meseStats['totale_da_incassare'] ?? 0);
$totaleEmessoMese = $totaleIncassatoMese + $totaleDaIncassareMese;
$totaleAltriMetodiMese = $totaleIncassatoMese - $totaleIncassatoStripeMese;
$tassoIncassoMese = $totaleEmessoMese > 0 ? ($totaleIncassatoMese / $totaleEmessoMese) * 100 : 0;

// DATI DELL'ANNO
$annoStatsKey = 'dash_anno_stats_v2_' . md5(json_encode([$whereDataAnno, $paramsDataAnno, $whereCreatedAnno, $paramsCreatedAnno]));
$annoStats = dashboard_cached_compute($annoStatsKey, $dashboardCacheTtl, static function () use ($pdo, $whereDataAnno, $paramsDataAnno, $whereCreatedAnno, $paramsCreatedAnno): array {
    $fattureStmt = $pdo->prepare("SELECT
        COUNT(*) AS tot_fatture,
        COALESCE(SUM(CASE WHEN stato = 'pagata' THEN totale ELSE 0 END), 0) AS totale_incassato,
        COALESCE(SUM(CASE WHEN stato <> 'pagata' THEN totale ELSE 0 END), 0) AS totale_da_incassare
        FROM fatture
        WHERE document_type = 'invoice' AND $whereDataAnno");
    $fattureStmt->execute($paramsDataAnno);
    $fattureRow = $fattureStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $pagamentiStmt = $pdo->prepare("SELECT
        COALESCE(SUM(importo),0) AS total_cents,
        COALESCE(SUM(stripe_fee),0) AS total_fees
        FROM pagamenti
        WHERE stato = 'succeeded' AND $whereCreatedAnno");
    $pagamentiStmt->execute($paramsCreatedAnno);
    $pagamentiRow = $pagamentiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'tot_fatture' => (int)($fattureRow['tot_fatture'] ?? 0),
        'totale_incassato' => (float)($fattureRow['totale_incassato'] ?? 0),
        'totale_da_incassare' => (float)($fattureRow['totale_da_incassare'] ?? 0),
        'stripe_total_cents' => (int)($pagamentiRow['total_cents'] ?? 0),
        'stripe_total_fees' => (int)($pagamentiRow['total_fees'] ?? 0),
    ];
});

$totFattureAnno = (int)($annoStats['tot_fatture'] ?? 0);
$totaleIncassatoStripeAnnoCents = (int)($annoStats['stripe_total_cents'] ?? 0);
$totaleCommissioniStripeAnnoCents = (int)($annoStats['stripe_total_fees'] ?? 0);
$totaleIncassatoStripeAnno = $totaleIncassatoStripeAnnoCents / 100;
$totaleCommissioniStripeAnno = $totaleCommissioniStripeAnnoCents / 100;
$totaleNettoStripeAnno = ($totaleIncassatoStripeAnnoCents - $totaleCommissioniStripeAnnoCents) / 100;
$totaleIncassatoAnno = (float)($annoStats['totale_incassato'] ?? 0);
$totaleDaIncassareAnno = (float)($annoStats['totale_da_incassare'] ?? 0);
$totaleEmessoAnno = $totaleIncassatoAnno + $totaleDaIncassareAnno;
$totaleAltriMetodiAnno = $totaleIncassatoAnno - $totaleIncassatoStripeAnno;
$tassoIncassoAnno = $totaleEmessoAnno > 0 ? ($totaleIncassatoAnno / $totaleEmessoAnno) * 100 : 0;

// DATI GENERALI (non filtrati per data)
$generaliStats = dashboard_cached_compute('dash_stats_generali_v2', 120, static function () use ($pdo): array {
    $totClienti = (int)$pdo->query("SELECT COUNT(*) FROM clienti")->fetchColumn();
    $totPagamenti = (int)$pdo->query("SELECT COUNT(*) FROM pagamenti")->fetchColumn();
    $scadute = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice' AND stato <> 'pagata' AND data_scadenza IS NOT NULL AND data_scadenza < CURDATE()")->fetchColumn();
    $ultime = $pdo->query("SELECT f.id, f.numero, f.data_emissione, f.stato, f.totale, c.nome AS cliente FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.document_type = 'invoice' ORDER BY f.data_emissione DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

    return [
        'tot_clienti' => $totClienti,
        'tot_pagamenti' => $totPagamenti,
        'scadute' => $scadute,
        'ultime' => is_array($ultime) ? $ultime : [],
    ];
});

$totClienti = (int)($generaliStats['tot_clienti'] ?? 0);
$totPagamenti = (int)($generaliStats['tot_pagamenti'] ?? 0);
$scadute = (int)($generaliStats['scadute'] ?? 0);
$ultime = is_array($generaliStats['ultime'] ?? null) ? $generaliStats['ultime'] : [];

// Array mesi in italiano
$mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

$aliquotaIvaInput = isset($_GET['aliquota_iva']) ? str_replace(',', '.', (string)$_GET['aliquota_iva']) : '22';
$aliquotaIva = is_numeric($aliquotaIvaInput) ? (float)$aliquotaIvaInput : 22.0;
$aliquotaIva = max(0, min(100, $aliquotaIva));

$ivaPeriodo = vat_split_from_gross_cents($totaleIncassatoStripeMeseCents, $aliquotaIva);

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$meseCorrenteStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $currentYear, $currentMonth));
$meseCorrenteEnd = $meseCorrenteStart->modify('first day of next month');
$annoCorrenteStart = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $currentYear));
$annoCorrenteEnd = $annoCorrenteStart->modify('first day of january next year');

$correnteStatsKey = 'dash_stats_corrente_v1_' . $currentYear . '_' . $currentMonth;
$correnteStats = dashboard_cached_compute($correnteStatsKey, 120, static function () use ($pdo, $meseCorrenteStart, $meseCorrenteEnd, $annoCorrenteStart, $annoCorrenteEnd): array {
    $stmtMese = $pdo->prepare('SELECT COALESCE(SUM(importo),0) FROM pagamenti WHERE stato = "succeeded" AND created_at >= ? AND created_at < ?');
    $stmtMese->execute([$meseCorrenteStart->format('Y-m-d H:i:s'), $meseCorrenteEnd->format('Y-m-d H:i:s')]);
    $incassoMese = (int)$stmtMese->fetchColumn();

    $stmtAnno = $pdo->prepare('SELECT COALESCE(SUM(importo),0) FROM pagamenti WHERE stato = "succeeded" AND created_at >= ? AND created_at < ?');
    $stmtAnno->execute([$annoCorrenteStart->format('Y-m-d H:i:s'), $annoCorrenteEnd->format('Y-m-d H:i:s')]);
    $incassoAnno = (int)$stmtAnno->fetchColumn();

    return [
        'mese_cents' => $incassoMese,
        'anno_cents' => $incassoAnno,
    ];
});

$incassoMeseCorrenteCents = (int)($correnteStats['mese_cents'] ?? 0);
$ivaMeseCorrente = vat_split_from_gross_cents($incassoMeseCorrenteCents, $aliquotaIva);

$incassoAnnoCorrenteCents = (int)($correnteStats['anno_cents'] ?? 0);
$ivaAnnoCorrente = vat_split_from_gross_cents($incassoAnnoCorrenteCents, $aliquotaIva);

$totaleBonificatoPeriodoCents = (int)($connectFinance['payouts_total'] ?? 0);
$numeroBonificiPeriodo = (int)($connectFinance['payouts_count'] ?? 0);
$saldoDisponibileLiveCents = $connectFinance['available']
    ? (int)($connectFinance['balance_available'] ?? 0)
    : (int)($connectOverview['stats']['balance_available'] ?? 0);
$saldoDaTrasferirePeriodoCents = max(0, $totaleIncassatoStripeMeseCents - $totaleBonificatoPeriodoCents);

$pagamentiRicevutiKey = 'dash_pagamenti_periodo_v1_' . md5(json_encode([$whereCreatedMese, $paramsCreatedMese]));
$pagamentiRicevuti = dashboard_cached_compute($pagamentiRicevutiKey, $dashboardCacheTtl, static function () use ($pdo, $whereCreatedMese, $paramsCreatedMese): array {
    $pagamentiStmt = $pdo->prepare(
        "SELECT
            p.stripe_id, p.importo, p.valuta, p.metodo, p.created_at,
            c.nome AS cliente_nome
        FROM pagamenti p
        LEFT JOIN clienti c ON c.id = p.cliente_id
        WHERE p.stato = 'succeeded' AND $whereCreatedMese
        ORDER BY p.created_at DESC
        LIMIT 12"
    );
    $pagamentiStmt->execute($paramsCreatedMese);
    $rows = $pagamentiStmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
});

$ivaStoricoKey = 'dash_iva_storico_v1_' . md5(json_encode([$whereCreatedMese, $paramsCreatedMese]));
$ivaStoricoRows = dashboard_cached_compute($ivaStoricoKey, $dashboardCacheTtl, static function () use ($pdo, $whereCreatedMese, $paramsCreatedMese): array {
    $ivaStoricoStmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(created_at, '%Y-%m-01') AS periodo,
            COUNT(*) AS pagamenti,
            COALESCE(SUM(importo),0) AS gross_cents
        FROM pagamenti
        WHERE stato = 'succeeded' AND $whereCreatedMese
        GROUP BY periodo
        ORDER BY periodo DESC
        LIMIT 24"
    );
    $ivaStoricoStmt->execute($paramsCreatedMese);
    $rows = $ivaStoricoStmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
});

$ivaStorico = [];
foreach ($ivaStoricoRows as $row) {
    $periodoTs = strtotime((string)($row['periodo'] ?? ''));
    $grossCents = (int)($row['gross_cents'] ?? 0);
    $split = vat_split_from_gross_cents($grossCents, $aliquotaIva);
    $ivaStorico[] = [
        'anno' => $periodoTs ? (int)date('Y', $periodoTs) : 0,
        'mese' => $periodoTs ? (int)date('n', $periodoTs) : 0,
        'pagamenti' => (int)($row['pagamenti'] ?? 0),
        'gross' => $split['gross'],
        'taxable' => $split['taxable'],
        'vat' => $split['vat'],
    ];
}

$periodoLabel = 'Tutti i periodi';
if ($isAllMonths && !$isAllYears) {
    $periodoLabel = 'Anno ' . $annoSelezionato;
} elseif (!$isAllMonths && $isAllYears) {
    $periodoLabel = ($mesi[$meseSelezionato] ?? ('Mese ' . $meseSelezionato)) . ' (tutti gli anni)';
} elseif (!$isAllMonths && !$isAllYears) {
    $periodoLabel = ($mesi[$meseSelezionato] ?? ('Mese ' . $meseSelezionato)) . ' ' . $annoSelezionato;
}

$exportIvaCsv = isset($_GET['export']) && (string)$_GET['export'] === 'iva';
if ($exportIvaCsv) {
    $fileSuffix = date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_iva_' . $fileSuffix . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, ['Sezione', 'Periodo', 'Aliquota IVA %', 'Incasso lordo EUR', 'Imponibile EUR', 'IVA EUR', 'Pagamenti', 'Bonifici', 'Totale bonificato EUR', 'Saldo disponibile EUR'], ';');
        fputcsv(
            $out,
            [
                'Riepilogo incassi',
                $periodoLabel,
                number_format($aliquotaIva, 2, '.', ''),
                to_euro_decimal($ivaPeriodo['gross']),
                to_euro_decimal($ivaPeriodo['taxable']),
                to_euro_decimal($ivaPeriodo['vat']),
                $totalePagamentiStripeMese,
                $numeroBonificiPeriodo,
                to_euro_decimal($totaleBonificatoPeriodoCents),
                to_euro_decimal($saldoDisponibileLiveCents),
            ],
            ';'
        );
        fputcsv(
            $out,
            [
                'Sintesi IVA',
                'Mese corrente',
                number_format($aliquotaIva, 2, '.', ''),
                to_euro_decimal($ivaMeseCorrente['gross']),
                to_euro_decimal($ivaMeseCorrente['taxable']),
                to_euro_decimal($ivaMeseCorrente['vat']),
                '',
                '',
                '',
                '',
            ],
            ';'
        );
        fputcsv(
            $out,
            [
                'Sintesi IVA',
                'Anno corrente',
                number_format($aliquotaIva, 2, '.', ''),
                to_euro_decimal($ivaAnnoCorrente['gross']),
                to_euro_decimal($ivaAnnoCorrente['taxable']),
                to_euro_decimal($ivaAnnoCorrente['vat']),
                '',
                '',
                '',
                '',
            ],
            ';'
        );
        foreach ($ivaStorico as $row) {
            $periodoStorico = ($mesi[$row['mese']] ?? ('Mese ' . $row['mese'])) . ' ' . $row['anno'];
            fputcsv(
                $out,
                [
                    'Storico IVA',
                    $periodoStorico,
                    number_format($aliquotaIva, 2, '.', ''),
                    to_euro_decimal($row['gross']),
                    to_euro_decimal($row['taxable']),
                    to_euro_decimal($row['vat']),
                    $row['pagamenti'],
                    '',
                    '',
                    '',
                ],
                ';'
            );
        }
        fclose($out);
    }
    exit;
}

require __DIR__ . '/../../layouts/header.php';
?>
<style>
.dash-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.dash-filter-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.dash-overview {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 14px;
    margin-top: 14px;
}
.dash-overview-main {
    background: linear-gradient(135deg, #f8fafc, #eef2ff);
}
.dash-overview-main h3 {
    margin: 0;
}
.dash-kpi-controls {
    margin-top: 10px;
    display: flex;
    justify-content: flex-end;
}
.dash-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    color: #1e293b;
    background: #ffffff;
}
.dash-overview-grid {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
}
.dash-metric {
    border: 1px solid #dbeafe;
    background: #ffffff;
    border-radius: 10px;
    padding: 10px;
}
.dash-metric .label {
    color: #475569;
    font-size: 12px;
    margin-bottom: 4px;
}
.dash-metric .value {
    color: #0f172a;
    font-size: 23px;
    font-weight: 700;
    line-height: 1.1;
}
.dash-connect-card {
    text-decoration: none;
    color: inherit;
    border: 1px solid #dbeafe;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    background: #ffffff;
}
.dash-connect-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}
.dash-connect-mini {
    color: #6b7280;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.dash-connect-total {
    margin-top: 8px;
    font-size: 30px;
    font-weight: 700;
    color: #111827;
    line-height: 1;
}
.dash-split-list {
    margin-top: 10px;
    display: grid;
    gap: 8px;
}
.dash-split-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    align-items: baseline;
}
.dash-split-row .k {
    font-size: 12px;
    color: #475569;
}
.dash-split-row .v {
    font-size: 17px;
    font-weight: 700;
    color: #111827;
    text-align: right;
}
.dash-connect-preview {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 10px;
}
.dash-connect-item {
    text-decoration: none;
    color: inherit;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    background: #ffffff;
}
.dash-connect-item:hover {
    border-color: #c7d2fe;
    background: #f8faff;
}
.dash-connect-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.dash-pill {
    display: inline-block;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 11px;
    font-weight: 700;
}
.dash-pill.ok { background: #dcfce7; color: #166534; }
.dash-pill.warn { background: #fff7ed; color: #9a3412; }
.dash-partner-card {
    margin-top: 12px;
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid #c7d2fe;
    background: linear-gradient(120deg, #eef2ff, #f8fafc);
}
.dash-partner-card:hover {
    border-color: #6366f1;
}
.dash-finance-grid {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
}
.dash-finance-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    padding: 14px;
}
.dash-finance-card h3 {
    margin: 0;
}
.dash-finance-note {
    margin-top: 8px;
    font-size: 12px;
    color: #6b7280;
}
.dash-finance-list {
    margin-top: 10px;
    display: grid;
    gap: 7px;
}
.dash-finance-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
}
.dash-finance-row .k {
    color: #475569;
    font-size: 12px;
}
.dash-finance-row .v {
    color: #0f172a;
    font-weight: 700;
    font-size: 16px;
    text-align: right;
}
.dash-table-card {
    margin-top: 14px;
}
.dash-table-wrap {
    overflow-x: auto;
    margin-top: 10px;
}
.dash-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.dash-table th,
.dash-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px;
    text-align: left;
    white-space: nowrap;
}
.dash-table th {
    color: #334155;
    background: #f8fafc;
    font-weight: 700;
}
.dash-mini-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 8px;
}
.dash-mini-table th,
.dash-mini-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 6px;
    text-align: left;
    white-space: nowrap;
}
@media (max-width: 980px) {
    .dash-overview { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .dash-filter-form {
        width: 100%;
    }

    .dash-filter-form label {
        width: 100%;
    }

    .dash-filter-form select,
    .dash-filter-form input,
    .dash-filter-form button {
        width: 100%;
    }

    .dash-connect-preview {
        grid-template-columns: 1fr;
    }

    .dash-metric .value {
        font-size: 20px;
    }

    .dash-finance-row .v {
        font-size: 15px;
    }
}
@media (max-width: 480px) {
    .dash-connect-total {
        font-size: 24px;
    }
}
.dash-async {
    color: #9ca3af;
    font-size: 14px;
    font-style: italic;
    animation: dash-pulse 1.4s ease-in-out infinite;
}
@keyframes dash-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
</style>
<div class="wrap">
    <div class="card">
        <div class="dash-header-row">
            <div>
                <h2>Dashboard aziendale</h2>
                <p class="muted">Riepilogo rapido dell'andamento fatturazione e clienti.</p>
                <div id="stripe-progress-bar-wrap" style="margin:18px 0 0 0;max-width:520px;">
                    <div style="font-size:13px;color:#334155;margin-bottom:4px;">Stripe Totale / Commissioni / Netto</div>
                    <div style="position:relative;height:32px;background:#e0e7ef;border-radius:16px;overflow:hidden;">
                        <div id="stripe-bar-gross" style="position:absolute;left:0;top:0;height:100%;background:#a5d8ff;width:0%;transition:width 0.7s;cursor:pointer;" title=""></div>
                        <div id="stripe-bar-fees" style="position:absolute;left:0;top:0;height:100%;background:#ffd6a5;width:0%;transition:width 0.7s;cursor:pointer;" title=""></div>
                        <div id="stripe-bar-net" style="position:absolute;left:0;top:0;height:100%;background:#b9fbc0;width:0%;transition:width 0.7s;cursor:pointer;" title=""></div>
                        <div id="stripe-bar-labels" style="position:relative;z-index:2;width:100%;height:100%;display:flex;align-items:center;justify-content:space-between;padding:0 12px;font-size:14px;font-weight:600;color:#fff;text-shadow:0 1px 2px #0002;"></div>
                    </div>
                    <div style="display:flex;gap:18px;margin-top:8px;align-items:center;justify-content:flex-start;">
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;">
                            <span style="display:inline-block;width:18px;height:12px;background:#a5d8ff;border-radius:3px;"></span> Totale Stripe
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;">
                            <span style="display:inline-block;width:18px;height:12px;background:#ffd6a5;border-radius:3px;"></span> Commissioni
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;">
                            <span style="display:inline-block;width:18px;height:12px;background:#b9fbc0;border-radius:3px;"></span> Netto
                        </span>
                    </div>
                </div>
            </div>
            <form method="GET" class="dash-filter-form">
                <label style="font-weight:600;color:#374151;">Filtra per:</label>
                <select name="mese" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;">
                    <option value="0" <?php echo $isAllMonths ? 'selected' : ''; ?>>Tutto</option>
                    <?php foreach ($mesi as $num => $nome): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num === $meseSelezionato ? 'selected' : ''; ?>>
                            <?php echo $nome; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="anno" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;">
                    <option value="0" <?php echo $isAllYears ? 'selected' : ''; ?>>Tutti gli anni</option>
                    <?php for ($a = date('Y'); $a >= 2020; $a--): ?>
                        <option value="<?php echo $a; ?>" <?php echo $a === $annoSelezionato ? 'selected' : ''; ?>>
                            <?php echo $a; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <input
                    type="number"
                    name="aliquota_iva"
                    min="0"
                    max="100"
                    step="0.01"
                    value="<?php echo htmlspecialchars(number_format($aliquotaIva, 2, '.', '')); ?>"
                    style="width:110px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:white;"
                    title="Aliquota IVA percentuale"
                >
                <button type="submit" style="padding:8px 16px;background:#3b82f6;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
                    Filtra
                </button>
            </form>
        </div>
    </div>

    <div class="dash-overview">
        <div class="card dash-overview-main">
            <h3>Oggi</h3>
            <p class="muted" style="margin-top:6px;">Panoramica Stripe e Connect per il periodo selezionato.</p>
            <div class="dash-kpi-controls">
                <label class="dash-toggle">
                    <input type="checkbox" id="dash-iva-toggle" checked>
                    Mostra con IVA
                </label>
            </div>
            <div class="dash-overview-grid">
                <div class="dash-metric">
                    <div class="label">Riuscito (Stripe)</div>
                    <div class="value" id="dash-s-gross"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-metric">
                    <div class="label">Commissioni Stripe</div>
                    <div class="value" id="dash-s-net"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-metric">
                    <div class="label">IVA</div>
                    <div class="value" id="dash-s-fees"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-metric">
                    <div class="label">Totale netto finale</div>
                    <div class="value" id="dash-s-refunds"><span class="dash-async">&hellip;</span></div>
                </div>
            </div>
        </div>

        <div class="card dash-connect-card">
            <div class="dash-connect-mini">Ripartizione guadagni</div>
            <h3 style="margin-top:8px;">Partner 70% - Piattaforma 30%</h3>
            <p class="muted" style="margin:8px 0 0 0;">La piattaforma incassa il 100% IVA.</p>
            <div class="dash-split-list">
                <div class="dash-split-row">
                    <div class="k">Base ripartizione</div>
                    <div class="v" id="dash-split-base"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-split-row">
                    <div class="k">Quota Partner (70%)</div>
                    <div class="v" id="dash-split-partner"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-split-row">
                    <div class="k">Quota Piattaforma (30% + IVA)</div>
                    <div class="v" id="dash-split-platform"><span class="dash-async">&hellip;</span></div>
                </div>
            </div>
            <p class="dash-finance-note" id="dash-split-note" style="margin-top:10px;">Modalita: con IVA</p>
        </div>
    </div>

    <?php
    $exportQuery = http_build_query([
        'export' => 'iva',
        'mese' => $meseSelezionato,
        'anno' => $annoSelezionato,
        'aliquota_iva' => number_format($aliquotaIva, 2, '.', ''),
    ]);
    ?>
    <div class="dash-finance-grid">
        <div class="dash-finance-card">
            <h3>Partner - Bonifici reali</h3>
            <p class="muted" style="margin:6px 0 0 0;">Periodo: <?php echo htmlspecialchars($periodoLabel); ?></p>
            <div class="dash-finance-list">
                <div class="dash-finance-row">
                    <div class="k">Bonifici ricevuti</div>
                    <div class="v" id="dash-partner-bonif-n"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Totale bonificato reale</div>
                    <div class="v" id="dash-partner-bonif-tot"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row" style="border-top:1px dashed #e5e7eb;padding-top:6px;margin-top:4px;">
                    <div class="k">In arrivo (pending)</div>
                    <div class="v" id="dash-partner-pending-tot" style="color:#b45309;"><span class="dash-async">&hellip;</span></div>
                </div>
            </div>
            <div class="dash-finance-note">Fonte: payout Stripe (paid + pending/in_transit).</div>
        </div>

        <div class="dash-finance-card">
            <h3>Piattaforma - Bonifici reali</h3>
            <p class="muted" style="margin:6px 0 0 0;">Totali reali dei bonifici Stripe nel periodo.</p>
            <div class="dash-finance-list">
                <div class="dash-finance-row">
                    <div class="k">Bonifici ricevuti</div>
                    <div class="v" id="dash-s-bonif-n"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Totale bonificato</div>
                    <div class="v" id="dash-s-bonif-tot"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row" style="border-top:1px dashed #e5e7eb;padding-top:6px;margin-top:4px;">
                    <div class="k">In arrivo (pending)</div>
                    <div class="v" id="dash-s-pending-tot" style="color:#b45309;"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row" style="border-top:1px dashed #e5e7eb;padding-top:6px;margin-top:4px;">
                    <div class="k">Saldo disponibile live</div>
                    <div class="v" id="dash-s-saldo"><span class="dash-async">&hellip;</span></div>
                </div>
            </div>
            <div id="dash-s-finance-note" class="dash-finance-note" style="display:none;"></div>
        </div>

        <div class="dash-finance-card">
            <h3>Box 3 - Sintesi IVA</h3>
            <p class="muted" style="margin:6px 0 0 0;">Riepilogo automatico mese/anno corrente.</p>
            <div class="dash-finance-list">
                <div class="dash-finance-row">
                    <div class="k">IVA mese corrente</div>
                    <div class="v">&euro;<?php echo euro_from_cents($ivaMeseCorrente['vat']); ?></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">IVA anno corrente</div>
                    <div class="v">&euro;<?php echo euro_from_cents($ivaAnnoCorrente['vat']); ?></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Pagamenti ricevuti nel periodo</div>
                    <div class="v"><?php echo number_format($totalePagamentiStripeMese, 0, ',', '.'); ?></div>
                </div>
            </div>
            <div style="margin-top:10px;">
                <a class="btn secondary" href="?<?php echo htmlspecialchars($exportQuery); ?>">Esporta report IVA CSV</a>
            </div>
        </div>
    </div>

    <div class="dash-finance-grid">
        <div class="dash-finance-card" style="border-color:#c7d2fe;background:linear-gradient(135deg,#f8fafc,#eef2ff);">
            <h3>Riconciliazione</h3>
            <p class="muted" style="margin:6px 0 0 0;">Netto (senza IVA) = Pagato + In arrivo + Saldo Stripe</p>
            <div class="dash-finance-list">
                <div class="dash-finance-row">
                    <div class="k">Netto reale (senza IVA)</div>
                    <div class="v" id="dash-rec-netto"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Bonifici pagati (Partner + Piattaforma)</div>
                    <div class="v" id="dash-rec-paid"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Bonifici in arrivo (Partner + Piattaforma)</div>
                    <div class="v" id="dash-rec-pending" style="color:#b45309;"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row">
                    <div class="k">Saldo ancora su Stripe</div>
                    <div class="v" id="dash-rec-saldo"><span class="dash-async">&hellip;</span></div>
                </div>
                <div class="dash-finance-row" style="border-top:2px solid #c7d2fe;padding-top:6px;margin-top:4px;">
                    <div class="k" style="font-weight:700;">Differenza</div>
                    <div class="v" id="dash-rec-diff"><span class="dash-async">&hellip;</span></div>
                </div>
            </div>
            <div class="dash-finance-note" id="dash-rec-note">Se la differenza e zero, i numeri quadrano: netto = pagato + in arrivo + saldo.</div>
        </div>
    </div>

    <div class="dash-finance-grid">
        <div class="dash-finance-card">
            <h3>Dettaglio bonifici Partner</h3>
            <div class="dash-table-wrap">
                <table class="dash-mini-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Importo</th>
                            <th>ID</th>
                        </tr>
                    </thead>
                    <tbody id="dash-partner-payouts-body">
                        <tr><td colspan="3" class="muted">Caricamento in corso...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="dash-finance-card">
            <h3>Dettaglio bonifici Piattaforma</h3>
            <div class="dash-table-wrap">
                <table class="dash-mini-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Importo</th>
                            <th>ID</th>
                        </tr>
                    </thead>
                    <tbody id="dash-platform-payouts-body">
                        <tr><td colspan="3" class="muted">Caricamento in corso...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="dash-finance-grid">
        <div class="dash-finance-card" style="border-color:#fde68a;">
            <h3 style="color:#92400e;">Bonifici in arrivo - Partner</h3>
            <div class="dash-table-wrap">
                <table class="dash-mini-table">
                    <thead>
                        <tr>
                            <th>Arrivo previsto</th>
                            <th>Importo</th>
                            <th>Stato</th>
                            <th>ID</th>
                        </tr>
                    </thead>
                    <tbody id="dash-partner-pending-body">
                        <tr><td colspan="4" class="muted">Caricamento in corso...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="dash-finance-card" style="border-color:#fde68a;">
            <h3 style="color:#92400e;">Bonifici in arrivo - Piattaforma</h3>
            <div class="dash-table-wrap">
                <table class="dash-mini-table">
                    <thead>
                        <tr>
                            <th>Arrivo previsto</th>
                            <th>Importo</th>
                            <th>Stato</th>
                            <th>ID</th>
                        </tr>
                    </thead>
                    <tbody id="dash-platform-pending-body">
                        <tr><td colspan="4" class="muted">Caricamento in corso...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card dash-table-card">
        <h3 style="margin:0;">Storico IVA per periodo</h3>
        <p class="muted" style="margin:6px 0 0 0;">Scorporo IVA su incassi lordi (importi gia IVA inclusa).</p>
        <div class="dash-table-wrap">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Periodo</th>
                        <th>Pagamenti</th>
                        <th>Incasso lordo</th>
                        <th>Imponibile</th>
                        <th>IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ivaStorico): ?>
                        <tr><td colspan="5" class="muted">Nessun dato IVA nel periodo selezionato.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ivaStorico as $row): ?>
                        <?php $labelPeriodoStorico = ($mesi[$row['mese']] ?? ('Mese ' . $row['mese'])) . ' ' . $row['anno']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($labelPeriodoStorico); ?></td>
                            <td><?php echo number_format($row['pagamenti'], 0, ',', '.'); ?></td>
                            <td>&euro;<?php echo euro_from_cents($row['gross']); ?></td>
                            <td>&euro;<?php echo euro_from_cents($row['taxable']); ?></td>
                            <td>&euro;<?php echo euro_from_cents($row['vat']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card dash-table-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 style="margin:0;">Storico pagamenti ricevuti</h3>
                <p class="muted" style="margin:6px 0 0 0;">Ultimi pagamenti Stripe riusciti del periodo selezionato.</p>
            </div>
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/transazioni.php?tipo=pagamenti&mese=<?php echo $meseSelezionato; ?>&anno=<?php echo $annoSelezionato; ?>&aliquota_iva=<?php echo number_format($aliquotaIva, 2, '.', ''); ?>">Vedi tutte &rarr;</a>
        </div>
        <div class="dash-table-wrap">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>ID Stripe</th>
                        <th>Importo lordo</th>
                        <th>Imponibile</th>
                        <th>IVA</th>
                        <th>Metodo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$pagamentiRicevuti): ?>
                        <tr><td colspan="7" class="muted">Nessun pagamento ricevuto nel periodo selezionato.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pagamentiRicevuti as $pagamento): ?>
                        <?php
                        $splitPagamento = vat_split_from_gross_cents((int)($pagamento['importo'] ?? 0), $aliquotaIva);
                        $clientePagamento = trim((string)($pagamento['cliente_nome'] ?? ''));
                        $clientePagamento = $clientePagamento !== '' ? $clientePagamento : '-';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)($pagamento['created_at'] ?? 'now')))); ?></td>
                            <td><?php echo htmlspecialchars($clientePagamento); ?></td>
                            <td><span style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars((string)($pagamento['stripe_id'] ?? '-')); ?></span></td>
                            <td>&euro;<?php echo euro_from_cents($splitPagamento['gross']); ?></td>
                            <td>&euro;<?php echo euro_from_cents($splitPagamento['taxable']); ?></td>
                            <td>&euro;<?php echo euro_from_cents($splitPagamento['vat']); ?></td>
                            <td><?php echo htmlspecialchars((string)($pagamento['metodo'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($connectOverview['accounts']): ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <h3 style="margin:0;">Anteprima account Connect</h3>
                <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/connect/index.php?sync=1">Aggiorna sync</a>
            </div>
            <div class="dash-connect-preview">
                <?php foreach ($connectOverview['accounts'] as $acc): ?>
                    <?php
                    $isActive = (int)($acc['onboarding_completed'] ?? 0) === 1;
                    $accountId = (string)($acc['stripe_account_id'] ?? '');
                    ?>
                    <a class="dash-connect-item" href="<?php echo $config['app']['base_url']; ?>/modules/connect/index.php?account=<?php echo urlencode($accountId); ?>">
                        <div class="dash-connect-row">
                            <strong><?php echo htmlspecialchars((string)(($acc['business_name'] ?? '') !== '' ? $acc['business_name'] : $accountId)); ?></strong>
                            <span class="dash-pill <?php echo $isActive ? 'ok' : 'warn'; ?>">
                                <?php echo $isActive ? 'Attivo' : 'Onboarding'; ?>
                            </span>
                        </div>
                        <div style="font-family:monospace;font-size:12px;color:#6b7280;margin-top:4px;"><?php echo htmlspecialchars($accountId); ?></div>
                        <div style="margin-top:8px;color:#374151;font-size:13px;">
                            Disp: &euro;<?php echo euro_from_cents((int)($acc['balance_available'] ?? 0)); ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <h3 style="margin:24px 0 12px 0;color:#374151;">
        Dati
        <?php if ($isAllMonths && $isAllYears): ?>
            complessivi
        <?php elseif ($isAllMonths): ?>
            anno <?php echo $annoSelezionato; ?>
        <?php elseif ($isAllYears): ?>
            di <?php echo $mesi[$meseSelezionato]; ?> (tutti gli anni)
        <?php else: ?>
            di <?php echo $mesi[$meseSelezionato] . ' ' . $annoSelezionato; ?>
        <?php endif; ?>
    </h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div class="card" style="background:#f0f9ff;border-color:#bfdbfe;">
            <h3 style="color:#1e40af;">Fatture emesse</h3>
            <p><strong style="font-size:24px;color:#1e40af;"><?php echo number_format($totFattureMese, 0, ',', '.'); ?></strong></p>
        </div>
        <div class="card" style="background:#f0fdf4;border-color:#bbf7d0;">
            <h3 style="color:#15803d;">Incassato Stripe (lordo)</h3>
            <p><strong style="font-size:20px;color:#15803d;">&euro;<?php echo number_format($totaleIncassatoStripeMese, 2, ',', '.'); ?></strong></p>
            <p style="font-size:11px;color:#6b7280;margin-top:4px;">Da tabella pagamenti</p>
            <hr style="margin:8px 0;">
            <span style="font-size:13px;color:#b45309;">Commissioni Stripe: <strong>&euro;<?php echo number_format($totaleCommissioniStripeMese, 2, ',', '.'); ?></strong></span><br>
            <span style="font-size:13px;color:#166534;">Netto Stripe: <strong>&euro;<?php echo number_format($totaleNettoStripeMese, 2, ',', '.'); ?></strong></span>
        </div>
        <div class="card" style="background:#f0fdf4;border-color:#bbf7d0;">
            <h3 style="color:#15803d;">Totale fatture pagate</h3>
            <p><strong style="font-size:20px;color:#15803d;">&euro;<?php echo number_format($totaleIncassatoMese, 2, ',', '.'); ?></strong></p>
        </div>
        <?php
        $altriMetodiMeseColor = $totaleAltriMetodiMese >= 0 ? '#15803d' : '#be123c';
        $altriMetodiMeseBg = $totaleAltriMetodiMese >= 0 ? '#f0fdf4' : '#fff1f2';
        $altriMetodiMeseBorder = $totaleAltriMetodiMese >= 0 ? '#bbf7d0' : '#fecdd3';
        ?>
        <div class="card" style="background:<?php echo $altriMetodiMeseBg; ?>;border-color:<?php echo $altriMetodiMeseBorder; ?>;" title="Differenza tra fatture pagate e incassato Stripe (bonifici, contanti, ecc.)">
            <h3 style="color:<?php echo $altriMetodiMeseColor; ?>;margin-bottom:4px;">Incassato extra Stripe</h3>
            <p><strong style="font-size:20px;color:<?php echo $altriMetodiMeseColor; ?>;">&euro;<?php echo number_format($totaleAltriMetodiMese, 2, ',', '.'); ?></strong></p>
            <p style="font-size:11px;color:#6b7280;margin-top:4px;">Bonifici, contanti, ecc.</p>
        </div>
        <div class="card" style="background:#fef3c7;border-color:#fde68a;">
            <h3 style="color:#92400e;">Fatture da incassare</h3>
            <p><strong style="font-size:20px;color:#92400e;">&euro;<?php echo number_format($totaleDaIncassareMese, 2, ',', '.'); ?></strong></p>
        </div>
        <div class="card" style="background:#eff6ff;border-color:#bfdbfe;">
            <h3 style="color:#1d4ed8;">% Tasso incasso</h3>
            <p><strong style="font-size:20px;color:#1d4ed8;"><?php echo number_format($tassoIncassoMese, 1, ',', '.'); ?>%</strong></p>
            <p style="font-size:11px;color:#6b7280;margin-top:4px;">Pagate / Totale emesso</p>
        </div>
    </div>

    <?php if (!($isAllMonths && $isAllYears)): ?>
        <h3 style="margin:32px 0 12px 0;color:#374151;">
            <?php echo $isAllYears ? 'Totale complessivo' : 'Totale anno ' . $annoSelezionato; ?>
        </h3>
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
            <div class="card">
                <h3>Fatture emesse</h3>
                <p><strong><?php echo number_format($totFattureAnno, 0, ',', '.'); ?></strong> totali</p>
            </div>
            <div class="card">
                <h3>Incassato Stripe (lordo)</h3>
                <p><strong>&euro;<?php echo number_format($totaleIncassatoStripeAnno, 2, ',', '.'); ?></strong></p>
                <hr style="margin:8px 0;">
                <span style="font-size:13px;color:#b45309;">Commissioni Stripe: <strong>&euro;<?php echo number_format($totaleCommissioniStripeAnno, 2, ',', '.'); ?></strong></span><br>
                <span style="font-size:13px;color:#166534;">Netto Stripe: <strong>&euro;<?php echo number_format($totaleNettoStripeAnno, 2, ',', '.'); ?></strong></span>
            </div>
            <div class="card">
                <h3>Totale fatture pagate</h3>
                <p><strong>&euro;<?php echo number_format($totaleIncassatoAnno, 2, ',', '.'); ?></strong></p>
            </div>
            <?php
            $altriMetodiAnnoColor = $totaleAltriMetodiAnno >= 0 ? '#15803d' : '#be123c';
            $altriMetodiAnnoBg = $totaleAltriMetodiAnno >= 0 ? '#f0fdf4' : '#fff1f2';
            $altriMetodiAnnoBorder = $totaleAltriMetodiAnno >= 0 ? '#bbf7d0' : '#fecdd3';
            ?>
            <div class="card" style="background:<?php echo $altriMetodiAnnoBg; ?>;border-color:<?php echo $altriMetodiAnnoBorder; ?>;" title="Differenza tra fatture pagate e incassato Stripe (bonifici, contanti, ecc.)">
                <h3 style="color:<?php echo $altriMetodiAnnoColor; ?>;margin-bottom:4px;">Incassato extra Stripe</h3>
                <p><strong style="font-size:20px;color:<?php echo $altriMetodiAnnoColor; ?>;">&euro;<?php echo number_format($totaleAltriMetodiAnno, 2, ',', '.'); ?></strong></p>
                <p style="font-size:11px;color:#6b7280;margin-top:4px;">Bonifici, contanti, ecc.</p>
            </div>
            <div class="card">
                <h3>Fatture da incassare</h3>
                <p><strong>&euro;<?php echo number_format($totaleDaIncassareAnno, 2, ',', '.'); ?></strong></p>
            </div>
            <div class="card" style="background:#eff6ff;border-color:#bfdbfe;">
                <h3 style="color:#1d4ed8;">% Tasso incasso</h3>
                <p><strong style="font-size:20px;color:#1d4ed8;"><?php echo number_format($tassoIncassoAnno, 1, ',', '.'); ?>%</strong></p>
                <p style="font-size:11px;color:#6b7280;margin-top:4px;">Pagate / Totale emesso</p>
            </div>
        </div>
    <?php endif; ?>

    <h3 style="margin:32px 0 12px 0;color:#374151;">Indicatori aziendali</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div class="card">
            <h3>Anagrafe clienti</h3>
            <p><strong><?php echo number_format($totClienti, 0, ',', '.'); ?></strong> totali</p>
        </div>
        <div class="card">
            <h3>Pagamenti Stripe</h3>
            <p><strong><?php echo number_format($totPagamenti, 0, ',', '.'); ?></strong> totali</p>
        </div>
        <div class="card">
            <h3>Fatture scadute</h3>
            <p><strong><?php echo number_format($scadute, 0, ',', '.'); ?></strong> da recuperare</p>
        </div>
    </div>

    <h3 style="margin:32px 0 12px 0;color:#374151;">
        Volumi Stripe
        <?php if ($isAllMonths && $isAllYears): ?>
            complessivi
        <?php elseif ($isAllMonths): ?>
            anno <?php echo $annoSelezionato; ?>
        <?php elseif ($isAllYears): ?>
            di <?php echo $mesi[$meseSelezionato]; ?> (tutti gli anni)
        <?php else: ?>
            di <?php echo $mesi[$meseSelezionato] . ' ' . $annoSelezionato; ?>
        <?php endif; ?>
    </h3>
    <div id="dash-stripe-section">
        <div class="card" style="border-color:#e5e7eb;background:#f8fafc;text-align:center;">
            <p class="muted" style="padding:8px 0;"><span class="dash-async">Caricamento dati Stripe in corso&hellip;</span></p>
            <div style="max-width:700px;margin:0 auto;">
                <canvas id="stripe-bar-chart" height="120"></canvas>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <div class="card">
        <h3>Esportazioni rapide</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/clienti/esporta_csv.php">Clienti CSV</a>
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/fatture/esporta_csv.php">Fatture CSV</a>
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/ordini/esporta_csv.php">Ordini CSV</a>
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/preventivi/esporta_csv.php">Preventivi CSV</a>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3 style="margin:0;">Ultime fatture</h3>
            <a class="btn secondary" href="<?php echo $config['app']['base_url']; ?>/modules/dashboard/transazioni.php?tipo=fatture&mese=<?php echo $meseSelezionato; ?>&anno=<?php echo $annoSelezionato; ?>">Vedi tutte &rarr;</a>
        </div>
        <table style="margin-top:10px;">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Data</th>
                    <th>Stato</th>
                    <th>Totale</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ultime as $f): ?>
                <tr>
                    <td><?php echo htmlspecialchars($f['numero']); ?></td>
                    <td><?php echo htmlspecialchars($f['cliente']); ?></td>
                    <td><?php echo htmlspecialchars($f['data_emissione']); ?></td>
                    <td><span class="badge <?php echo htmlspecialchars($f['stato']); ?>"><?php echo htmlspecialchars($f['stato']); ?></span></td>
                    <td><?php echo number_format((float)$f['totale'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ultime): ?>
                <tr><td colspan="5" class="muted">Nessuna fattura.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    // Progress bar Stripe nella card principale
    function updateStripeProgressBar(m) {
        var gross = Number(m.charges_amount_succeeded || 0);
        var fees = Number(m.fees || 0);
        var net = gross - fees;
        var max = Math.max(gross, net + fees, 1);
        var grossPerc = Math.round((gross / max) * 100);
        var feesPerc = Math.round((fees / max) * 100);
        var netPerc = Math.round((net / max) * 100);
        var grossBar = document.getElementById('stripe-bar-gross');
        var feesBar = document.getElementById('stripe-bar-fees');
        var netBar = document.getElementById('stripe-bar-net');
        var labels = document.getElementById('stripe-bar-labels');
        if (grossBar && feesBar && netBar && labels) {
            grossBar.style.width = grossPerc + '%';
            feesBar.style.width = feesPerc + '%';
            netBar.style.width = netPerc + '%';
            labels.innerHTML = '';
            // Tooltip sugli importi
            grossBar.title = 'Totale Stripe: €' + (gross/100).toLocaleString('it-IT', {minimumFractionDigits:2});
            feesBar.title = 'Commissioni: €' + (fees/100).toLocaleString('it-IT', {minimumFractionDigits:2});
            netBar.title = 'Netto: €' + (net/100).toLocaleString('it-IT', {minimumFractionDigits:2});
        }
    }
    var mese = <?php echo $meseSelezionato; ?>;
    var anno = <?php echo $annoSelezionato; ?>;
    var iva  = '<?php echo number_format($aliquotaIva, 2, '.', ''); ?>';
    var incassatoCents = <?php echo $totaleIncassatoStripeMeseCents; ?>;

    function euros(cents) {
        return new Intl.NumberFormat('it-IT', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
            useGrouping: true
        }).format((Number(cents) || 0) / 100);
    }
    function numIt(n) {
        return Number(n).toLocaleString('it-IT');
    }
    function setTxt(id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
    }
    function escH(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function vatFromGrossCents(grossCents, vatPercent) {
        var rate = Math.max(0, Math.min(100, Number(vatPercent) || 0));
        var divider = 1 + (rate / 100);
        var gross = Number(grossCents) || 0;
        var taxable = divider > 0 ? Math.round(gross / divider) : gross;
        return gross - taxable;
    }
    function renderPayoutRows(targetId, rows) {
        var el = document.getElementById(targetId);
        if (!el) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            el.innerHTML = '<tr><td colspan="3" class="muted">Nessun bonifico nel periodo selezionato.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            var ts = Number(row.arrival_date || row.created || 0);
            var dateStr = ts > 0 ? new Date(ts * 1000).toLocaleDateString('it-IT') : '-';
            html += '<tr>'
                + '<td>' + escH(dateStr) + '</td>'
                + '<td>' + escH(euros(Number(row.amount || 0))) + '</td>'
                + '<td><span style="font-family:monospace;font-size:11px;">' + escH(String(row.id || '-')) + '</span></td>'
                + '</tr>';
        });
        el.innerHTML = html;
    }
    function renderPendingRows(targetId, rows) {
        var el = document.getElementById(targetId);
        if (!el) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            el.innerHTML = '<tr><td colspan="4" class="muted">Nessun bonifico in arrivo.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            var ts = Number(row.arrival_date || row.created || 0);
            var dateStr = ts > 0 ? new Date(ts * 1000).toLocaleDateString('it-IT') : '-';
            var statusLabel = row.status === 'in_transit' ? 'In transito' : 'Pending';
            html += '<tr>'
                + '<td>' + escH(dateStr) + '</td>'
                + '<td style="color:#b45309;font-weight:700;">' + escH(euros(Number(row.amount || 0))) + '</td>'
                + '<td><span style="font-size:11px;">' + escH(statusLabel) + '</span></td>'
                + '<td><span style="font-family:monospace;font-size:11px;">' + escH(String(row.id || '-')) + '</span></td>'
                + '</tr>';
        });
        el.innerHTML = html;
    }

    var toggleVat = document.getElementById('dash-iva-toggle');
    var latestData = null;

    function applyKpis(data) {
        latestData = data || latestData || {};
        var m = (latestData.metrics || {});
        // Aggiorna la progress bar Stripe nella card principale
        updateStripeProgressBar(m);
        var split = (latestData.payout_split || {});
        var fin = (latestData.finance || {});
        var includeVat = !toggleVat || !!toggleVat.checked;

        var succeeded = Number(m.charges_amount_succeeded || 0);

        var platform = split.platform || {};
        var partner = split.partner || {};
        var platformPending = split.platform_pending || {};
        var partnerPending = split.partner_pending || {};

        // Somma reale dai payouts (fonte di verita)
        var totalPaid = Number(platform.payouts_total || 0) + Number(partner.payouts_total || 0);
        var totalPending = Number(platformPending.payouts_total || 0) + Number(partnerPending.payouts_total || 0);
        var balAvail = Number(fin.balance_available || 0);
        var balPending = Number(fin.balance_pending || 0);
        var totalSaldo = balAvail + balPending;

        // Netto reale = tutto cio che Stripe ha bonificato/bonificera + saldo residuo
        // Questa e la fonte di verita, NON il calcolo teorico da balance transactions
        var hasPayoutData = !!(platform.available || partner.available);
        var realNetSenzaIva = totalPaid + totalPending + totalSaldo;

        // Commissioni reali derivate: Riuscito - Netto reale
        // (include fees Stripe, application fees, Connect splits, ecc.)
        var realFees = hasPayoutData ? Math.max(0, succeeded - realNetSenzaIva) : Math.max(0, Number(m.fees || 0));

        // Netto reale senza IVA (fallback se no payout data)
        var netSenzaIva = hasPayoutData ? realNetSenzaIva : (succeeded - realFees);

        // IVA scorporata dal lordo (solo se toggle attivo)
        var ivaCents = includeVat ? vatFromGrossCents(succeeded, iva) : 0;

        // Base imponibile visualizzata quando si separa l'IVA.
        var baseForSplit = includeVat ? Math.max(0, netSenzaIva - ivaCents) : netSenzaIva;
        var netFinal = baseForSplit;

        setTxt('dash-s-gross', m.connected ? euros(succeeded) : '\u2014');
        setTxt('dash-s-net', m.connected ? euros(realFees) : '\u2014');
        setTxt('dash-s-fees', m.connected ? euros(ivaCents) : '\u2014');
        setTxt('dash-s-refunds', m.connected ? euros(netFinal) : '\u2014');

        var partnerShare = Math.round(baseForSplit * 0.70);
        var platformShare = Math.max(0, netSenzaIva - partnerShare);

        setTxt('dash-split-base', m.connected ? euros(baseForSplit) : '\u2014');
        setTxt('dash-split-partner', m.connected ? euros(partnerShare) : '\u2014');
        setTxt('dash-split-platform', m.connected ? euros(platformShare) : '\u2014');

        var note = document.getElementById('dash-split-note');
        if (note) {
            note.textContent = includeVat
                ? 'Modalita: con IVA (Piattaforma = 30% imponibile + 100% IVA)'
                : 'Modalita: senza IVA (ripartizione 70/30 sul netto reale)';
        }

        // Partner - paid + pending
        if (partner.available) {
            setTxt('dash-partner-bonif-n', numIt(partner.payouts_count || 0));
            setTxt('dash-partner-bonif-tot', euros(partner.payouts_total || 0));
            setTxt('dash-partner-pending-tot', euros(partnerPending.payouts_total || 0));
        } else {
            setTxt('dash-partner-bonif-n', '\u2014');
            setTxt('dash-partner-bonif-tot', '\u2014');
            setTxt('dash-partner-pending-tot', '\u2014');
        }

        // Piattaforma - paid + pending
        if (platform.available) {
            setTxt('dash-s-bonif-n', numIt(platform.payouts_count || 0));
            setTxt('dash-s-bonif-tot', euros(platform.payouts_total || 0));
            setTxt('dash-s-pending-tot', euros(platformPending.payouts_total || 0));
            setTxt('dash-s-saldo', euros(totalSaldo));
        } else {
            setTxt('dash-s-bonif-n', '\u2014');
            setTxt('dash-s-bonif-tot', '\u2014');
            setTxt('dash-s-pending-tot', '\u2014');
            setTxt('dash-s-saldo', '\u2014');
        }

        // Riconciliazione: Netto(senza IVA) = Pagati + Pending + Saldo (per costruzione)
        // La differenza deve essere sempre 0 o vicina a 0
        var diff = netSenzaIva - totalPaid - totalPending - totalSaldo;

        if (m.connected) {
            // GRAFICO BAR CHART STRIPE
            var ctx = document.getElementById('stripe-bar-chart');
            if (ctx) {
                var chartData = {
                    labels: [
                        'Incassato (Riuscito)',
                        'Commissioni Stripe',
                        'IVA',
                        'Netto finale',
                        'Rimborsi',
                        'Pagamenti totali',
                        'Pagamenti riusciti',
                        'Pagamenti falliti',
                        'Pagamenti pending'
                    ],
                    datasets: [{
                        label: 'Valori Stripe',
                        data: [
                            succeeded / 100,
                            realFees / 100,
                            ivaCents / 100,
                            netFinal / 100,
                            Number(m.refunds || 0) / 100,
                            Number(m.charges_total || 0),
                            Number(m.charges_succeeded || 0),
                            Number(m.charges_failed || 0),
                            Number(m.charges_pending || 0)
                        ],
                        backgroundColor: [
                            '#3b82f6', // Incassato
                            '#f59e42', // Commissioni
                            '#facc15', // IVA
                            '#22c55e', // Netto
                            '#ef4444', // Rimborsi
                            '#6366f1', // Totali
                            '#16a34a', // Riusciti
                            '#dc2626', // Falliti
                            '#fbbf24'  // Pending
                        ],
                        borderRadius: 8,
                        barPercentage: 0.7,
                        categoryPercentage: 0.7
                    }]
                };
                if (window.stripeBarChart) window.stripeBarChart.destroy();
                window.stripeBarChart = new Chart(ctx, {
                    type: 'bar',
                    data: chartData,
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        var label = context.dataset.label || '';
                                        if (context.dataIndex <= 3 || context.dataIndex === 4) {
                                            return label + ': €' + context.parsed.x.toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2});
                                        } else {
                                            return label + ': ' + context.parsed.x;
                                        }
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                grid: { color: '#e5e7eb' },
                                ticks: {
                                    callback: function(value, index, values) {
                                        return value >= 1 ? '€' + value.toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2}) : value;
                                    }
                                }
                            },
                            y: {
                                grid: { color: '#e5e7eb' }
                            }
                        }
                    }
                });
            }
            setTxt('dash-rec-netto', euros(netSenzaIva));
            setTxt('dash-rec-paid', euros(totalPaid));
            setTxt('dash-rec-pending', euros(totalPending));
            setTxt('dash-rec-saldo', euros(totalSaldo));
            setTxt('dash-rec-diff', euros(diff));

            var diffEl = document.getElementById('dash-rec-diff');
            if (diffEl) {
                diffEl.style.color = Math.abs(diff) < 100 ? '#166534' : '#be123c';
            }
            var recNote = document.getElementById('dash-rec-note');
            if (recNote) {
                if (Math.abs(diff) < 100) {
                    recNote.textContent = 'I numeri quadrano correttamente.';
                    recNote.style.color = '#166534';
                } else {
                    recNote.textContent = 'Differenza di ' + euros(Math.abs(diff)) + ' — potrebbe dipendere da rimborsi, dispute o tempi di elaborazione.';
                    recNote.style.color = '#be123c';
                }
            }
        } else {
            ['dash-rec-netto','dash-rec-paid','dash-rec-pending','dash-rec-saldo','dash-rec-diff'].forEach(function(id) {
                setTxt(id, '\u2014');
            });
        }

        // Render detail tables
        renderPayoutRows('dash-partner-payouts-body', partner.items || []);
        renderPayoutRows('dash-platform-payouts-body', platform.items || []);
        renderPendingRows('dash-partner-pending-body', partnerPending.items || []);
        renderPendingRows('dash-platform-pending-body', platformPending.items || []);
    }

    var url = 'stripe_data.php?mese=' + mese + '&anno=' + anno + '&aliquota_iva=' + iva;
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            applyKpis(d || {});

            // Sezione Stripe in fondo
            var el = document.getElementById('dash-stripe-section');
            if (el) {
                var m = (d || {}).metrics || {};
                if (!m.connected) {
                    el.innerHTML = '<div class="card" style="border-color:#fecaca;background:#fff5f5;"><h3 style="color:#991b1b;">Stripe non disponibile</h3><p class="muted">' + escH(m.error || 'Configura la chiave segreta Stripe in config/secret.php.') + '</p></div>';
                } else {
                    el.innerHTML = '<div class="card"><p class="muted">KPI principali aggiornati su incassato riuscito, commissioni, IVA e netto finale.</p></div>';
                }
            }
        })
        .catch(function () {
            ['dash-s-gross','dash-s-net','dash-s-fees','dash-s-refunds',
             'dash-s-bonif-n','dash-s-bonif-tot','dash-s-pending-tot','dash-s-saldo',
             'dash-partner-bonif-n','dash-partner-bonif-tot','dash-partner-pending-tot',
             'dash-split-base','dash-split-partner','dash-split-platform',
             'dash-rec-netto','dash-rec-paid','dash-rec-pending','dash-rec-saldo','dash-rec-diff'].forEach(function (id) {
                setTxt(id, '\u2014');
            });
            renderPayoutRows('dash-partner-payouts-body', []);
            renderPayoutRows('dash-platform-payouts-body', []);
            renderPendingRows('dash-partner-pending-body', []);
            renderPendingRows('dash-platform-pending-body', []);
            var el = document.getElementById('dash-stripe-section');
            if (el) el.innerHTML = '<div class="card"><p class="muted">Dati Stripe non disponibili.</p></div>';
        });

    if (toggleVat) {
        toggleVat.addEventListener('change', function () {
            applyKpis(latestData || {});
        });
    }
}());
</script>
<?php require __DIR__ . '/../../layouts/footer.php'; ?>

