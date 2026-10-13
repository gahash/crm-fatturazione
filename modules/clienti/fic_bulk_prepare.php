<?php
require __DIR__ . '/../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../fatture/actions/fic_api.php';

$scope = $_POST['scope'] ?? 'selected';
$dataEmissione = $_POST['data_emissione'] ?? '';
$dataScadenza = $_POST['data_scadenza'] ?? '';
$descrizione = trim((string)($_POST['descrizione'] ?? 'Fattura servizi'));
$search = trim((string)($_POST['search'] ?? ''));

if ($dataEmissione === '' || $dataScadenza === '' || $descrizione === '') {
    header('Location: clienti.php?fic_bulk_error=missing_fields');
    exit;
}

$cfg = fic_config();
$companyId = fic_get_company_id();
if ($companyId === '') {
    header('Location: clienti.php?fic_bulk_error=missing_company_id');
    exit;
}

if (fic_get_access_token() === '') {
    header('Location: clienti.php?fic_bulk_error=missing_token');
    exit;
}


function normalize_cliente_nome(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/[^a-z0-9\s\-\._]/', '', $name);
    return trim($name);
}

function fic_bulk_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function fic_bulk_parse_address_parts($value): array
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
    $tokens = array_values(array_filter(array_map(static function ($t) {
        return trim((string)$t);
    }, explode(',', (string)$normalized)), static function ($t) {
        return $t !== '';
    }));

    $street = '';
    $postalCode = '';
    $city = '';
    $province = '';

    if ($tokens) {
        $street = rtrim((string)$tokens[0], " ,.-");
        for ($i = 1; $i < count($tokens); $i++) {
            $t = trim((string)$tokens[$i]);
            if ($t === '') {
                continue;
            }

            if ($postalCode === '' && preg_match('/^\d{5}$/', $t)) {
                $postalCode = $t;
                continue;
            }

            if ($province === '' && preg_match('/^[A-Za-z]{2}$/', $t)) {
                $province = strtoupper($t);
                continue;
            }

            if ($city === '' && preg_match('/^(.+?)\s+([A-Za-z]{2})$/', $t, $m)) {
                $city = trim((string)$m[1]);
                if ($province === '') {
                    $province = strtoupper((string)$m[2]);
                }
                continue;
            }

            if ($city === '') {
                $city = $t;
            }
        }
    } else {
        $street = rtrim($raw, " ,.-");
    }

    if ($postalCode === '' && preg_match('/\b(\d{5})\b/', $raw, $m)) {
        $postalCode = (string)$m[1];
    }
    if ($province === '' && preg_match('/\b([A-Za-z]{2})\b\s*$/', trim($raw), $m)) {
        $province = strtoupper((string)$m[1]);
    }
    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\b\s*,?\s*([^,]+?)(?:\s*,\s*[A-Za-z]{2})?(?:\s*,|$)/i', $raw, $m)) {
        $city = trim((string)$m[1]);
    }

    return [
        'address_street' => trim($street),
        'address_postal_code' => preg_match('/^\d{5}$/', $postalCode) ? $postalCode : '',
        'address_city' => trim($city),
        'address_province' => preg_match('/^[A-Z]{2}$/', $province) ? $province : '',
    ];
}

function fic_bulk_is_payments_mismatch_error(array $response): bool
{
    if ((int)($response['status'] ?? 0) !== 422) {
        return false;
    }

    $message = (string)($response['json']['error']['message'] ?? '');
    $messageLower = fic_bulk_lower($message);
    if (strpos($messageLower, 'totale dei pagamenti non corrisponde al totale da pagare') !== false) {
        return true;
    }

    $amountDue = (float)($response['json']['extra']['totals']['amount_due'] ?? 0);
    $paymentsSum = (float)($response['json']['extra']['totals']['payments_sum'] ?? 0);
    return $amountDue > 0 && $paymentsSum >= 0 && $paymentsSum < $amountDue;
}

function fic_bulk_extract_amount_due(array $response): float
{
    $amountDue = (float)($response['json']['extra']['totals']['amount_due'] ?? 0);
    return $amountDue > 0 ? round($amountDue, 2) : 0.0;
}

function fic_bulk_normalize_due_date(string $dateEmissione): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateEmissione, $m)) {
        return $m[0];
    }
    return date('Y-m-d');
}

