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
$where = " WHERE piva IS NOT NULL AND piva <> '' AND azienda IS NOT NULL AND azienda <> ''";
$params = [];
if ($search !== '') {
    $where .= " AND (nome LIKE :q1 OR email LIKE :q2 OR azienda LIKE :q3 OR telefono LIKE :q4 OR indirizzo LIKE :q5 OR piva LIKE :q6)";
    $searchParam = '%' . $search . '%';
    $params[':q1'] = $searchParam;
    $params[':q2'] = $searchParam;
    $params[':q3'] = $searchParam;
    $params[':q4'] = $searchParam;
    $params[':q5'] = $searchParam;
    $params[':q6'] = $searchParam;
}

$cfg = fic_config();
$companyId = fic_get_company_id();
if ($companyId === '') {
    header('Location: clienti.php?fic_client_sync_error=missing_company_id');
    exit;
}

if (fic_get_access_token() === '') {
    header('Location: clienti.php?fic_client_sync_error=missing_token');
    exit;
}

function fic_sync_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fic_sync_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
        $stmt->execute([':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fic_sync_ensure_map_table(PDO $pdo): void
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
        // fallback: mapping table opzionale
    }
}

function fic_sync_get_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId): ?string
{
    if ($clienteId <= 0 || $companyId === '' || !fic_sync_table_exists($pdo, 'fic_clienti_map')) {
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

function fic_sync_set_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId, string $entityId, string $source = 'sync'): void
{
    if ($clienteId <= 0 || $companyId === '' || trim($entityId) === '') {
        return;
    }

    fic_sync_ensure_map_table($pdo);
    if (!fic_sync_table_exists($pdo, 'fic_clienti_map')) {
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
        // non bloccare la sync
    }
}

function fic_sync_trim_or_empty($value): string
{
    return trim((string)$value);
}

function fic_sync_normalize_sdi_code($value): string
{
    $code = strtoupper(fic_sync_trim_or_empty($value));
    if ($code === '') {
        return '';
    }
    return preg_match('/^[A-Z0-9]{7}$/', $code) ? $code : '';
}

function fic_sync_normalize_pec($value): string
{
    $pec = strtolower(fic_sync_trim_or_empty($value));
    if ($pec === '') {
        return '';
    }
    return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : '';
}

function fic_sync_parse_address_parts($value): array
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

try {
    $hasFicClientId = fic_sync_column_exists($pdo, 'clienti', 'fic_client_id');
    $hasFicSyncedAt = fic_sync_column_exists($pdo, 'clienti', 'fic_synced_at');
    if (!$hasFicClientId) {
        fic_sync_ensure_map_table($pdo);
    }

    $stmt = $pdo->prepare("SELECT * FROM clienti" . $where . " ORDER BY creato_il DESC");
    $stmt->execute($params);
    $clienti = $stmt->fetchAll();

    $ok = 0;
    $failed = 0;
    $firstFailureDetail = '';

    foreach ($clienti as $c) {
        $addressParts = fic_sync_parse_address_parts((string)($c['indirizzo'] ?? ''));
        $safePec = fic_sync_normalize_pec($c['pec'] ?? '');
        $safeSdi = fic_sync_normalize_sdi_code($c['codice_sdi'] ?? '');
        $name = fic_sync_trim_or_empty($c['azienda'] ?? '');
        if ($name === '') {
            $name = fic_sync_trim_or_empty($c['nome'] ?? '');
        }

        $payload = [
            'data' => array_filter([
                'name' => $name,
                'vat_number' => $c['piva'] ?? null,
                'internal_code' => 'TDG-CLI-' . (int)$c['id'],
                'address_street' => $addressParts['address_street'] !== '' ? $addressParts['address_street'] : ($c['indirizzo'] ?? null),
                'address_postal_code' => $addressParts['address_postal_code'] !== '' ? $addressParts['address_postal_code'] : null,
                'address_city' => $addressParts['address_city'] !== '' ? $addressParts['address_city'] : null,
                'address_province' => $addressParts['address_province'] !== '' ? $addressParts['address_province'] : null,
                'country_iso' => 'IT',
                'country' => 'Italia',
                'email' => $c['email'] ?? null,
                'certified_email' => $safePec !== '' ? $safePec : null,
                'ei_code' => $safeSdi !== '' ? $safeSdi : null,
            ], static function ($v) {
                return $v !== null && $v !== '';
            }),
        ];

        try {
            $resp = null;
            $usedFallbackCreate = false;
            $currentFicId = '';

            if ($hasFicClientId) {
                $currentFicId = trim((string)($c['fic_client_id'] ?? ''));
            }
            if ($currentFicId === '') {
                $currentFicId = (string)(fic_sync_get_mapped_entity_id($pdo, (int)($c['id'] ?? 0), $companyId) ?? '');
            }

            if ($currentFicId !== '') {
                $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$currentFicId}", $payload);

                if (!($resp['status'] >= 200 && $resp['status'] < 300)) {
                    $status = (int)($resp['status'] ?? 0);
                    if ($status === 404 || $status === 400 || $status === 422) {
                        $resp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);
                        $usedFallbackCreate = true;
                    }
                }
            } else {
                $resp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);

                if (!($resp['status'] >= 200 && $resp['status'] < 300) && (int)($resp['status'] ?? 0) === 409) {
                    // Conflitto su P.IVA: FIC richiede Codice interno per consentire il salvataggio.
                    $payload['data']['internal_code'] = 'TDG-CLI-' . (int)$c['id'];
                    $resp = fic_request('POST', "/c/{$companyId}/entities/clients", $payload);
                }
            }

            if ($resp && $resp['status'] >= 200 && $resp['status'] < 300) {
                $ficId = $resp['json']['data']['id'] ?? ($currentFicId !== '' ? $currentFicId : null);

                if ($hasFicClientId && $hasFicSyncedAt) {
                    $upd = $pdo->prepare("UPDATE clienti SET fic_client_id = :fic_id, fic_synced_at = NOW() WHERE id = :id");
                    $upd->execute([
                        ':fic_id' => $ficId,
                        ':id' => $c['id'],
                    ]);
                } elseif ($hasFicClientId) {
                    $upd = $pdo->prepare("UPDATE clienti SET fic_client_id = :fic_id WHERE id = :id");
                    $upd->execute([
                        ':fic_id' => $ficId,
                        ':id' => $c['id'],
                    ]);
                } elseif ($hasFicSyncedAt) {
                    $upd = $pdo->prepare("UPDATE clienti SET fic_synced_at = NOW() WHERE id = :id");
                    $upd->execute([':id' => $c['id']]);
                }

                if (!empty($ficId)) {
                    fic_sync_set_mapped_entity_id($pdo, (int)$c['id'], $companyId, (string)$ficId, $usedFallbackCreate ? 'sync_recreate' : 'sync');
                }
                $ok++;

                if ($usedFallbackCreate) {
                    // ID precedente non più valido: ora riallineato con il nuovo ID creato.
                }
            } else {
                $failed++;
                if ($firstFailureDetail === '') {
                    $status = (int)($resp['status'] ?? 0);
                    $msg = '';
                    if (!empty($resp['json']['error']['message'])) {
                        $msg = (string)$resp['json']['error']['message'];
                    } elseif (!empty($resp['json']['message'])) {
                        $msg = (string)$resp['json']['message'];
                    }
                    $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ' (' . trim((string)($c['azienda'] ?: $c['nome'])) . ') - HTTP ' . $status . ($msg !== '' ? (': ' . $msg) : '');
                }
            }
        } catch (Throwable $e) {
            $failed++;
            if ($firstFailureDetail === '') {
                $firstFailureDetail = 'Cliente ID ' . (int)$c['id'] . ' (' . trim((string)($c['azienda'] ?: $c['nome'])) . ') - ' . substr((string)$e->getMessage(), 0, 160);
            }
        }
    }

    $redirect = 'clienti.php?fic_client_sync_ok=' . $ok . '&fic_client_sync_failed=' . $failed;
    if ($failed > 0 && $firstFailureDetail !== '') {
        $redirect .= '&fic_client_sync_error_detail=' . rawurlencode($firstFailureDetail);
    }
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    $detail = rawurlencode(substr($e->getMessage(), 0, 180));
    header('Location: clienti.php?fic_client_sync_error=runtime&fic_client_sync_error_detail=' . $detail);
    exit;
}
