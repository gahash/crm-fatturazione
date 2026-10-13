<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';
require_once __DIR__ . '/../modules/fatture/actions/fic_api.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clienteId = max(0, (int)($_GET['cliente_id'] ?? 0));
 $forcedFicClientId = trim((string)($_GET['fic_client_id'] ?? ''));
if ($clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cliente_id mancante'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

function sync_single_trim($value): string
{
    return trim((string)$value);
}

function sync_single_normalize_sdi_code($value): string
{
    $code = strtoupper(sync_single_trim($value));
    return preg_match('/^[A-Z0-9]{7}$/', $code) === 1 ? $code : '';
}

function sync_single_normalize_pec($value): string
{
    $pec = strtolower(sync_single_trim($value));
    return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : '';
}

function sync_single_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sync_single_ensure_map_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fic_clienti_map (
                cliente_id INT NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                fic_entity_id BIGINT NOT NULL,
                source VARCHAR(20) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (cliente_id, company_id),
                KEY idx_fic_entity_id (fic_entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
    }
}

function sync_single_get_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId): ?string
{
    if ($clienteId <= 0 || $companyId === '' || !sync_single_table_exists($pdo, 'fic_clienti_map')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT fic_entity_id FROM fic_clienti_map WHERE cliente_id = :cliente_id AND company_id = :company_id LIMIT 1');
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':company_id' => $companyId,
        ]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return (string)$value;
    } catch (Throwable $e) {
        return null;
    }
}

function sync_single_set_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId, string $entityId, string $source = 'single-sync'): void
{
    if ($clienteId <= 0 || $companyId === '' || trim($entityId) === '') {
        return;
    }

    sync_single_ensure_map_table($pdo);
    if (!sync_single_table_exists($pdo, 'fic_clienti_map')) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fic_clienti_map (cliente_id, company_id, fic_entity_id, source, updated_at)
             VALUES (:cliente_id, :company_id, :entity_id, :source, NOW())
             ON DUPLICATE KEY UPDATE
                fic_entity_id = VALUES(fic_entity_id),
                source = VALUES(source),
                updated_at = NOW()'
        );
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':company_id' => $companyId,
            ':entity_id' => (int)$entityId,
            ':source' => $source,
        ]);
    } catch (Throwable $e) {
    }
}

function sync_single_parse_address_parts($value): array
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
        $postalIndex = null;
        foreach ($tokens as $index => $token) {
            if ($postalIndex === null && preg_match('/^\d{5}$/', $token) === 1) {
                $postalCode = $token;
                $postalIndex = $index;
                break;
            }
        }

        if ($postalIndex === null) {
            $street = rtrim((string)$tokens[0], " ,.-");
        } else {
            $streetTokens = array_slice($tokens, 0, $postalIndex);
            $street = rtrim(implode(' ', $streetTokens), " ,.-");

            for ($index = $postalIndex + 1; $index < count($tokens); $index++) {
                $token = trim((string)$tokens[$index]);
                if ($token === '' || preg_match('/^(IT|ITALIA)$/i', $token) === 1) {
                    continue;
                }
                if ($province === '' && preg_match('/^[A-Za-z]{2}$/', $token) === 1) {
                    $province = strtoupper($token);
                    continue;
                }
                if ($city === '' && preg_match('/^(.+?)\s+([A-Za-z]{2})$/', $token, $matches) === 1) {
                    $city = trim((string)$matches[1]);
                    if ($province === '') {
                        $province = strtoupper((string)$matches[2]);
                    }
                    continue;
                }
                if ($city === '') {
                    $city = $token;
                    continue;
                }
                $city .= ' ' . $token;
            }
        }
    } else {
        $street = rtrim($raw, " ,.-");
    }

    if ($postalCode === '' && preg_match('/\b(\d{5})\b/', $raw, $matches) === 1) {
        $postalCode = (string)$matches[1];
    }
    if ($province === '' && preg_match('/\b([A-Za-z]{2})\b\s*$/', trim($raw), $matches) === 1) {
        $province = strtoupper((string)$matches[1]);
    }
    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\b\s*,?\s*([^,]+?)(?:\s*,\s*[A-Za-z]{2})?(?:\s*,|$)/i', $raw, $matches) === 1) {
        $city = trim((string)$matches[1]);
    }

    return [
        'address_street' => trim($street),
        'address_postal_code' => preg_match('/^\d{5}$/', $postalCode) === 1 ? $postalCode : '',
        'address_city' => trim($city),
        'address_province' => preg_match('/^[A-Z]{2}$/', $province) === 1 ? $province : '',
    ];
}

