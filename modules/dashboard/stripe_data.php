<?php
require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (strtolower(trim((string)($user['ruolo'] ?? ''))) !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

set_time_limit(60);
ini_set('default_socket_timeout', '8');

header('Content-Type: application/json; charset=utf-8');

$pdo    = require __DIR__ . '/../../config/db.php';
$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../connect/connect_service.php';

$mese = isset($_GET['mese']) ? (int)$_GET['mese'] : 0;
$anno = isset($_GET['anno']) ? (int)$_GET['anno'] : 0;
$mese = max(0, min(12, $mese));
$anno = max(0, $anno);

$ivaIn = isset($_GET['aliquota_iva']) ? str_replace(',', '.', (string)$_GET['aliquota_iva']) : '22';
$iva   = is_numeric($ivaIn) ? (float)$ivaIn : 22.0;
$iva   = max(0, min(100, $iva));

$forceRefresh = isset($_GET['refresh']) && (string)$_GET['refresh'] === '1';

// Cache su file per 5 minuti
$cacheDir  = __DIR__ . '/../../tmp';
$cacheFile = $cacheDir . '/dash_stripe_' . md5("{$mese}_{$anno}_{$iva}") . '.json';
$cacheTtl  = 300;

if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    echo file_get_contents($cacheFile);
    exit;
}

$stripeKey = '';
$secretPath = __DIR__ . '/../../config/secret.php';
if (file_exists($secretPath)) {
    $secret    = require $secretPath;
    $stripeKey = (string)($secret['stripe']['secret_key'] ?? '');
}
if ($stripeKey === '') {
    $stripeKey = (string)($config['stripe']['secret_key'] ?? '');
}

$metrics = [
    'connected' => false, 'error' => null,
    'gross' => 0, 'net' => 0, 'fees' => 0, 'refunds' => 0,
    'charges_total' => 0, 'charges_succeeded' => 0,
    'charges_failed' => 0, 'charges_pending' => 0,
    'charges_amount_succeeded' => 0, 'customers_new' => 0,
    'balance_available' => false,
];
$finance = [
    'available' => false, 'error' => null,
    'payouts_count' => 0, 'payouts_total' => 0,
    'balance_available' => 0, 'balance_pending' => 0,
];
$connectStats = [
    'accounts_total' => 0, 'accounts_active' => 0,
    'accounts_incomplete' => 0, 'balance_available' => 0, 'balance_pending' => 0,
];
$connectError = null;
$payoutSplit = [
    'available' => false,
    'rules' => [
        'partner_share' => 0.70,
        'platform_share' => 0.30,
        'platform_gets_all_vat' => true,
    ],
    'platform' => [
        'available' => false,
        'error' => null,
        'payouts_count' => 0,
        'payouts_total' => 0,
        'items' => [],
    ],
    'partner' => [
        'available' => false,
        'error' => null,
        'account_id' => null,
        'payouts_count' => 0,
        'payouts_total' => 0,
        'items' => [],
    ],
    'platform_pending' => [
        'available' => false,
        'error' => null,
        'account_id' => null,
        'payouts_count' => 0,
        'payouts_total' => 0,
        'items' => [],
    ],
    'partner_pending' => [
        'available' => false,
        'error' => null,
        'account_id' => null,
        'payouts_count' => 0,
        'payouts_total' => 0,
        'items' => [],
    ],
];

function _sd_matches(int $ts, int $month, int $year): bool
{
    if ($ts <= 0) return false;
    if ($year > 0  && (int)date('Y', $ts) !== $year)  return false;
    if ($month > 0 && (int)date('n', $ts) !== $month) return false;
    return true;
}

function _sd_collect_payouts(?array $createdFilter, int $month, int $year, ?string $stripeAccount = null, int $limit = 20): array
{
    $all = _sd_collect_payouts_all($createdFilter, $month, $year, $stripeAccount, $limit);
    return $all['paid'];
}

