<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function stripe_non_idonei_has_valid_sdi(?string $value): bool
{
    return preg_match('/^[A-Z0-9]{7}$/', strtoupper(trim((string)$value))) === 1;
}

function stripe_non_idonei_has_valid_pec(?string $value): bool
{
    $pec = trim((string)$value);
    return $pec !== '' && filter_var($pec, FILTER_VALIDATE_EMAIL) !== false;
}

function stripe_non_idonei_is_valid_italian_vat(string $vat): bool
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

function stripe_non_idonei_normalize_vat($value): string
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
        if (stripe_non_idonei_is_valid_italian_vat($paddedVat)) {
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

function stripe_non_idonei_is_valid_city_name(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    return preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u", $value) === 1;
}

function stripe_non_idonei_parse_address($value): array
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
                if ($city === '' || !stripe_non_idonei_is_valid_city_name($city)) {
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
                if ($city === '' || !stripe_non_idonei_is_valid_city_name($city)) {
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

            if ($city === '' || !stripe_non_idonei_is_valid_city_name($city)) {
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

function stripe_non_idonei_missing_fields(array $cliente): array
{
    $missing = [];
    $vatNumber = stripe_non_idonei_normalize_vat($cliente['piva'] ?? '');
    $sdi = (string)($cliente['codice_sdi'] ?? '');
    $pec = (string)($cliente['pec'] ?? '');
    $address = stripe_non_idonei_parse_address($cliente['indirizzo'] ?? '');

    if ($vatNumber === '') {
        $missing[] = 'P.IVA';
    } elseif (preg_match('/^\d{11}$/', $vatNumber) && !stripe_non_idonei_is_valid_italian_vat($vatNumber)) {
        $missing[] = 'P.IVA valida';
    }
    if (!stripe_non_idonei_has_valid_sdi($sdi) && !stripe_non_idonei_has_valid_pec($pec)) {
        $missing[] = 'Codice SDI valido (7 caratteri) o PEC valida';
    }
    if (trim((string)($address['address_street'] ?? '')) === ''
        || !preg_match('/^\d{5}$/', (string)($address['address_postal_code'] ?? ''))
        || !stripe_non_idonei_is_valid_city_name((string)($address['address_city'] ?? ''))
        || !preg_match('/^[A-Z]{2}$/', (string)($address['address_province'] ?? ''))) {
        $missing[] = 'Indirizzo completo (CAP + Comune + Provincia)';
    }

    return $missing;
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT c.id, c.nome, c.email, c.azienda, c.indirizzo, c.piva, c.codice_sdi, c.pec,
        ss.total_charges, ss.total_amount_charged, ss.last_charge_date
        FROM clienti c
        JOIN stripe_sync ss ON ss.cliente_id = c.id AND ss.total_charges > 0
        ORDER BY ss.last_charge_date DESC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clients = [];
    foreach ($rows as $row) {
        $missing = stripe_non_idonei_missing_fields($row);
        if (!$missing) {
            continue;
        }

        $clients[] = [
            'cliente_id' => (int)($row['id'] ?? 0),
            'cliente' => trim((string)((($row['azienda'] ?? '') !== '') ? $row['azienda'] : ($row['nome'] ?? ''))),
            'email' => (string)($row['email'] ?? ''),
            'piva' => (string)($row['piva'] ?? ''),
            'codice_sdi' => (string)($row['codice_sdi'] ?? ''),
            'pec' => (string)($row['pec'] ?? ''),
            'indirizzo' => (string)($row['indirizzo'] ?? ''),
            'total_charges' => (int)($row['total_charges'] ?? 0),
            'total_amount_charged' => (int)($row['total_amount_charged'] ?? 0),
            'last_charge_date' => (string)($row['last_charge_date'] ?? ''),
            'missing' => $missing,
        ];
    }

    echo json_encode([
        'ok' => true,
        'summary' => [
            'total_non_idonei' => count($clients),
        ],
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}