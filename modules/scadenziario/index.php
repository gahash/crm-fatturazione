<?php
require_once __DIR__ . '/../../auth.php';
require_login();

$config = require __DIR__ . '/../../config/config.php';
$user = current_user();
require_once __DIR__ . '/../fatture/actions/fic_api.php';

function fic_calendar_to_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d', $ts);
}

function fic_calendar_extract_documents(array $response): array
{
    $json = $response['json'] ?? null;
    if (!is_array($json)) {
        return [];
    }

    $data = $json['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }

    if (isset($data['items_list']) && is_array($data['items_list'])) {
        return $data['items_list'];
    }

    $isList = array_keys($data) === range(0, count($data) - 1);
    if ($isList) {
        return $data;
    }

    if (!empty($data['id'])) {
        return [$data];
    }

    return [];
}

function fic_calendar_amount($value): float
{
    if (is_numeric($value)) {
        return round((float)$value, 2);
    }

    if (is_array($value)) {
        foreach (['gross', 'amount', 'net', 'value'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return round((float)$value[$key], 2);
            }
        }
    }

    return 0.0;
}

function fic_calendar_collect_doc_events(array $doc, string $monthStart, string $monthEnd): array
{
    $events = [];
    $docId = (string)($doc['id'] ?? '');
    if ($docId === '') {
        return [];
    }

    $number = (string)($doc['numeration'] ?? $doc['number'] ?? ('DOC-' . $docId));
    if (trim($number) === '') {
        $number = 'DOC-' . $docId;
    }
    $customer = trim((string)($doc['entity']['name'] ?? 'Cliente'));
    if ($customer === '') {
        $customer = 'Cliente';
    }

    $docStatus = strtolower(trim((string)($doc['status'] ?? '')));
    $docDate = fic_calendar_to_date((string)($doc['date'] ?? ''));
    $nextDueDate = fic_calendar_to_date((string)($doc['next_due_date'] ?? ''));
    $amountDueDoc = fic_calendar_amount($doc['amount_due'] ?? null);
    if ($amountDueDoc <= 0) {
        $amountDueDoc = fic_calendar_amount($doc['amount_due_discount'] ?? null);
    }
    $payments = $doc['payments_list'] ?? [];

    if (is_array($payments) && !empty($payments)) {
        foreach ($payments as $idx => $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $dueDate = fic_calendar_to_date((string)($payment['due_date'] ?? ''));
            if ($dueDate === '' || $dueDate < $monthStart || $dueDate > $monthEnd) {
                continue;
            }

            $status = strtolower(trim((string)($payment['status'] ?? '')));
            $paidDate = fic_calendar_to_date((string)($payment['paid_date'] ?? ''));
            if ($status === '') {
                if ($paidDate !== '') {
                    $status = 'paid';
                } elseif ($docStatus === 'paid') {
                    $status = 'paid';
                } else {
                    $status = 'not_paid';
                }
            }

            $amount = fic_calendar_amount($payment['amount'] ?? null);
            if ($amount <= 0) {
                $amount = fic_calendar_amount($payment['due_amount'] ?? null);
            }
            if ($amount <= 0) {
                $amount = fic_calendar_amount($doc['amount_due'] ?? null);
            }
            if ($amount <= 0) {
                $amount = fic_calendar_amount($doc['amount'] ?? null);
            }

            $events[] = [
                'id' => $docId . '-p' . (string)$idx,
                'date' => $dueDate,
                'number' => $number,
                'customer' => $customer,
                'status' => $status,
                'amount' => $amount,
                'doc_id' => $docId,
                'doc_status' => $docStatus,
                'doc_date' => $docDate,
                'next_due_date' => $nextDueDate,
                'payment_status' => (string)($payment['status'] ?? ''),
                'payment_method' => (string)($payment['payment_method']['name'] ?? $payment['payment_method'] ?? ''),
                'paid_date' => $paidDate,
            ];
        }

        return $events;
    }

    $dueDate = fic_calendar_to_date((string)($doc['due_date'] ?? ''));
    if ($dueDate === '') {
        $dueDate = $nextDueDate;
    }
    if ($dueDate === '' || $dueDate < $monthStart || $dueDate > $monthEnd) {
        return [];
    }

    $status = ($docStatus === 'paid' || $docStatus === 'reversed') ? 'paid' : 'not_paid';
    if ($status !== 'paid' && $amountDueDoc > 0 && $amountDueDoc < 0.01) {
        $status = 'paid';
    }
    $amount = fic_calendar_amount($doc['amount_due'] ?? null);
    if ($amount <= 0) {
        $amount = fic_calendar_amount($doc['amount'] ?? null);
    }
    if ($amount <= 0) {
        $amount = fic_calendar_amount($doc['amount_gross'] ?? null);
    }

    $events[] = [
        'id' => $docId . '-fallback',
        'date' => $dueDate,
        'number' => $number,
        'customer' => $customer,
        'status' => $status,
        'amount' => $amount,
        'doc_id' => $docId,
        'doc_status' => $docStatus,
        'doc_date' => $docDate,
        'next_due_date' => $nextDueDate,
        'payment_status' => '',
        'payment_method' => '',
        'paid_date' => '',
    ];

    return $events;
}

function fic_calendar_fetch_month_events(string $companyId, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
{
    $monthStartStr = $monthStart->format('Y-m-d');
    $monthEndStr = $monthEnd->format('Y-m-d');

    $events = [];
    $seen = [];
    $pagesRead = 0;
    $detailCalls = 0;
    $maxPages = 20;
    $maxDetailCalls = 80;
    $detailWindowStart = $monthStart->modify('-90 days')->format('Y-m-d');
    $detailWindowEnd = $monthEnd->modify('+90 days')->format('Y-m-d');

    // Filtra per data: documenti emessi nel range -90gg / +90gg dal periodo selezionato
    $ficFilterStart = $monthStart->modify('-90 days')->format('Y-m-d');
    $ficFilterEnd = $monthEnd->modify('+90 days')->format('Y-m-d');

    for ($page = 1; $page <= $maxPages; $page++) {
        $pagesRead++;
        $query = http_build_query([
            'type' => 'invoice',
            'page' => $page,
            'per_page' => 100,
            'sort' => '-date',
        ]);

        $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
        if (($response['status'] ?? 500) < 200 || ($response['status'] ?? 500) >= 300) {
            $body = (string)($response['body'] ?? '');
            throw new RuntimeException('Errore FIC in lista documenti: HTTP ' . (string)($response['status'] ?? 500) . ' ' . substr($body, 0, 180));
        }

        $documents = fic_calendar_extract_documents($response);
        if (empty($documents)) {
            break;
        }

        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $docEvents = fic_calendar_collect_doc_events($doc, $monthStartStr, $monthEndStr);
            if (empty($docEvents)) {
                $docId = (string)($doc['id'] ?? '');
                $docDate = fic_calendar_to_date((string)($doc['date'] ?? ''));
                $nextDueDate = fic_calendar_to_date((string)($doc['next_due_date'] ?? ''));
                $isNearByDocDate = ($docDate !== '' && $docDate >= $detailWindowStart && $docDate <= $detailWindowEnd);
                $isDueInWindow = ($nextDueDate !== '' && $nextDueDate >= $monthStartStr && $nextDueDate <= $monthEndStr);
                if ($docId !== '' && ($isNearByDocDate || $isDueInWindow) && $detailCalls < $maxDetailCalls) {
                    $detailCalls++;
                    try {
                        $detailResp = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
                        if (($detailResp['status'] ?? 500) >= 200 && ($detailResp['status'] ?? 500) < 300) {
                            $fullDocs = fic_calendar_extract_documents($detailResp);
                            if (!empty($fullDocs[0]) && is_array($fullDocs[0])) {
                                $docEvents = fic_calendar_collect_doc_events($fullDocs[0], $monthStartStr, $monthEndStr);
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            }

            foreach ($docEvents as $event) {
                $key = (string)($event['id'] ?? '') . '|' . (string)($event['date'] ?? '');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $events[] = $event;
            }
        }

        $pagination = $response['json']['pagination'] ?? [];
        $lastPage = isset($pagination['last_page']) ? (int)$pagination['last_page'] : 0;
        if ($lastPage > 0 && $page >= $lastPage) {
            break;
        }
    }

    usort($events, static function (array $a, array $b): int {
        $da = (string)($a['date'] ?? '');
        $db = (string)($b['date'] ?? '');
        if ($da === $db) {
            return strcmp((string)($a['number'] ?? ''), (string)($b['number'] ?? ''));
        }
        return strcmp($da, $db);
    });

    return [
        'events' => $events,
        'pages_read' => $pagesRead,
        'detail_calls' => $detailCalls,
    ];
}

$monthRaw = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) {
    $monthRaw = date('Y-m');
}
$view = trim((string)($_GET['view'] ?? 'month'));
if (!in_array($view, ['month', 'week'], true)) {
    $view = 'month';
}

$forceSync = isset($_GET['sync']) && $_GET['sync'] === '1';
$monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthRaw . '-01');
if (!$monthStart) {
    $monthStart = new DateTimeImmutable('first day of this month');
    $monthRaw = $monthStart->format('Y-m');
}
$monthEnd = $monthStart->modify('last day of this month');

$monthNames = [
    1 => 'Gennaio',
    2 => 'Febbraio',
    3 => 'Marzo',
    4 => 'Aprile',
    5 => 'Maggio',
    6 => 'Giugno',
    7 => 'Luglio',
    8 => 'Agosto',
    9 => 'Settembre',
    10 => 'Ottobre',
    11 => 'Novembre',
    12 => 'Dicembre',
];

$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');

$weekDateRaw = trim((string)($_GET['week_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekDateRaw)) {
    $weekDateRaw = $today;
}
$weekAnchor = DateTimeImmutable::createFromFormat('!Y-m-d', $weekDateRaw);
if (!$weekAnchor) {
    $weekAnchor = new DateTimeImmutable($today);
}
$weekStart = $weekAnchor->modify('-' . ((int)$weekAnchor->format('N') - 1) . ' days');
$weekEnd = $weekStart->modify('+6 days');
$prevWeekDate = $weekStart->modify('-7 days')->format('Y-m-d');
$nextWeekDate = $weekStart->modify('+7 days')->format('Y-m-d');

$activeStart = $view === 'week' ? $weekStart : $monthStart;
$activeEnd = $view === 'week' ? $weekEnd : $monthEnd;

$events = [];
$eventsByDate = [];
$loadError = null;
$syncInfo = [
    'source' => 'live',
    'cached_at' => null,
    'pages_read' => 0,
    'detail_calls' => 0,
];

try {
    $companyId = (string)fic_get_company_id();
    if ($companyId === '') {
        throw new RuntimeException('company_id Fatture in Cloud non configurato.');
    }

    $cacheKey = $view === 'week'
        ? 'week_' . $weekStart->format('Y_m_d')
        : 'month_' . str_replace('-', '_', $monthRaw);
    $cachePath = __DIR__ . '/../../tmp/fic_scadenziario_' . $cacheKey . '.json';
    $cacheTtl = 120;
    $cacheSchemaVersion = 3;

    $useCache = false;
    if (!$forceSync && is_file($cachePath)) {
        $mtime = (int)@filemtime($cachePath);
        if ($mtime > 0 && (time() - $mtime) <= $cacheTtl) {
            $raw = (string)@file_get_contents($cachePath);
            $decoded = json_decode($raw, true);
            $cachedSchema = (int)($decoded['schema_version'] ?? 1);
            if (is_array($decoded) && isset($decoded['events']) && is_array($decoded['events']) && $cachedSchema >= $cacheSchemaVersion) {
                $events = $decoded['events'];
                $syncInfo['source'] = 'cache';
                $syncInfo['cached_at'] = date('Y-m-d H:i:s', $mtime);
                $syncInfo['pages_read'] = (int)($decoded['pages_read'] ?? 0);
                $syncInfo['detail_calls'] = (int)($decoded['detail_calls'] ?? 0);
                $useCache = true;
            }
        }
    }

    if (!$useCache) {
        $fetched = fic_calendar_fetch_month_events($companyId, $activeStart, $activeEnd);
        $events = $fetched['events'] ?? [];
        $syncInfo['source'] = 'live';
        $syncInfo['cached_at'] = date('Y-m-d H:i:s');
        $syncInfo['pages_read'] = (int)($fetched['pages_read'] ?? 0);
        $syncInfo['detail_calls'] = (int)($fetched['detail_calls'] ?? 0);

        $payload = json_encode([
            'schema_version' => $cacheSchemaVersion,
            'events' => $events,
            'pages_read' => $syncInfo['pages_read'],
            'detail_calls' => $syncInfo['detail_calls'],
        ], JSON_UNESCAPED_UNICODE);
        if (is_string($payload)) {
            @file_put_contents($cachePath, $payload);
        }
    } elseif (empty($events)) {
        $fetched = fic_calendar_fetch_month_events($companyId, $activeStart, $activeEnd);
        $events = $fetched['events'] ?? [];
        $syncInfo['source'] = 'live';
        $syncInfo['cached_at'] = date('Y-m-d H:i:s');
        $syncInfo['pages_read'] = (int)($fetched['pages_read'] ?? 0);
        $syncInfo['detail_calls'] = (int)($fetched['detail_calls'] ?? 0);
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// ---------- Integra fatture locali dal DB (non ancora su FIC) ----------
try {
    $pdo = require __DIR__ . '/../../config/db.php';
    $localStart = $activeStart->format('Y-m-d');
    $localEnd = $activeEnd->format('Y-m-d');

    $localStmt = $pdo->prepare("
        SELECT f.id, f.numero, f.data_emissione, f.stato, f.totale, f.mail_status,
               f.stripe_payment_intent_id, f.fic_document_id,
               c.nome AS cliente_nome, c.azienda AS cliente_azienda
        FROM fatture f
        LEFT JOIN clienti c ON c.id = f.cliente_id
        WHERE f.data_emissione >= :start AND f.data_emissione <= :end
        ORDER BY f.data_emissione ASC
    ");
    $localStmt->execute([':start' => $localStart, ':end' => $localEnd]);
    $localFatture = $localStmt->fetchAll(PDO::FETCH_ASSOC);

    // Crea un set di numeri FIC già presenti per evitare duplicati
    $ficDocIds = [];
    foreach ($events as $ev) {
        $ficDocIds[(string)($ev['doc_id'] ?? '')] = true;
    }

    foreach ($localFatture as $lf) {
        // Salta se già presente da FIC
        $ficId = (string)($lf['fic_document_id'] ?? '');
        if ($ficId !== '' && isset($ficDocIds[$ficId])) {
            continue;
        }

        $date = (string)($lf['data_emissione'] ?? '');
        if ($date === '' || $date < $localStart || $date > $localEnd) {
            continue;
        }

        $statoLocale = strtolower(trim($lf['stato'] ?? ''));
        $status = ($statoLocale === 'pagata') ? 'paid' : 'not_paid';
        $customer = trim($lf['cliente_azienda'] ?? '') !== ''
            ? trim($lf['cliente_azienda'])
            : (trim($lf['cliente_nome'] ?? '') !== '' ? trim($lf['cliente_nome']) : 'Cliente');
        $numero = trim($lf['numero'] ?? '') !== '' ? trim($lf['numero']) : ('DB-' . $lf['id']);

        $events[] = [
            'id' => 'local-' . $lf['id'],
            'date' => $date,
            'number' => $numero,
            'customer' => $customer,
            'status' => $status,
            'amount' => round((float)($lf['totale'] ?? 0), 2),
            'doc_id' => 'local-' . $lf['id'],
            'doc_status' => $statoLocale,
            'doc_date' => $date,
            'next_due_date' => '',
            'payment_status' => $status,
            'payment_method' => !empty($lf['stripe_payment_intent_id']) ? 'Stripe' : '',
            'paid_date' => $statoLocale === 'pagata' ? $date : '',
        ];
    }

    // Riordina per data
    usort($events, static function (array $a, array $b): int {
        $da = (string)($a['date'] ?? '');
        $db = (string)($b['date'] ?? '');
        return $da === $db ? strcmp($a['number'] ?? '', $b['number'] ?? '') : strcmp($da, $db);
    });
} catch (Throwable $e) {
    // Non bloccare se il DB non è raggiungibile
    if ($loadError === null) {
        $loadError = 'Avviso: fatture locali non caricate (' . substr($e->getMessage(), 0, 100) . ')';
    }
}

$stats = [
    'total' => 0,
    'overdue' => 0,
    'today' => 0,
    'upcoming' => 0,
    'paid' => 0,
    'amount_total' => 0.0,
];

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $date = (string)($event['date'] ?? '');
    if ($date === '') {
        continue;
    }

    $status = strtolower((string)($event['status'] ?? 'not_paid'));
    $amount = (float)($event['amount'] ?? 0);
    $stats['total']++;
    $stats['amount_total'] += $amount;

    $isPaid = in_array($status, ['paid', 'reversed', 'closed'], true);
    if ($isPaid) {
        $stats['paid']++;
    } elseif ($date < $today) {
        $stats['overdue']++;
    } elseif ($date === $today) {
        $stats['today']++;
    } else {
        $stats['upcoming']++;
    }

    if (!isset($eventsByDate[$date])) {
        $eventsByDate[$date] = [];
    }
    $eventsByDate[$date][] = $event;
}

$firstWeekday = (int)$monthStart->format('N');
$daysInMonth = (int)$monthStart->format('t');
$cells = (int)ceil(($firstWeekday - 1 + $daysInMonth) / 7) * 7;
$calendarCells = [];
for ($i = 0; $i < $cells; $i++) {
    $dayNumber = $i - ($firstWeekday - 2);
    if ($dayNumber < 1 || $dayNumber > $daysInMonth) {
        $calendarCells[] = [
            'day' => null,
            'date' => '',
            'events' => [],
        ];
        continue;
    }

    $date = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $dayNumber)->format('Y-m-d');
    $calendarCells[] = [
        'day' => $dayNumber,
        'date' => $date,
        'events' => $eventsByDate[$date] ?? [],
    ];
}

$weekCells = [];
for ($i = 0; $i < 7; $i++) {
    $current = $weekStart->modify('+' . $i . ' days');
    $date = $current->format('Y-m-d');
    $weekCells[] = [
        'day' => (int)$current->format('j'),
        'date' => $date,
        'weekday' => (int)$current->format('N'),
        'month' => (int)$current->format('n'),
        'events' => $eventsByDate[$date] ?? [],
    ];
}

require __DIR__ . '/../../layouts/header.php';
?>
<style>
.scad-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.scad-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.view-switch{display:inline-flex;background:#e2e8f0;border-radius:8px;padding:3px;gap:3px}
.view-switch a{display:inline-block;padding:6px 10px;border-radius:6px;text-decoration:none;color:#334155;font-size:12px;font-weight:700}
.view-switch a.active{background:#0ea5e9;color:#fff}
.scad-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.scad-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px}
.scad-stat .k{font-size:11px;color:#64748b;text-transform:uppercase}
.scad-stat .v{font-size:20px;font-weight:700;color:#0f172a}
.calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}
.week-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
.week-day{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:8px;min-height:180px}
.week-day .head{font-size:12px;font-weight:700;color:#334155;margin-bottom:6px}
.week-day.today{outline:2px solid #0ea5e9}
.calendar .head{background:#f8fafc;padding:10px;font-size:12px;font-weight:700;color:#334155;border-right:1px solid #e5e7eb}
.calendar .head:last-child{border-right:0}
.calendar .cell{min-height:130px;padding:8px;border-right:1px solid #e5e7eb;border-top:1px solid #e5e7eb;background:#fff}
.calendar .cell:nth-child(7n){border-right:0}
.calendar .cell.empty{background:#f8fafc}
.calendar .day{font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
.calendar .day.today{display:inline-block;background:#0ea5e9;color:#fff;border-radius:999px;padding:2px 8px}
.scad-item{display:block;width:100%;text-align:left;cursor:pointer;appearance:none;background:#fff;border-radius:6px;padding:6px 7px;margin-bottom:6px;font-size:11px;line-height:1.3;border:1px solid transparent}
.scad-item:focus{outline:2px solid #0ea5e9;outline-offset:1px}
.scad-item:last-child{margin-bottom:0}
.scad-item .n{display:block;font-weight:700}
.scad-item .c{display:block;color:#334155}
.scad-item .a{display:block;font-weight:700;margin-top:2px}
.scad-item.pending{background:#e0f2fe;border-color:#7dd3fc;color:#0c4a6e}
.scad-item.overdue{background:#fee2e2;border-color:#fca5a5;color:#991b1b}
.scad-item.today{background:#fef3c7;border-color:#fcd34d;color:#92400e}
.scad-item.paid{background:#dcfce7;border-color:#86efac;color:#166534}
.scad-legend{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#475569}
.chip{display:inline-flex;align-items:center;gap:6px}
.dot{width:10px;height:10px;border-radius:999px;display:inline-block}
.dot.pending{background:#38bdf8}
.dot.overdue{background:#ef4444}
.dot.today{background:#f59e0b}
.dot.paid{background:#22c55e}
.scad-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.scad-detail-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px}
.scad-detail-item .k{font-size:11px;color:#64748b;text-transform:uppercase}
.scad-detail-item .v{font-size:14px;font-weight:700;color:#0f172a;margin-top:4px;word-break:break-word}
@media (max-width: 900px){
  .calendar{grid-template-columns:repeat(1,minmax(0,1fr))}
  .calendar .head{display:none}
  .calendar .cell{border-right:0;min-height:auto}
  .week-calendar{grid-template-columns:repeat(1,minmax(0,1fr))}
  .week-day{min-height:auto}
}
</style>

<div class="wrap">
    <div class="card">
        <div class="scad-header">
            <div>
                <h3 style="margin:0;">Scadenziario Fatture in Cloud</h3>
                <p class="muted" style="margin:6px 0 0 0;">Calendario scadenze pagamenti fatture da FIC con vista mensile e settimanale.</p>
            </div>
            <div class="scad-actions">
                <span class="view-switch">
                    <a href="?view=month&month=<?php echo urlencode($monthRaw); ?>" class="<?php echo $view === 'month' ? 'active' : ''; ?>">Mese</a>
                    <a href="?view=week&week_date=<?php echo urlencode($weekAnchor->format('Y-m-d')); ?>" class="<?php echo $view === 'week' ? 'active' : ''; ?>">Settimana</a>
                </span>
                <?php if ($view === 'week'): ?>
                    <a class="btn secondary" href="?view=week&week_date=<?php echo urlencode($prevWeekDate); ?>">&lt; Settimana precedente</a>
                    <a class="btn secondary" href="?view=week&week_date=<?php echo urlencode($nextWeekDate); ?>">Settimana successiva &gt;</a>
                <?php else: ?>
                    <a class="btn secondary" href="?view=month&month=<?php echo urlencode($prevMonth); ?>">&lt; Mese precedente</a>
                    <a class="btn secondary" href="?view=month&month=<?php echo urlencode($nextMonth); ?>">Mese successivo &gt;</a>
                <?php endif; ?>
                <a class="btn" href="?view=<?php echo urlencode($view); ?>&month=<?php echo urlencode($monthRaw); ?>&week_date=<?php echo urlencode($weekAnchor->format('Y-m-d')); ?>&sync=1">Sincronizza ora</a>
            </div>
        </div>
        <div style="margin-top:10px;">
            <?php if ($view === 'week'): ?>
                <strong>Settimana <?php echo htmlspecialchars($weekStart->format('d/m/Y')); ?> - <?php echo htmlspecialchars($weekEnd->format('d/m/Y')); ?></strong>
            <?php else: ?>
                <strong><?php echo htmlspecialchars($monthNames[(int)$monthStart->format('n')] ?? $monthRaw); ?> <?php echo htmlspecialchars($monthStart->format('Y')); ?></strong>
            <?php endif; ?>
            <span class="muted" style="margin-left:10px;">
                Fonte: <?php echo htmlspecialchars($syncInfo['source']); ?><?php echo $syncInfo['cached_at'] ? ' - aggiornato ' . htmlspecialchars((string)$syncInfo['cached_at']) : ''; ?>
                - pagine lette: <?php echo (int)$syncInfo['pages_read']; ?>
                - dettagli: <?php echo (int)$syncInfo['detail_calls']; ?>
            </span>
        </div>
        <?php if ($loadError !== null): ?>
            <div class="alert error" style="margin-top:12px;">Errore sincronizzazione scadenziario: <?php echo htmlspecialchars($loadError); ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="scad-stats">
            <div class="scad-stat"><div class="k">Scadenze Totali</div><div class="v"><?php echo number_format((int)$stats['total'], 0, ',', '.'); ?></div></div>
            <div class="scad-stat"><div class="k">Importo Totale</div><div class="v"><?php echo number_format((float)$stats['amount_total'], 2, ',', '.'); ?> EUR</div></div>
            <div class="scad-stat"><div class="k">In Ritardo</div><div class="v" style="color:#b91c1c;"><?php echo number_format((int)$stats['overdue'], 0, ',', '.'); ?></div></div>
            <div class="scad-stat"><div class="k">Oggi</div><div class="v" style="color:#a16207;"><?php echo number_format((int)$stats['today'], 0, ',', '.'); ?></div></div>
            <div class="scad-stat"><div class="k">Future</div><div class="v" style="color:#0c4a6e;"><?php echo number_format((int)$stats['upcoming'], 0, ',', '.'); ?></div></div>
            <div class="scad-stat"><div class="k">Pagate</div><div class="v" style="color:#166534;"><?php echo number_format((int)$stats['paid'], 0, ',', '.'); ?></div></div>
        </div>
        <div class="scad-legend" style="margin-top:12px;">
            <span class="chip"><span class="dot pending"></span>Da pagare</span>
            <span class="chip"><span class="dot overdue"></span>In ritardo</span>
            <span class="chip"><span class="dot today"></span>Scade oggi</span>
            <span class="chip"><span class="dot paid"></span>Pagata</span>
        </div>
    </div>

    <div class="card">
        <?php if ($view === 'week'): ?>
            <?php if (empty($events)): ?>
                <p class="muted" style="margin:0 0 10px 0;">Nessuna scadenza disponibile per questa settimana.</p>
            <?php endif; ?>
                <div class="week-calendar">
                    <?php foreach ($weekCells as $cell): ?>
                        <div class="week-day <?php echo $cell['date'] === $today ? 'today' : ''; ?>">
                            <div class="head">
                                <?php
                                    $weekNames = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Gio', 5 => 'Ven', 6 => 'Sab', 7 => 'Dom'];
                                    echo htmlspecialchars($weekNames[(int)$cell['weekday']] ?? '');
                                ?>
                                - <?php echo htmlspecialchars(substr((string)$cell['date'], 8, 2) . '/' . substr((string)$cell['date'], 5, 2)); ?>
                            </div>
                            <?php if (empty($cell['events'])): ?>
                                <p class="muted" style="margin:4px 0 0 0;font-size:12px;">Nessuna scadenza</p>
                            <?php else: ?>
                                <?php foreach ($cell['events'] as $event): ?>
                                    <?php
                                        $date = (string)($event['date'] ?? '');
                                        $status = strtolower((string)($event['status'] ?? 'not_paid'));
                                        $isPaid = in_array($status, ['paid', 'reversed', 'closed'], true);
                                        $class = 'pending';
                                        if ($isPaid) {
                                            $class = 'paid';
                                        } elseif ($date < $today) {
                                            $class = 'overdue';
                                        } elseif ($date === $today) {
                                            $class = 'today';
                                        }
                                    ?>
                                    <button
                                        type="button"
                                        class="scad-item js-scad-detail <?php echo htmlspecialchars($class); ?>"
                                        data-number="<?php echo htmlspecialchars((string)($event['number'] ?? '')); ?>"
                                        data-customer="<?php echo htmlspecialchars((string)($event['customer'] ?? 'Cliente')); ?>"
                                        data-date="<?php echo htmlspecialchars((string)($event['date'] ?? '')); ?>"
                                        data-status="<?php echo htmlspecialchars((string)($event['status'] ?? '')); ?>"
                                        data-amount="<?php echo htmlspecialchars(number_format((float)($event['amount'] ?? 0), 2, ',', '.')); ?>"
                                        data-doc-id="<?php echo htmlspecialchars((string)($event['doc_id'] ?? '')); ?>"
                                        data-doc-status="<?php echo htmlspecialchars((string)($event['doc_status'] ?? '')); ?>"
                                        data-doc-date="<?php echo htmlspecialchars((string)($event['doc_date'] ?? '')); ?>"
                                        data-payment-status="<?php echo htmlspecialchars((string)($event['payment_status'] ?? '')); ?>"
                                        data-payment-method="<?php echo htmlspecialchars((string)($event['payment_method'] ?? '')); ?>"
                                        data-paid-date="<?php echo htmlspecialchars((string)($event['paid_date'] ?? '')); ?>"
                                    >
                                        <span class="n"><?php echo htmlspecialchars((string)($event['number'] ?? '')); ?></span>
                                        <span class="c"><?php echo htmlspecialchars((string)($event['customer'] ?? 'Cliente')); ?></span>
                                        <span class="a"><?php echo number_format((float)($event['amount'] ?? 0), 2, ',', '.'); ?> EUR</span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="calendar">
                    <?php foreach (['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'] as $name): ?>
                        <div class="head"><?php echo htmlspecialchars($name); ?></div>
                    <?php endforeach; ?>

                    <?php foreach ($calendarCells as $cell): ?>
                        <?php if ($cell['day'] === null): ?>
                            <div class="cell empty"></div>
                        <?php else: ?>
                            <div class="cell">
                                <div class="day <?php echo $cell['date'] === $today ? 'today' : ''; ?>"><?php echo (int)$cell['day']; ?></div>
                                <?php foreach ($cell['events'] as $event): ?>
                                    <?php
                                        $date = (string)($event['date'] ?? '');
                                        $status = strtolower((string)($event['status'] ?? 'not_paid'));
                                        $isPaid = in_array($status, ['paid', 'reversed', 'closed'], true);
                                        $class = 'pending';
                                        if ($isPaid) {
                                            $class = 'paid';
                                        } elseif ($date < $today) {
                                            $class = 'overdue';
                                        } elseif ($date === $today) {
                                            $class = 'today';
                                        }
                                    ?>
                                    <button
                                        type="button"
                                        class="scad-item js-scad-detail <?php echo htmlspecialchars($class); ?>"
                                        data-number="<?php echo htmlspecialchars((string)($event['number'] ?? '')); ?>"
                                        data-customer="<?php echo htmlspecialchars((string)($event['customer'] ?? 'Cliente')); ?>"
                                        data-date="<?php echo htmlspecialchars((string)($event['date'] ?? '')); ?>"
                                        data-status="<?php echo htmlspecialchars((string)($event['status'] ?? '')); ?>"
                                        data-amount="<?php echo htmlspecialchars(number_format((float)($event['amount'] ?? 0), 2, ',', '.')); ?>"
                                        data-doc-id="<?php echo htmlspecialchars((string)($event['doc_id'] ?? '')); ?>"
                                        data-doc-status="<?php echo htmlspecialchars((string)($event['doc_status'] ?? '')); ?>"
                                        data-doc-date="<?php echo htmlspecialchars((string)($event['doc_date'] ?? '')); ?>"
                                        data-payment-status="<?php echo htmlspecialchars((string)($event['payment_status'] ?? '')); ?>"
                                        data-payment-method="<?php echo htmlspecialchars((string)($event['payment_method'] ?? '')); ?>"
                                        data-paid-date="<?php echo htmlspecialchars((string)($event['paid_date'] ?? '')); ?>"
                                    >
                                        <span class="n"><?php echo htmlspecialchars((string)($event['number'] ?? '')); ?></span>
                                        <span class="c"><?php echo htmlspecialchars((string)($event['customer'] ?? 'Cliente')); ?></span>
                                        <span class="a"><?php echo number_format((float)($event['amount'] ?? 0), 2, ',', '.'); ?> EUR</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
    </div>

    <div class="card" id="scadDetailCard" style="display:none;">
        <h3 style="margin:0 0 10px 0;">Dettaglio scadenza</h3>
        <div class="scad-detail-grid">
            <div class="scad-detail-item"><div class="k">Numero</div><div class="v" id="scadDetailNumber">-</div></div>
            <div class="scad-detail-item"><div class="k">Cliente</div><div class="v" id="scadDetailCustomer">-</div></div>
            <div class="scad-detail-item"><div class="k">Data Scadenza</div><div class="v" id="scadDetailDate">-</div></div>
            <div class="scad-detail-item"><div class="k">Importo</div><div class="v" id="scadDetailAmount">-</div></div>
            <div class="scad-detail-item"><div class="k">Stato Pagamento</div><div class="v" id="scadDetailStatus">-</div></div>
            <div class="scad-detail-item"><div class="k">Metodo Pagamento</div><div class="v" id="scadDetailMethod">-</div></div>
            <div class="scad-detail-item"><div class="k">Data Pagamento</div><div class="v" id="scadDetailPaidDate">-</div></div>
            <div class="scad-detail-item"><div class="k">Documento FIC</div><div class="v" id="scadDetailDoc">-</div></div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.js-scad-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var card = document.getElementById('scadDetailCard');
        if (!card) return;

        document.getElementById('scadDetailNumber').textContent = this.dataset.number || '-';
        document.getElementById('scadDetailCustomer').textContent = this.dataset.customer || '-';
        document.getElementById('scadDetailDate').textContent = this.dataset.date || '-';
        document.getElementById('scadDetailAmount').textContent = (this.dataset.amount || '0,00') + ' EUR';

        var status = (this.dataset.status || '').trim();
        var paymentStatus = (this.dataset.paymentStatus || '').trim();
        document.getElementById('scadDetailStatus').textContent = paymentStatus !== '' ? paymentStatus : (status !== '' ? status : '-');
        document.getElementById('scadDetailMethod').textContent = (this.dataset.paymentMethod || '').trim() || '-';
        document.getElementById('scadDetailPaidDate').textContent = (this.dataset.paidDate || '').trim() || '-';

        var docLabel = 'ID ' + ((this.dataset.docId || '').trim() || '-');
        var docStatus = (this.dataset.docStatus || '').trim();
        var docDate = (this.dataset.docDate || '').trim();
        if (docStatus !== '') {
            docLabel += ' - ' + docStatus;
        }
        if (docDate !== '') {
            docLabel += ' (' + docDate + ')';
        }
        document.getElementById('scadDetailDoc').textContent = docLabel;

        card.style.display = 'block';
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