function _sd_collect_payouts_all(?array $createdFilter, int $month, int $year, ?string $stripeAccount = null, int $limit = 20): array
{
    $params = ['limit' => 100];
    if ($createdFilter) {
        $params['created'] = $createdFilter;
    }

    $opts = [];
    if ($stripeAccount !== null && $stripeAccount !== '') {
        $opts['stripe_account'] = $stripeAccount;
    }

    $template = [
        'available' => false,
        'error' => null,
        'account_id' => $stripeAccount,
        'payouts_count' => 0,
        'payouts_total' => 0,
        'items' => [],
    ];
    $paid = $template;
    $pending = $template;

    try {
        $iterable = !empty($opts)
            ? \Stripe\Payout::all($params, $opts)->autoPagingIterator()
            : \Stripe\Payout::all($params)->autoPagingIterator();

        foreach ($iterable as $payout) {
            if (!_sd_matches((int)($payout->created ?? 0), $month, $year)) {
                continue;
            }
            $status = (string)($payout->status ?? '');
            $amount = abs((int)($payout->amount ?? 0));
            $item = [
                'id' => (string)($payout->id ?? ''),
                'amount' => $amount,
                'currency' => strtoupper((string)($payout->currency ?? 'EUR')),
                'created' => (int)($payout->created ?? 0),
                'arrival_date' => (int)($payout->arrival_date ?? 0),
                'status' => $status,
            ];

            if ($status === 'paid') {
                $paid['payouts_count']++;
                $paid['payouts_total'] += $amount;
                if (count($paid['items']) < $limit) {
                    $paid['items'][] = $item;
                }
            } elseif ($status === 'pending' || $status === 'in_transit') {
                $pending['payouts_count']++;
                $pending['payouts_total'] += $amount;
                if (count($pending['items']) < $limit) {
                    $pending['items'][] = $item;
                }
            }
        }

        $paid['available'] = true;
        $pending['available'] = true;
    } catch (Throwable $e) {
        $paid['error'] = $e->getMessage();
        $pending['error'] = $e->getMessage();
    }

    return ['paid' => $paid, 'pending' => $pending];
}

