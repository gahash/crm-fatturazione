<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../fatture/actions/fic_api.php';

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    header('Location: clienti.php');
    exit;
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$params = [];
$searchClause = '';
$searchClauseClient = '';
if ($search !== '') {
    $searchClause = " AND (nome LIKE :q1 OR email LIKE :q2 OR azienda LIKE :q3 OR telefono LIKE :q4 OR indirizzo LIKE :q5 OR piva LIKE :q6 OR codice_sdi LIKE :q7 OR pec LIKE :q8)";
    $searchClauseClient = " AND (c.nome LIKE :q1 OR c.email LIKE :q2 OR c.azienda LIKE :q3 OR c.telefono LIKE :q4 OR c.indirizzo LIKE :q5 OR c.piva LIKE :q6 OR c.codice_sdi LIKE :q7 OR c.pec LIKE :q8)";
    $searchParam = '%' . $search . '%';
    $params[':q1'] = $searchParam;
    $params[':q2'] = $searchParam;
    $params[':q3'] = $searchParam;
    $params[':q4'] = $searchParam;
    $params[':q5'] = $searchParam;
    $params[':q6'] = $searchParam;
    $params[':q7'] = $searchParam;
    $params[':q8'] = $searchParam;
}

function fic_repair_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fic_repair_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fic_repair_ensure_map_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fic_clienti_map (
                cliente_id INT NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                fic_entity_id BIGINT NOT NULL,
                source VARCHAR(20) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (cliente_id, company_id),
                KEY idx_fic_entity_id (fic_entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // fallback: il chiamante gestira' l'assenza della tabella
    }
}