function sync_single_extract_clients_from_response(array $response): array
{
    $data = $response['json']['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['items_list']) && is_array($data['items_list'])) {
        return $data['items_list'];
    }
    if (isset($data['clients']) && is_array($data['clients'])) {
        return $data['clients'];
    }
    if (array_keys($data) === range(0, count($data) - 1)) {
        return $data;
    }
    return [];
}

function sync_single_find_existing_entity_id(string $companyId, array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (!is_array($data)) {
        return null;
    }

    $vat = trim((string)($data['vat_number'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $name = trim((string)($data['name'] ?? ''));
    $internalCode = trim((string)($data['internal_code'] ?? ''));

    $queries = [];
    if ($internalCode !== '') {
        $queries[] = ['q' => $internalCode, 'per_page' => 50];
    }
    if ($vat !== '') {
        $queries[] = ['vat_number' => $vat, 'per_page' => 50];
        $queries[] = ['q' => $vat, 'per_page' => 50];
    }
    if ($email !== '') {
        $queries[] = ['q' => $email, 'per_page' => 50];
    }
    if ($name !== '') {
        $queries[] = ['q' => $name, 'per_page' => 50];
    }

    $matchesItem = static function (array $item) use ($internalCode, $vat, $email, $name): ?string {
        $itemId = (int)($item['id'] ?? 0);
        if ($itemId <= 0) {
            return null;
        }

        $itemVat = trim((string)($item['vat_number'] ?? ''));
        $itemEmail = strtolower(trim((string)($item['email'] ?? '')));
        $itemName = trim((string)($item['name'] ?? ''));
        $itemInternalCode = trim((string)($item['internal_code'] ?? ($item['code'] ?? '')));

        if ($internalCode !== '' && $itemInternalCode !== '' && strcasecmp($internalCode, $itemInternalCode) === 0) {
            return (string)$itemId;
        }
        if ($vat !== '' && $itemVat !== '' && strcasecmp($vat, $itemVat) === 0) {
            return (string)$itemId;
        }
        if ($email !== '' && $itemEmail !== '' && strcasecmp($email, $itemEmail) === 0) {
            return (string)$itemId;
        }
        if ($name !== '' && $itemName !== '' && strcasecmp($name, $itemName) === 0) {
            return (string)$itemId;
        }

        return null;
    };

    foreach ($queries as $query) {
        try {
            $resp = fic_request('GET', "/c/{$companyId}/entities/clients?" . http_build_query($query));
            $status = (int)($resp['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                continue;
            }

            foreach (sync_single_extract_clients_from_response($resp) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $matchedId = $matchesItem($item);
                if ($matchedId !== null) {
                    return $matchedId;
                }
            }
        } catch (Throwable $e) {
        }
    }

    for ($page = 1; $page <= 10; $page++) {
        try {
            $resp = fic_request('GET', "/c/{$companyId}/entities/clients?" . http_build_query([
                'page' => $page,
                'per_page' => 100,
            ]));
            $status = (int)($resp['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                break;
            }

            $items = sync_single_extract_clients_from_response($resp);
            if (!$items) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $matchedId = $matchesItem($item);
                if ($matchedId !== null) {
                    return $matchedId;
                }
            }

            if (count($items) < 100) {
                break;
            }
        } catch (Throwable $e) {
            break;
        }
    }

    return null;
}

try {
    $companyId = fic_get_company_id();
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }
    if (fic_get_access_token() === '') {
        throw new RuntimeException('access_token FIC mancante');
    }

    $stmt = $pdo->prepare('SELECT * FROM clienti WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $clienteId]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) {
        throw new RuntimeException('Cliente non trovato');
    }

    $addressParts = sync_single_parse_address_parts((string)($cliente['indirizzo'] ?? ''));
    $safePec = sync_single_normalize_pec($cliente['pec'] ?? '');
    $safeSdi = sync_single_normalize_sdi_code($cliente['codice_sdi'] ?? '');
    $name = sync_single_trim($cliente['azienda'] ?? '');
    if ($name === '') {
        $name = sync_single_trim($cliente['nome'] ?? '');
    }

    $payload = [
        'data' => array_filter([
            'name' => $name,
            'vat_number' => sync_single_trim($cliente['piva'] ?? '') !== '' ? $cliente['piva'] : null,
            'internal_code' => 'TDG-CLI-' . (int)$cliente['id'],
            'address_street' => $addressParts['address_street'] !== '' ? $addressParts['address_street'] : ($cliente['indirizzo'] ?? null),
            'address_postal_code' => $addressParts['address_postal_code'] !== '' ? $addressParts['address_postal_code'] : null,
            'address_city' => $addressParts['address_city'] !== '' ? $addressParts['address_city'] : null,
            'address_province' => $addressParts['address_province'] !== '' ? $addressParts['address_province'] : null,
            'country_iso' => 'IT',
            'country' => 'Italia',
            'email' => sync_single_trim($cliente['email'] ?? '') !== '' ? $cliente['email'] : null,
            'phone' => sync_single_trim($cliente['telefono'] ?? '') !== '' ? $cliente['telefono'] : null,
            'certified_email' => $safePec !== '' ? $safePec : null,
            'ei_code' => $safeSdi !== '' ? $safeSdi : null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        }),
    ];

    $hasFicClientId = false;
    try {
        $check = $pdo->query("SHOW COLUMNS FROM clienti LIKE 'fic_client_id'");
        $hasFicClientId = (bool)($check && $check->fetchColumn());
    } catch (Throwable $e) {
        $hasFicClientId = false;
    }

    $currentFicId = $forcedFicClientId !== '' ? $forcedFicClientId : ($hasFicClientId ? trim((string)($cliente['fic_client_id'] ?? '')) : '');
    if ($currentFicId === '') {
        $currentFicId = (string)(sync_single_get_mapped_entity_id($pdo, $clienteId, $companyId) ?? '');
    }
    $usedFallbackCreate = false;

    if ($currentFicId === '') {
        $currentFicId = (string)(sync_single_find_existing_entity_id($companyId, $payload) ?? '');
    }

    if ($currentFicId !== '') {
        $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$currentFicId}", $payload);
        if (!(($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300)) {
            $status = (int)($resp['status'] ?? 0);
            if ($status === 404 || $status === 400 || $status === 422) {
                $existingId = sync_single_find_existing_entity_id($companyId, $payload);
                if ($existingId !== null) {
                    $currentFicId = $existingId;
                    $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$currentFicId}", $payload);
                }
                if (!(($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300)) {
                    $resp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);
                    $usedFallbackCreate = true;
                }
            }
        }
    } else {
        $resp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);
        if (!(($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300) && (int)($resp['status'] ?? 0) === 409) {
            $existingId = sync_single_find_existing_entity_id($companyId, $payload);
            if ($existingId !== null) {
                $currentFicId = $existingId;
                $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$currentFicId}", $payload);
            }
        }
    }

    $status = (int)($resp['status'] ?? 0);
    if ($status < 200 || $status >= 300) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'cliente_id' => $clienteId,
            'http_status' => $status,
            'response' => $resp['json'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ficId = $resp['json']['data']['id'] ?? ($currentFicId !== '' ? $currentFicId : null);
    if (!empty($ficId)) {
        sync_single_set_mapped_entity_id($pdo, $clienteId, $companyId, (string)$ficId);
    }
    if ($hasFicClientId && !empty($ficId)) {
        try {
            $upd = $pdo->prepare("UPDATE clienti SET fic_client_id = :fic_id, fic_synced_at = NOW() WHERE id = :id");
            $upd->execute([':fic_id' => $ficId, ':id' => $clienteId]);
        } catch (Throwable $e) {
            try {
                $upd = $pdo->prepare("UPDATE clienti SET fic_client_id = :fic_id WHERE id = :id");
                $upd->execute([':fic_id' => $ficId, ':id' => $clienteId]);
            } catch (Throwable $ignored) {
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'cliente_id' => $clienteId,
        'cliente' => $name,
        'forced_fic_client_id' => $forcedFicClientId !== '' ? $forcedFicClientId : null,
        'previous_fic_client_id' => $currentFicId !== '' ? $currentFicId : null,
        'fic_client_id' => $ficId,
        'used_fallback_create' => $usedFallbackCreate,
        'payload' => $payload,
        'response' => $resp['json'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}