function fic_bulk_find_recent_invoice(PDO $pdo, int $clienteId, float $expectedAmount, string $dataEmissione): ?array
{
    // Controlla se una fattura recente per lo stesso cliente esiste già,
    // soprattutto se già sincronizzata su FIC
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, numero, totale, fic_document_id, fic_status, data_emissione
            FROM fatture
            WHERE cliente_id = :cliente_id
              AND data_emissione >= DATE_SUB(:data_emissione, INTERVAL 60 DAY)
              AND data_emissione <= DATE_ADD(:data_emissione, INTERVAL 60 DAY)
            ORDER BY data_emissione DESC
            LIMIT 5
        ");
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':data_emissione' => $dataEmissione,
        ]);
        
        $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($fatture as $f) {
            // Se l'importo combacia e la fattura è già su FIC, non ricreare
            if (abs((float)$f['totale'] - $expectedAmount) < 0.01 && !empty($f['fic_document_id'])) {
                return $f;
            }
        }
        
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_clienti_by_scope(PDO $pdo, string $scope, string $search, array $ids): array
{
    if ($scope === 'selected') {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM clienti WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    $params = [];
    $where = '';
    if ($scope === 'filtered' && $search !== '') {
        $where = " WHERE nome LIKE :q OR email LIKE :q OR azienda LIKE :q OR telefono LIKE :q OR indirizzo LIKE :q OR piva LIKE :q OR codice_sdi LIKE :q OR pec LIKE :q";
        $params[':q'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare("SELECT * FROM clienti" . $where . " ORDER BY creato_il DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}


$idsRaw = trim((string)($_POST['client_ids'] ?? ''));
$ids = $idsRaw !== '' ? array_values(array_filter(array_map('intval', explode(',', $idsRaw)))) : [];
$clienti = get_clienti_by_scope($pdo, $scope, $search, $ids);

if (!$clienti) {
    header('Location: clienti.php?fic_bulk_error=no_clients');
    exit;
}

$stripeByClientId = [];
$stripeByEmail = [];
$stripeByName = [];

try {
    $stmtStripe = $pdo->prepare("SELECT cliente_id, stripe_email, stripe_name, last_charge_amount, total_amount_charged FROM stripe_sync");
    $stmtStripe->execute();
    $stripeRows = $stmtStripe->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stripeRows as $row) {
        if (!empty($row['cliente_id'])) {
            $stripeByClientId[(int)$row['cliente_id']] = $row;
        }
        $emailKey = strtolower(trim((string)($row['stripe_email'] ?? '')));
        if ($emailKey !== '') {
            $stripeByEmail[$emailKey] = $row;
        }
        $nameKey = normalize_cliente_nome((string)($row['stripe_name'] ?? ''));
        if ($nameKey !== '') {
            $stripeByName[$nameKey] = $row;
        }
    }
} catch (Throwable $e) {
    header('Location: clienti.php?fic_bulk_error=stripe_sync');
    exit;
}

$vatId = $cfg['vat_type_id'] ?? 0;
$ok = 0;
$skipped = 0;

foreach ($clienti as $cliente) {
    $piva = trim((string)($cliente['piva'] ?? ''));
    $azienda = trim((string)($cliente['azienda'] ?? ''));
    if ($piva === '' || $azienda === '') {
        $skipped++;
        continue;
    }

    $stripeRow = $stripeByClientId[(int)$cliente['id']] ?? null;
    if (!$stripeRow) {
        $emailKey = strtolower(trim((string)($cliente['email'] ?? '')));
        $stripeRow = $emailKey !== '' ? ($stripeByEmail[$emailKey] ?? null) : null;
    }
    if (!$stripeRow) {
        $aziendaKey = normalize_cliente_nome((string)($cliente['azienda'] ?? ''));
        $stripeRow = $aziendaKey !== '' ? ($stripeByName[$aziendaKey] ?? null) : null;
    }

    if (!$stripeRow) {
        $skipped++;
        continue;
    }

    $amountCents = (int)($stripeRow['last_charge_amount'] ?? 0);
    if ($amountCents <= 0) {
        $amountCents = (int)($stripeRow['total_amount_charged'] ?? 0);
    }
    if ($amountCents <= 0) {
        $skipped++;
        continue;
    }

    $totale = $amountCents / 100;

    // Verifica se una fattura recente per lo stesso cliente esiste già e sincronizzata su FIC
    $existingFattura = fic_bulk_find_recent_invoice($pdo, (int)$cliente['id'], $totale, $dataEmissione);
    if ($existingFattura !== null) {
        // Fattura già esiste e sincronizzata su FIC: skippa senza ricreazione
        $skipped++;
        continue;
    }

    $numero = 'AUTO-' . date('Ymd') . '-' . $cliente['id'] . '-' . substr((string)time(), -4);
    $addressParts = fic_bulk_parse_address_parts((string)($cliente['indirizzo'] ?? ''));

    $stmt = $pdo->prepare("INSERT INTO fatture (cliente_id, numero, document_type, data_emissione, data_scadenza, stato, totale, note) VALUES (:cliente_id, :numero, 'invoice', :data_emissione, :data_scadenza, 'bozza', :totale, :note)");
    $stmt->execute([
        ':cliente_id' => (int)$cliente['id'],
        ':numero' => $numero,
        ':data_emissione' => $dataEmissione,
        ':data_scadenza' => $dataScadenza,
        ':totale' => $totale,
        ':note' => 'Creata da preparazione FIC',
    ]);
    $fatturaId = (int)$pdo->lastInsertId();

    $data = [
        'type' => $cfg['document_type'] ?? 'invoice',
        'entity' => [
            'name' => $azienda,
            'vat_number' => $piva ?: null,
            'address_street' => $addressParts['address_street'] !== '' ? $addressParts['address_street'] : ($cliente['indirizzo'] ?: null),
            'address_postal_code' => $addressParts['address_postal_code'] !== '' ? $addressParts['address_postal_code'] : null,
            'address_city' => $addressParts['address_city'] !== '' ? $addressParts['address_city'] : null,
            'address_province' => $addressParts['address_province'] !== '' ? $addressParts['address_province'] : null,
            'country_iso' => 'IT',
            'country' => 'Italia',
            'email' => $cliente['email'] ?: null,
            'ei_code' => trim((string)($cliente['codice_sdi'] ?? '')) ?: null,
            'certified_email' => trim((string)($cliente['pec'] ?? '')) ?: null,
        ],
        'date' => $dataEmissione,
        'visible_subject' => 'Fattura ' . $numero,
        'currency' => [
            'id' => $cfg['currency_id'] ?? 'EUR',
        ],
        'language' => [
            'code' => $cfg['language_code'] ?? 'it',
            'name' => $cfg['language_name'] ?? 'Italiano',
        ],
        'items_list' => [
            [
                'name' => $descrizione,
                'qty' => 1,
                'net_price' => (float)$totale,
                'vat' => ['id' => (int)$vatId],
            ],
        ],
    ];

    $sdiCode = trim((string)($cliente['codice_sdi'] ?? ''));
    $pec = trim((string)($cliente['pec'] ?? ''));
    if ($piva !== '' && ($sdiCode !== '' || $pec !== '')) {
        $data['e_invoice'] = true;
    }

    // Non inviare payment_method/payments in creazione:
    // FIC valida che la somma pagamenti coincida con amount_due.
    // In questo flusso creiamo il documento e lasciamo eventuale incasso separato.

    try {
        $response = fic_request('POST', "/c/{$companyId}/issued_documents", ['data' => $data]);

        if ($response['status'] >= 400 && fic_bulk_is_payments_mismatch_error($response)) {
            $amountDue = fic_bulk_extract_amount_due($response);
            if ($amountDue > 0) {
                $retryData = $data;
                $retryData['payments_list'] = [[
                    'amount' => $amountDue,
                    'paid_date' => $dataEmissione,
                    'due_date' => fic_bulk_normalize_due_date($dataScadenza),
                ]];

                $paymentMethodId = isset($cfg['payment_method_id']) ? (int)$cfg['payment_method_id'] : 0;
                if ($paymentMethodId > 0) {
                    $retryData['payment_method'] = ['id' => $paymentMethodId];
                }

                $response = fic_request('POST', "/c/{$companyId}/issued_documents", ['data' => $retryData]);
            }
        }
    } catch (Throwable $e) {
        $skipped++;
        continue;
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $skipped++;
        continue;
    }

    $docId = $response['json']['data']['id'] ?? null;
    $status = $response['json']['data']['status'] ?? null;

    if ($docId) {
        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
        $upd->execute([
            ':doc_id' => $docId,
            ':status' => $status,
            ':id' => $fatturaId,
        ]);
        $ok++;
    } else {
        $skipped++;
    }
}

header('Location: clienti.php?fic_bulk_ok=' . $ok . '&fic_bulk_skipped=' . $skipped);
exit;