function fic_repair_get_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId): ?string
{
    if ($clienteId <= 0 || $companyId === '' || !fic_repair_table_exists($pdo, 'fic_clienti_map')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT fic_entity_id FROM fic_clienti_map WHERE cliente_id = :cid AND company_id = :company LIMIT 1");
        $stmt->execute([
            ':cid' => $clienteId,
            ':company' => $companyId,
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

function fic_repair_set_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId, string $entityId, string $source = 'repair'): void
{
    if ($clienteId <= 0 || $companyId === '' || trim($entityId) === '') {
        return;
    }

    fic_repair_ensure_map_table($pdo);
    if (!fic_repair_table_exists($pdo, 'fic_clienti_map')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO fic_clienti_map (cliente_id, company_id, fic_entity_id, source, updated_at)
            VALUES (:cid, :company, :eid, :source, NOW())
            ON DUPLICATE KEY UPDATE
                fic_entity_id = VALUES(fic_entity_id),
                source = VALUES(source),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':cid' => $clienteId,
            ':company' => $companyId,
            ':eid' => (int)$entityId,
            ':source' => $source,
        ]);
    } catch (Throwable $e) {
        // non bloccare il repair
    }
}

function fic_repair_trim_or_empty($value): string
{
    return trim((string)$value);
}

function fic_repair_normalize_sdi_code($value): string
{
    $code = strtoupper(fic_repair_trim_or_empty($value));
    if ($code === '') {
        return '';
    }
    return preg_match('/^[A-Z0-9]{7}$/', $code) ? $code : '';
}

function fic_repair_normalize_pec($value): string
{
    $pec = strtolower(fic_repair_trim_or_empty($value));
    if ($pec === '') {
        return '';
    }
    return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : '';
}

function fic_repair_parse_address_parts($value): array
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
        $postalIndex = null;
        foreach ($tokens as $i => $t) {
            if ($postalIndex === null && preg_match('/^\d{5}$/', $t)) {
                $postalCode = $t;
                $postalIndex = $i;
                break;
            }
        }

        if ($postalIndex === null) {
            $street = rtrim((string)$tokens[0], " ,.-");
        } else {
            $streetTokens = array_slice($tokens, 0, $postalIndex);
            $street = rtrim(implode(' ', $streetTokens), " ,.-");

            for ($i = $postalIndex + 1; $i < count($tokens); $i++) {
                $t = trim((string)$tokens[$i]);
                if ($t === '' || preg_match('/^(IT|ITALIA)$/i', $t)) {
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
                    continue;
                }

                $city .= ' ' . $t;
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

function fic_repair_payload_from_cliente(array $c): array
{
    $name = fic_repair_trim_or_empty($c['azienda'] ?? '');
    if ($name === '') {
        $name = fic_repair_trim_or_empty($c['nome'] ?? '');
    }

    $addressParts = fic_repair_parse_address_parts($c['indirizzo'] ?? '');
    $safePec = fic_repair_normalize_pec($c['pec'] ?? '');
    $safeSdi = fic_repair_normalize_sdi_code($c['codice_sdi'] ?? '');

    return [
        'data' => array_filter([
            'name' => $name,
            'vat_number' => fic_repair_trim_or_empty($c['piva'] ?? '') !== '' ? $c['piva'] : null,
            'internal_code' => 'TDG-CLI-' . (int)$c['id'],
            'address_street' => $addressParts['address_street'] !== '' ? $addressParts['address_street'] : ($c['indirizzo'] ?? null),
            'address_postal_code' => $addressParts['address_postal_code'] !== '' ? $addressParts['address_postal_code'] : null,
            'address_city' => $addressParts['address_city'] !== '' ? $addressParts['address_city'] : null,
            'address_province' => $addressParts['address_province'] !== '' ? $addressParts['address_province'] : null,
            'country_iso' => 'IT',
            'country' => 'Italia',
            'email' => fic_repair_trim_or_empty($c['email'] ?? '') !== '' ? $c['email'] : null,
            'phone' => fic_repair_trim_or_empty($c['telefono'] ?? '') !== '' ? $c['telefono'] : null,
            'certified_email' => $safePec !== '' ? $safePec : null,
            'ei_code' => $safeSdi !== '' ? $safeSdi : null,
        ], static function ($v) {
            return $v !== null && $v !== '';
        }),
    ];
}

function fic_repair_extract_clients_from_response(array $response): array
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

function fic_repair_find_existing_entity_id(string $companyId, array $payload): ?string
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
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $itemVat = trim((string)($item['vat_number'] ?? ''));
        $itemEmail = strtolower(trim((string)($item['email'] ?? '')));
        $itemName = trim((string)($item['name'] ?? ''));
        $itemInternalCode = trim((string)($item['internal_code'] ?? ($item['code'] ?? '')));

        if ($internalCode !== '' && $itemInternalCode !== '' && strcasecmp($internalCode, $itemInternalCode) === 0) {
            return (string)$id;
        }
        if ($vat !== '' && $itemVat !== '' && strcasecmp($vat, $itemVat) === 0) {
            return (string)$id;
        }
        if ($email !== '' && $itemEmail !== '' && strcasecmp($email, $itemEmail) === 0) {
            return (string)$id;
        }
        if ($name !== '' && $itemName !== '' && strcasecmp($name, $itemName) === 0) {
            return (string)$id;
        }

        return null;
    };

    foreach ($queries as $query) {
        try {
            $path = "/c/{$companyId}/entities/clients?" . http_build_query($query);
            $resp = fic_request('GET', $path);
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                continue;
            }

            $items = fic_repair_extract_clients_from_response($resp);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $matchedId = $matchesItem($item);
                if ($matchedId !== null) {
                    return $matchedId;
                }
            }
        } catch (Throwable $e) {
            // continua col prossimo criterio
        }
    }

    for ($page = 1; $page <= 10; $page++) {
        try {
            $resp = fic_request('GET', "/c/{$companyId}/entities/clients?" . http_build_query([
                'page' => $page,
                'per_page' => 100,
            ]));
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                break;
            }

            $items = fic_repair_extract_clients_from_response($resp);
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

function fic_repair_error_detail(array $resp): string
{
    $status = (int)($resp['status'] ?? 0);
    $message = trim((string)($resp['json']['error']['message'] ?? ($resp['json']['message'] ?? '')));
    if ($message === '') {
        $message = trim((string)($resp['body'] ?? ''));
    }
    if ($message === '') {
        $message = 'Risposta API non valida';
    }
    return 'HTTP ' . $status . ': ' . substr($message, 0, 240);
}

$cfg = fic_config();
$companyId = fic_get_company_id();
if ($companyId === '') {
    header('Location: clienti.php?fic_repair_error=missing_company_id');
    exit;
}

if (fic_get_access_token() === '') {
    header('Location: clienti.php?fic_repair_error=missing_token');
    exit;
}

try {
    $hasFicSyncedAt = fic_repair_column_exists($pdo, 'clienti', 'fic_synced_at');
    fic_repair_ensure_map_table($pdo);
    $hasMapTable = fic_repair_table_exists($pdo, 'fic_clienti_map');

    $paramsMap = $params;
    $paramsMap[':company_id'] = $companyId;

    if ($hasMapTable) {
        $stmt = $pdo->prepare("SELECT c.*, m.fic_entity_id AS _fic_entity_id FROM clienti c LEFT JOIN fic_clienti_map m ON m.cliente_id = c.id AND m.company_id = :company_id WHERE 1=1" . $searchClauseClient . " ORDER BY c.creato_il DESC");
        $stmt->execute($paramsMap);
        $clienti = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clienti WHERE 1=1" . $searchClause . " ORDER BY creato_il DESC");
        $stmt->execute($params);
        $clienti = $stmt->fetchAll();
    }

    $ok = 0;
    $failed = 0;
    $firstFailureDetail = '';

    foreach ($clienti as $c) {
        $payload = fic_repair_payload_from_cliente($c);
        $entityData = $payload['data'] ?? [];
        if (!is_array($entityData) || trim((string)($entityData['name'] ?? '')) === '') {
            $failed++;
            if ($firstFailureDetail === '') {
                $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ': nome/azienda mancante per update anagrafica FIC.';
            }
            continue;
        }

        $ficId = trim((string)($c['fic_client_id'] ?? ($c['_fic_entity_id'] ?? '')));
        if ($ficId === '') {
            $ficId = (string)(fic_repair_get_mapped_entity_id($pdo, (int)($c['id'] ?? 0), $companyId) ?? '');
        }
        if ($ficId === '') {
            $existingId = fic_repair_find_existing_entity_id($companyId, $payload);
            if ($existingId !== null) {
                $ficId = $existingId;
            }
        }
        if ($ficId === '') {
            $failed++;
            if ($firstFailureDetail === '') {
                $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ': cliente non trovato su FIC (match per P.IVA/email/nome assente).';
            }
            continue;
        }

        try {
            $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$ficId}", $payload);
            $status = (int)($resp['status'] ?? 0);

            if (!($status >= 200 && $status < 300) && in_array($status, [404, 400, 422], true)) {
                $existingId = fic_repair_find_existing_entity_id($companyId, $payload);
                if ($existingId !== null) {
                    $ficId = $existingId;
                    $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$ficId}", $payload);
                    $status = (int)($resp['status'] ?? 0);
                }
            }

            if (!($status >= 200 && $status < 300) && in_array($status, [404, 400, 422], true)) {
                $createResp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);
                $createStatus = (int)($createResp['status'] ?? 0);
                if ($createStatus >= 200 && $createStatus < 300) {
                    $createdId = (string)($createResp['json']['data']['id'] ?? '');
                    if ($createdId !== '') {
                        $ficId = $createdId;
                        $resp = $createResp;
                        $status = $createStatus;
                    }
                }
            }

            if ($status >= 200 && $status < 300) {
                if ($hasFicSyncedAt) {
                    $upd = $pdo->prepare("UPDATE clienti SET fic_synced_at = NOW() WHERE id = :id");
                    $upd->execute([':id' => $c['id']]);
                }
                fic_repair_set_mapped_entity_id($pdo, (int)$c['id'], $companyId, (string)$ficId, 'repair');
                $ok++;
            } else {
                $failed++;
                if ($firstFailureDetail === '') {
                    $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ' (' . trim((string)($c['azienda'] ?: $c['nome'])) . ') - ' . fic_repair_error_detail($resp);
                }
            }
        } catch (Throwable $e) {
            $failed++;
            if ($firstFailureDetail === '') {
                $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ' (' . trim((string)($c['azienda'] ?: $c['nome'])) . ') - ' . substr((string)$e->getMessage(), 0, 180);
            }
        }
    }

    $redirect = 'clienti.php?fic_repair_ok=' . $ok . '&fic_repair_failed=' . $failed;
    if ($failed > 0 && $firstFailureDetail !== '') {
        $redirect .= '&fic_repair_error_detail=' . rawurlencode($firstFailureDetail);
    }
    if ($search !== '') {
        $redirect .= '&search=' . rawurlencode($search);
    }

    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    $detail = rawurlencode(substr((string)$e->getMessage(), 0, 180));
    $redirect = 'clienti.php?fic_repair_error=runtime&fic_repair_error_detail=' . $detail;
    if ($search !== '') {
        $redirect .= '&search=' . rawurlencode($search);
    }
    header('Location: ' . $redirect);
    exit;
}