if ($stripeKey !== '' && strpos($stripeKey, 'sk_') === 0) {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        $metrics['error'] = 'Stripe SDK non trovato.';
    } else {
        try {
            require_once $autoload;
            \Stripe\Stripe::setApiKey($stripeKey);

            $isAllYears    = $anno === 0;
            $createdFilter = null;
            if (!$isAllYears) {
                if ($mese > 0) {
                    $ps = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anno, $mese));
                    $pe = $ps->modify('last day of this month 23:59:59');
                } else {
                    $ps = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $anno));
                    $pe = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $anno));
                }
                $createdFilter = ['gte' => $ps->getTimestamp(), 'lte' => $pe->getTimestamp()];
            }

            // Charges
            $p = ['limit' => 100];
            if ($createdFilter) $p['created'] = $createdFilter;
            foreach (\Stripe\Charge::all($p)->autoPagingIterator() as $charge) {
                if (!_sd_matches((int)($charge->created ?? 0), $mese, $anno)) continue;
                $metrics['charges_total']++;
                $status = $charge->status ?? '';
                if ($status === 'succeeded') {
                    $metrics['charges_succeeded']++;
                    $cap = (int)($charge->amount_captured ?? 0) ?: (int)($charge->amount ?? 0);
                    $metrics['charges_amount_succeeded'] += $cap;
                } elseif ($status === 'failed') {
                    $metrics['charges_failed']++;
                } else {
                    $metrics['charges_pending']++;
                }
            }

            // Refunds
            $p = ['limit' => 100];
            if ($createdFilter) $p['created'] = $createdFilter;
            foreach (\Stripe\Refund::all($p)->autoPagingIterator() as $refund) {
                if (!_sd_matches((int)($refund->created ?? 0), $mese, $anno)) continue;
                $metrics['refunds'] += abs((int)($refund->amount ?? 0));
            }

            // Balance transactions
            $p = ['limit' => 100];
            if ($createdFilter) $p['created'] = $createdFilter;
            foreach (\Stripe\BalanceTransaction::all($p)->autoPagingIterator() as $bt) {
                if (!_sd_matches((int)($bt->created ?? 0), $mese, $anno)) continue;
                $type = (string)($bt->type ?? '');
                $rc   = (string)($bt->reporting_category ?? '');
                $isC  = $type === 'charge' || $type === 'payment' || $rc === 'charge';
                $isR  = $type === 'refund' || $type === 'payment_refund' || $rc === 'refund';
                if (!$isC && !$isR) continue;
                $metrics['balance_available'] = true;
                $amount = (int)($bt->amount ?? 0);
                if ($isC && $amount > 0) $metrics['gross'] += $amount;
                $metrics['fees'] += (int)($bt->fee ?? 0);
                $metrics['net']  += (int)($bt->net ?? 0);
            }

            // Customers
            $p = ['limit' => 100];
            if ($createdFilter) $p['created'] = $createdFilter;
            foreach (\Stripe\Customer::all($p)->autoPagingIterator() as $c) {
                if (!_sd_matches((int)($c->created ?? 0), $mese, $anno)) continue;
                $metrics['customers_new']++;
            }

            if (!$metrics['balance_available']) {
                $metrics['gross'] = $metrics['charges_amount_succeeded'];
                $metrics['net']   = $metrics['charges_amount_succeeded'] - $metrics['refunds'];
                $metrics['fees']  = 0;
            }
            $metrics['connected'] = true;

            // Balance + Payouts
            try {
                $bal = \Stripe\Balance::retrieve();
                $finance['balance_available'] = tdg_stripe_connect_sum_balance_for_currency($bal->available ?? [], 'eur')
                    ?: tdg_stripe_connect_sum_balance_all($bal->available ?? []);
                $finance['balance_pending'] = tdg_stripe_connect_sum_balance_for_currency($bal->pending ?? [], 'eur')
                    ?: tdg_stripe_connect_sum_balance_all($bal->pending ?? []);
                $p = ['limit' => 100];
                if ($createdFilter) $p['created'] = $createdFilter;
                foreach (\Stripe\Payout::all($p)->autoPagingIterator() as $payout) {
                    if (!_sd_matches((int)($payout->created ?? 0), $mese, $anno)) continue;
                    if ((string)($payout->status ?? '') !== 'paid') continue;
                    $finance['payouts_count']++;
                    $finance['payouts_total'] += abs((int)($payout->amount ?? 0));
                }
                $finance['available'] = true;
            } catch (Throwable $e) {
                $finance['error'] = $e->getMessage();
            }

            // Connect sync + aggiorna statistiche in DB locale
            try {
                tdg_stripe_connect_ensure_schema($pdo);
                tdg_stripe_connect_sync_accounts($pdo, 30);
                $agg = $pdo->query("SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN onboarding_completed = 1 THEN 1 ELSE 0 END), 0) AS active,
                    COALESCE(SUM(CASE WHEN onboarding_completed = 0 THEN 1 ELSE 0 END), 0) AS incomplete,
                    COALESCE(SUM(balance_available), 0) AS balance_available,
                    COALESCE(SUM(balance_pending), 0) AS balance_pending
                    FROM stripe_connected_accounts")->fetch(PDO::FETCH_ASSOC);
                $connectStats = [
                    'accounts_total'      => (int)($agg['total'] ?? 0),
                    'accounts_active'     => (int)($agg['active'] ?? 0),
                    'accounts_incomplete' => (int)($agg['incomplete'] ?? 0),
                    'balance_available'   => (int)($agg['balance_available'] ?? 0),
                    'balance_pending'     => (int)($agg['balance_pending'] ?? 0),
                ];

                $partnerAccountId = null;
                try {
                    $partnerLike = $pdo->quote('%' . strtolower((string)(getenv('STRIPE_PARTNER_MATCH') ?: 'partner')) . '%');
    $partnerStmt = $pdo->query("SELECT stripe_account_id
                        FROM stripe_connected_accounts
                        WHERE LOWER(COALESCE(business_name, '')) LIKE {$partnerLike}
                           OR LOWER(COALESCE(email, '')) LIKE {$partnerLike}
                        ORDER BY updated_at DESC
                        LIMIT 1");
                    $partnerRow = $partnerStmt ? $partnerStmt->fetch(PDO::FETCH_ASSOC) : null;
                    $partnerAccountId = trim((string)($partnerRow['stripe_account_id'] ?? ''));
                    if ($partnerAccountId === '') {
                        $partnerAccountId = null;
                    }
                } catch (Throwable $ignorePartnerAccount) {
                    $partnerAccountId = null;
                }

                $platformAll = _sd_collect_payouts_all($createdFilter, $mese, $anno, null, 25);
                $partnerAll = _sd_collect_payouts_all($createdFilter, $mese, $anno, $partnerAccountId, 25);
                $payoutSplit['platform'] = $platformAll['paid'];
                $payoutSplit['platform_pending'] = $platformAll['pending'];
                $payoutSplit['partner'] = $partnerAll['paid'];
                $payoutSplit['partner_pending'] = $partnerAll['pending'];
                $payoutSplit['available'] = (bool)($payoutSplit['platform']['available'] || $payoutSplit['partner']['available']);
            } catch (Throwable $e) {
                $connectError = $e->getMessage();
            }
        } catch (Throwable $e) {
            $metrics['error'] = $e->getMessage();
        }
    }
}

$result = [
    'metrics'       => $metrics,
    'finance'       => $finance,
    'payout_split'  => $payoutSplit,
    'connect_stats' => $connectStats,
    'connect_error' => $connectError,
    'cached_at'     => time(),
];

$json = json_encode($result, JSON_UNESCAPED_UNICODE);
if (is_dir($cacheDir)) {
    @file_put_contents($cacheFile, $json);
}
echo $json;
