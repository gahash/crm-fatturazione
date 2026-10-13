<?php
/**
 * Invia il documento FIC allo SDI (fattura elettronica)
 * @param string $companyId
 * @param string $docId
 * @return array [status, message]
 */

/**
 * Invia il documento FIC allo SDI (fattura elettronica) con logging avanzato e gestione robusta degli errori.
 * @param string $companyId
 * @param string $docId
 * @return array [status, message]
 */
function fic_send_sdi_document(string $companyId, string $docId): array
{
    require_once __DIR__ . '/fic_api.php';
    $logContext = [
        'company_id' => $companyId,
        'doc_id' => $docId,
        'timestamp' => date('c'),
    ];

    // Endpoint API ufficiale per invio e-fattura allo SDI.
    $response = fic_request('POST', "/c/{$companyId}/issued_documents/{$docId}/e_invoice/send", [
        'data' => new stdClass(),
    ]);

    // Fallback legacy per installazioni vecchie/API proxy custom.
    if ($response['status'] === 404 || $response['status'] === 405) {
        fic_log_if_possible(null, null, 'fic_send_sdi_document', 'warning', 'Fallback legacy endpoint /send attivato', $logContext);
        $response = fic_request('POST', "/c/{$companyId}/issued_documents/{$docId}/send", [
            'data' => new stdClass(),
        ]);
    }

    // Successo
    if ($response['status'] >= 200 && $response['status'] < 300) {
        fic_log_if_possible(null, null, 'fic_send_sdi_document', 'ok', 'Inviato allo SDI', $logContext);
        return ['ok', 'Inviato allo SDI'];
    }

    // Analisi dettagliata errore
    $msg = $response['json']['error']['message'] ?? $response['body'] ?? 'Errore invio SDI';
    $validationResult = $response['json']['error']['validation_result'] ?? null;
    if (is_array($validationResult) && $validationResult) {
        $pairs = [];
        foreach ($validationResult as $field => $messages) {
            if (is_array($messages)) {
                $pairs[] = $field . ': ' . implode(', ', array_map('strval', $messages));
            } else {
                $pairs[] = $field . ': ' . (string)$messages;
            }
        }
        $extra = implode(' | ', $pairs);
        if ($extra !== '') {
            $msg .= ' - ' . $extra;
        }
    }

    // Logging dettagliato errore
    $logContext['http_status'] = $response['status'] ?? null;
    $logContext['error'] = $msg;
    fic_log_if_possible(null, null, 'fic_send_sdi_document', 'error', 'Errore invio SDI: ' . substr($msg, 0, 300), $logContext);

    return ['error', $msg];
}

function fic_is_sdi_already_in_progress_message(string $message): bool
{
    $normalized = fic_lower(trim($message));
    if ($normalized === '') {
        return false;
    }

    return (strpos($normalized, 'gia in corso un tentativo di invio') !== false)
        || (strpos($normalized, 'già in corso un tentativo di invio') !== false)
        || (strpos($normalized, 'tentativo di invio per questa fattura') !== false)
        || (strpos($normalized, 'invio per questa fattura') !== false && strpos($normalized, 'gia in corso') !== false)
        || (strpos($normalized, 'already in progress') !== false);
}

function fic_is_usage_limit_error_message(string $message): bool
{
    $normalized = fic_lower(trim($message));
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, 'usage_limit_reached') !== false
        || strpos($normalized, 'api usage limit') !== false
        || strpos($normalized, 'you have reached your api usage limit') !== false) {
        return true;
    }

    return (strpos($normalized, 'http 409') !== false)
        && (strpos($normalized, 'limite di documenti inclusi') !== false
            || strpos($normalized, 'superato il limite di documenti') !== false);
}

function fic_response_error_snippet(array $response, int $maxLen = 800): string
{
    $msg = trim((string)($response['json']['error']['message'] ?? ''));
    if ($msg !== '') {
        return substr($msg, 0, $maxLen);
    }

    $body = trim((string)($response['body'] ?? ''));
    if ($body !== '') {
        return substr($body, 0, $maxLen);
    }

    return 'content_type=' . (string)($response['content_type'] ?? 'n/a');
}

function fic_verify_einvoice_xml(string $companyId, string $docId): array
{
    $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}/e_invoice/xml_verify");

    if (($response['status'] ?? 0) === 404 || ($response['status'] ?? 0) === 405) {
        return ['ok', 'Verifica XML non disponibile su questo endpoint.'];
    }

    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
        $detail = fic_response_error_snippet($response);
        $validationResult = $response['json']['error']['validation_result'] ?? null;
        if (is_array($validationResult) && $validationResult) {
            $pairs = [];
            foreach ($validationResult as $field => $messages) {
                if (is_array($messages)) {
                    $pairs[] = $field . ': ' . implode(', ', array_map('strval', $messages));
                } else {
                    $pairs[] = $field . ': ' . (string)$messages;
                }
            }
            $extra = implode(' | ', $pairs);
            if ($extra !== '') {
                $detail .= ' - ' . $extra;
            }
        }
        return ['error', 'Verifica XML e-fattura fallita (HTTP ' . (int)($response['status'] ?? 0) . '). ' . $detail];
    }

    $success = $response['json']['data']['success'] ?? null;
    if ($success === false || $success === 0 || $success === '0') {
        $detail = '';
        $data = $response['json']['data'] ?? null;
        if (is_array($data)) {
            foreach (['message', 'error', 'reason', 'details'] as $k) {
                if (!empty($data[$k])) {
                    $detail = (string)$data[$k];
                    break;
                }
            }
            if ($detail === '' && isset($data['errors']) && is_array($data['errors'])) {
                $detail = implode(' | ', array_map('strval', $data['errors']));
            }
        }
        $msg = 'Verifica XML e-fattura non superata.';
        if ($detail !== '') {
            $msg .= ' ' . substr($detail, 0, 400);
        }
        return ['error', $msg];
    }

    return ['ok', 'Verifica XML e-fattura superata.'];
}

function fic_build_transform_einvoice_payload(string $companyId, string $originalDocId, array $fattura, array $cfg): array
{
    $queryPrimary = http_build_query([
        'original_document_id' => $originalDocId,
        'new_type' => 'invoice',
        'type' => 'invoice',
        'e_invoice' => 1,
        'transform_keep_copy' => 1,
    ]);
    $queryFallback = http_build_query([
        'original_document_id' => $originalDocId,
        'new_type' => 'invoice',
        'e_invoice' => 1,
        'transform_keep_copy' => 1,
    ]);

    $paths = [
        "/c/{$companyId}/issued_documents/transform?{$queryPrimary}",
        "/c/{$companyId}/issued_documents/transform?{$queryFallback}",
    ];

    $lastError = 'Transform non disponibile.';
    foreach ($paths as $path) {
        $response = fic_request('GET', $path);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            $lastError = 'Transform fallito (HTTP ' . (int)($response['status'] ?? 0) . '). ' . fic_response_error_snippet($response);
            continue;
        }

        $data = $response['json']['data'] ?? null;
        if (!is_array($data) || !$data) {
            $lastError = 'Transform riuscito ma payload data mancante.';
            continue;
        }

        $payload = ['data' => $data];
        $options = $response['json']['options'] ?? null;
        if (is_array($options) && $options) {
            $payload['options'] = $options;
        }

        $payload['data']['e_invoice'] = true;
        if (!isset($payload['data']['ei_data']) || !is_array($payload['data']['ei_data'])) {
            $payload['data']['ei_data'] = [];
        }

        if (trim((string)($payload['data']['ei_data']['payment_method'] ?? '')) === '') {
            $preCreateInfo = fic_get_pre_create_info($companyId, 'invoice');
            $payload['data']['ei_data']['payment_method'] = fic_pick_einvoice_payment_method_code($cfg, $preCreateInfo);
        }

        $entityPatch = fic_prepare_entity($fattura);
        if (!isset($payload['data']['entity']) || !is_array($payload['data']['entity']) || !$payload['data']['entity']) {
            $payload['data']['entity'] = $entityPatch;
        } else {
            $forceOverwrite = [
                'address_street' => true,
                'address_postal_code' => true,
                'address_city' => true,
                'address_province' => true,
                'country_iso' => true,
                'country' => true,
                'vat_number' => true,
                'ei_code' => true,
                'certified_email' => true,
            ];
            foreach ($entityPatch as $k => $v) {
                if (isset($forceOverwrite[$k]) || !array_key_exists($k, $payload['data']['entity']) || trim((string)$payload['data']['entity'][$k]) === '') {
                    $payload['data']['entity'][$k] = $v;
                }
            }
        }

        return ['ok', $payload, ''];
    }

    return ['error', null, $lastError];
}

function fic_create_transformed_einvoice(string $companyId, array $payload): array
{
    $response = fic_request('POST', "/c/{$companyId}/issued_documents", $payload);
    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
        return ['error', null, null, 'Creazione da transform fallita (HTTP ' . (int)($response['status'] ?? 0) . '). ' . fic_response_error_snippet($response)];
    }

    $docId = $response['json']['data']['id'] ?? null;
    $status = $response['json']['data']['status'] ?? null;
    if ($docId === null || $docId === '') {
        return ['error', null, null, 'Creazione da transform riuscita ma ID documento mancante nella risposta.'];
    }

    return ['ok', (string)$docId, $status, ''];
}

function fic_try_patch_document_entity_for_einvoice(string $companyId, string $docId, array $fattura, array $cfg): array
{
    $entityPatch = fic_prepare_entity($fattura);
    if (!$entityPatch) {
        return [false, 'Nessun campo entity disponibile per patch.'];
    }

    $preCreateInfo = fic_get_pre_create_info($companyId, 'invoice');
    $paymentMethod = fic_pick_einvoice_payment_method_code($cfg, $preCreateInfo);

    $payload = [
        'data' => [
            'entity' => $entityPatch,
            'e_invoice' => true,
            'ei_data' => [
                'payment_method' => $paymentMethod,
            ],
        ],
    ];

    $response = fic_request('PUT', "/c/{$companyId}/issued_documents/{$docId}", $payload);
    if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300) {
        return [true, 'Patch entity documento eseguita.'];
    }

    return [false, 'Patch entity non riuscita (HTTP ' . (int)($response['status'] ?? 0) . '). ' . fic_response_error_snippet($response)];
}
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/../../impostazioni/mail_smtp.php';
require_once __DIR__ . '/fic_audit.php';

/**
 * Assicura che la connessione MySQL sia attiva, riconnettendo se necessario
 * Evita "MySQL server has gone away" durante operazioni lunghe
 */
function fic_ensure_db_connection(PDO &$pdo): void
{
    try {
        $pdo->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        // Connessione persa: forza una nuova istanza, non il PDO in cache.
        unset($GLOBALS['_tdg_pdo']);
        if (function_exists('get_db')) {
            $pdo = get_db(true);
            return;
        }

        $pdo = require __DIR__ . '/../../../config/db.php';
    }
}

function fic_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function fic_to_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0;
    }

    $normalized = fic_lower(trim((string)$value));
    if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
        return false;
    }
    return true;
}

function fic_log_if_possible(?PDO $pdo, ?int $fatturaId, string $azione, string $esito, string $messaggio, array $meta = []): void
{
    if ($pdo instanceof PDO && function_exists('fatture_log')) {
        try {
            fic_ensure_db_connection($pdo);
            fatture_log($pdo, $fatturaId, $azione, $esito, $messaggio, $meta);
        } catch (Throwable $e) {
            // Il log non deve interrompere sync/rebuild.
        }
    }
}

function fic_trim_or_empty($value): string
{
    return trim((string)$value);
}

function fic_normalize_sdi_code($value): string
{
    $code = strtoupper(fic_trim_or_empty($value));
    if ($code === '') {
        return '';
    }
    return preg_match('/^[A-Z0-9]{7}$/', $code) ? $code : '';
}

function fic_normalize_pec($value): string
{
    $pec = strtolower(fic_trim_or_empty($value));
    if ($pec === '') {
        return '';
    }
    return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : '';
}

function fic_normalize_vat_number($value): string
{
    $vat = strtoupper(fic_trim_or_empty($value));
    if ($vat === '') {
        return '';
    }

    // Rimuove separatori comuni digitati a mano.
    $vat = preg_replace('/[\s\.\-\/]/', '', $vat);
    if ($vat === null) {
        return '';
    }

    // P.IVA italiana spesso inserita come IT12345678901.
    if (preg_match('/^IT(\d{11})$/', $vat, $m)) {
        return $m[1];
    }

    // Dato storico importato senza zero iniziale: accettalo solo se il checksum torna.
    if (preg_match('/^\d{10}$/', $vat)) {
        $paddedVat = '0' . $vat;
        if (fic_is_valid_italian_vat_number($paddedVat)) {
            return $paddedVat;
        }
    }

    // Formato italiano standard.
    if (preg_match('/^\d{11}$/', $vat)) {
        return $vat;
    }

    // Formato intracomunitario (es. DE123456789).
    if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,28}$/', $vat)) {
        return $vat;
    }

    return '';
}

function fic_is_valid_italian_vat_number(string $vat): bool
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

function fic_parse_address_parts($value): array
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

            if (preg_match('/^\d+[A-Za-z]?$/', $t)) {
                if ($street !== '') {
                    $street .= ' ' . $t;
                }
                continue;
            }

            if (preg_match('/^(\d{5})\s+(.+?)\s+\(?([A-Za-z]{2})\)?$/', $t, $m)) {
                if ($postalCode === '') {
                    $postalCode = (string)$m[1];
                }
                if ($city === '' || !fic_is_valid_city_name($city)) {
                    $city = trim((string)$m[2]);
                }
                if ($province === '') {
                    $province = strtoupper((string)$m[3]);
                }
                continue;
            }

            if (preg_match('/^(\d{5})\s+(.+)$/', $t, $m)) {
                if ($postalCode === '') {
                    $postalCode = (string)$m[1];
                }
                if ($city === '' || !fic_is_valid_city_name($city)) {
                    $city = trim((string)$m[2]);
                }
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

            if ($city === '' && preg_match('/^(.+?)\s+\(?([A-Za-z]{2})\)?$/', $t, $m)) {
                $city = trim((string)$m[1]);
                if ($province === '') {
                    $province = strtoupper((string)$m[2]);
                }
                continue;
            }

            if (!fic_is_valid_city_name($city) && preg_match('/^(.+?)\s+\(?([A-Za-z]{2})\)?$/', $t, $m)) {
                $city = trim((string)$m[1]);
                if ($province === '') {
                    $province = strtoupper((string)$m[2]);
                }
                continue;
            }

            if ($city === '' || !fic_is_valid_city_name($city)) {
                $city = $t;
            }
        }
    } else {
        $street = rtrim($raw, " ,.-");
    }

    if ($postalCode === '' && preg_match('/\b(\d{5})\b/', $raw, $m)) {
        $postalCode = (string)$m[1];
    }
    if ($province === '' && preg_match('/(?:\(|,\s*|\s+)([A-Za-z]{2})\)?\s*$/', trim($raw), $m)) {
        $province = strtoupper((string)$m[1]);
    }
    if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\s+(.+?)\s*\(?([A-Za-z]{2})\)?(?:\s*,|$)/i', $raw, $m)) {
        $city = trim((string)$m[1]);
        if ($province === '') {
            $province = strtoupper((string)$m[2]);
        }
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

function fic_is_valid_city_name(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    return (bool)preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u", $value);
}

function fic_einvoice_address_error(array $fattura): ?string
{
    $rawAddress = trim((string)($fattura['indirizzo'] ?? ''));
    if ($rawAddress === '') {
        return 'Dati cliente insufficienti per e-fattura: manca indirizzo completo cliente. Usa formato "Via Roma 10, 20100 Milano MI".';
    }

    $addressParts = fic_parse_address_parts($rawAddress);
    $missing = [];
    if (trim((string)($addressParts['address_street'] ?? '')) === '') {
        $missing[] = 'via e numero civico';
    }

    $postalCode = trim((string)($addressParts['address_postal_code'] ?? ''));
    if (!preg_match('/^\d{5}$/', $postalCode)) {
        $missing[] = 'CAP valido (5 cifre)';
    }

    $city = trim((string)($addressParts['address_city'] ?? ''));
    if (!fic_is_valid_city_name($city)) {
        $missing[] = 'Comune valido';
    }

    $province = trim((string)($addressParts['address_province'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $province)) {
        $missing[] = 'Provincia valida (sigla 2 lettere)';
    }

    if (!$missing) {
        return null;
    }

    return 'Dati cliente insufficienti per e-fattura: manca ' . implode(' + ', $missing) . '. Indirizzo atteso: "Via Roma 10, 20100 Milano MI".';
}

function fic_has_einvoice_minimum_data(array $fattura): bool
{
    $vatNumber = fic_normalize_vat_number($fattura['piva'] ?? '');
    $sdiCode = fic_normalize_sdi_code($fattura['codice_sdi'] ?? '');
    $pec = fic_normalize_pec($fattura['pec'] ?? '');

    if ($vatNumber === '') {
        return false;
    }

    return $sdiCode !== '' || $pec !== '';
}

function fic_update_einvoice_tracking(PDO $pdo, int $fatturaId, ?string $status, ?string $error, bool $setSentAt): void
{
    try {
        fic_ensure_db_connection($pdo);
        $sql = "UPDATE fatture SET " . ($setSentAt ? "ei_sent_at = NOW(), " : "") . "ei_status = :status, ei_error = :error WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':error' => $error,
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
        // Compatibilita con DB non ancora migrato (colonne ei_* non presenti).
    }
}

function fic_prepare_entity(array $fattura): array
{
    $name = $fattura['azienda'] ?? '';
    if ($name === '') {
        $name = $fattura['nome'] ?? '';
    }

    $safePec = fic_normalize_pec($fattura['pec'] ?? '');
    $safeSdi = fic_normalize_sdi_code($fattura['codice_sdi'] ?? '');
    $addressParts = fic_parse_address_parts($fattura['indirizzo'] ?? '');

    $normalizedVat = fic_normalize_vat_number($fattura['piva'] ?? '');
    if (preg_match('/^\d{11}$/', $normalizedVat) && !fic_is_valid_italian_vat_number($normalizedVat)) {
        // Evita di sovrascrivere su FIC una P.IVA italiana formalmente non valida.
        $normalizedVat = '';
    }

    return array_filter([
        'name' => $name,
        'vat_number' => $normalizedVat !== '' ? $normalizedVat : null,
        'address_street' => $addressParts['address_street'] !== '' ? $addressParts['address_street'] : ($fattura['indirizzo'] ?? null),
        'address_postal_code' => $addressParts['address_postal_code'] !== '' ? $addressParts['address_postal_code'] : null,
        'address_city' => $addressParts['address_city'] !== '' ? $addressParts['address_city'] : null,
        'address_province' => $addressParts['address_province'] !== '' ? $addressParts['address_province'] : null,
        'country_iso' => 'IT',
        'country' => 'Italia',
        'email' => $fattura['email'] ?? null,
        'phone' => $fattura['telefono'] ?? null,
        'certified_email' => $safePec !== '' ? $safePec : null,
        'ei_code' => $safeSdi !== '' ? $safeSdi : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    });
}

function fic_array_get(array $data, array $path, $default = null)
{
    $cursor = $data;
    foreach ($path as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

function fic_ensure_client_map_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

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
        // Se non e' possibile creare la tabella, il flusso continua senza mapping persistente.
    }

    $done = true;
}

function fic_get_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId): ?string
{
    if ($clienteId <= 0 || $companyId === '') {
        return null;
    }
    fic_ensure_client_map_table($pdo);

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

function fic_set_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId, string $entityId, string $source = 'sync'): void
{
    if ($clienteId <= 0 || $companyId === '' || trim($entityId) === '') {
        return;
    }
    fic_ensure_client_map_table($pdo);

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
            ':source' => substr($source, 0, 20),
        ]);
    } catch (Throwable $e) {
        // No-op se tabella non disponibile/non scrivibile.
    }
}

function fic_delete_mapped_entity_id(PDO $pdo, int $clienteId, string $companyId): void
{
    if ($clienteId <= 0 || $companyId === '') {
        return;
    }
    fic_ensure_client_map_table($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM fic_clienti_map WHERE cliente_id = :cid AND company_id = :company");
        $stmt->execute([
            ':cid' => $clienteId,
            ':company' => $companyId,
        ]);
    } catch (Throwable $e) {
        // no-op
    }
}

function fic_get_pre_create_info(string $companyId, string $docType): array
{
    static $cache = [];
    $cacheKey = $companyId . '|' . $docType;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $paths = [
        "/c/{$companyId}/issued_documents/pre_create_info?" . http_build_query(['type' => $docType]),
        "/c/{$companyId}/issued_documents/pre_create_info",
    ];

    foreach ($paths as $path) {
        try {
            $resp = fic_request('GET', $path);
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                continue;
            }
            $payload = $resp['json']['data'] ?? null;
            if (is_array($payload)) {
                $cache[$cacheKey] = $payload;
                return $payload;
            }
        } catch (Throwable $e) {
            // Prova il path successivo.
        }
    }

    $cache[$cacheKey] = [];
    return [];
}

function fic_pick_payment_method_id_from_precreate(array $preCreate): int
{
    $candidates = [
        fic_array_get($preCreate, ['default_payment_method', 'id']),
        fic_array_get($preCreate, ['payment_method', 'id']),
        fic_array_get($preCreate, ['default_payment_method_id']),
    ];

    foreach ($candidates as $candidate) {
        $id = (int)$candidate;
        if ($id > 0) {
            return $id;
        }
    }

    $lists = [
        fic_array_get($preCreate, ['payment_methods']),
        fic_array_get($preCreate, ['payment_methods_list']),
    ];

    foreach ($lists as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
    }

    return 0;
}

function fic_pick_einvoice_payment_method_code(array $cfg, array $preCreate): string
{
    $normalize = static function ($value): string {
        $code = strtoupper(trim((string)$value));
        return preg_match('/^MP\d{2}$/', $code) ? $code : '';
    };

    $candidates = [
        $cfg['ei_payment_method'] ?? null,
        $cfg['e_invoice_payment_method'] ?? null,
        fic_array_get($preCreate, ['default_ei_data', 'payment_method']),
        fic_array_get($preCreate, ['ei_data', 'payment_method']),
        fic_array_get($preCreate, ['default_e_invoice_data', 'payment_method']),
        fic_array_get($preCreate, ['e_invoice', 'payment_method']),
    ];

    foreach ($candidates as $candidate) {
        $code = $normalize($candidate);
        if ($code !== '') {
            return $code;
        }
    }

    $lists = [
        fic_array_get($preCreate, ['ei_payment_methods']),
        fic_array_get($preCreate, ['e_invoice_payment_methods']),
        fic_array_get($preCreate, ['payment_methods']),
        fic_array_get($preCreate, ['payment_methods_list']),
    ];

    foreach ($lists as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $normalize($row['code'] ?? ($row['value'] ?? null));
            if ($code !== '') {
                return $code;
            }
        }
    }

    // Default prudenziale: bonifico bancario
    return 'MP05';
}

function fic_pick_payment_account_id_from_precreate(array $preCreate): int
{
    $candidates = [
        fic_array_get($preCreate, ['default_payment_account', 'id']),
        fic_array_get($preCreate, ['payment_account', 'id']),
        fic_array_get($preCreate, ['default_payment_account_id']),
    ];

    foreach ($candidates as $candidate) {
        $id = (int)$candidate;
        if ($id > 0) {
            return $id;
        }
    }

    $lists = [
        fic_array_get($preCreate, ['payment_accounts']),
        fic_array_get($preCreate, ['payment_accounts_list']),
    ];

    foreach ($lists as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
    }

    return 0;
}

function fic_extract_clients_from_response(array $response): array
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

function fic_find_existing_client_entity_id(string $companyId, array $entity): ?string
{
    $vat = trim((string)($entity['vat_number'] ?? ''));
    $email = strtolower(trim((string)($entity['email'] ?? '')));
    $name = trim((string)($entity['name'] ?? ''));

    $queries = [];
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

    foreach ($queries as $query) {
        $path = "/c/{$companyId}/entities/clients?" . http_build_query($query);
        try {
            $resp = fic_request('GET', $path);
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                continue;
            }
            $items = fic_extract_clients_from_response($resp);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int)($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $itemVat = trim((string)($item['vat_number'] ?? ''));
                $itemEmail = strtolower(trim((string)($item['email'] ?? '')));
                $itemName = trim((string)($item['name'] ?? ''));

                if ($vat !== '' && $itemVat !== '' && strcasecmp($vat, $itemVat) === 0) {
                    return (string)$id;
                }
                if ($email !== '' && $itemEmail !== '' && strcasecmp($email, $itemEmail) === 0) {
                    return (string)$id;
                }
                if ($name !== '' && $itemName !== '' && fic_lower($name) === fic_lower($itemName)) {
                    return (string)$id;
                }
            }
        } catch (Throwable $e) {
            // Continua con query successiva.
        }
    }

    return null;
}

function fic_create_client_entity(string $companyId, array $entity): ?string
{
    $payload = $entity;
    if (trim((string)($payload['name'] ?? '')) === '') {
        return null;
    }

    try {
        $resp = fic_request('POST', "/c/{$companyId}/entities/clients", ['data' => $payload]);
        if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
            return null;
        }
        $id = (int)($resp['json']['data']['id'] ?? 0);
        return $id > 0 ? (string)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fic_update_client_entity(string $companyId, string $entityId, array $entity): bool
{
    if ($companyId === '' || trim($entityId) === '') {
        return false;
    }

    if (!$entity) {
        return false;
    }

    if (trim((string)($entity['name'] ?? '')) === '') {
        return false;
    }

    try {
        $resp = fic_request('PUT', "/c/{$companyId}/entities/clients/{$entityId}", ['data' => $entity]);
        return ($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300;
    } catch (Throwable $e) {
        return false;
    }
}

function fic_client_entity_exists(string $companyId, string $entityId): bool
{
    if (trim($entityId) === '') {
        return false;
    }
    try {
        $resp = fic_request('GET', "/c/{$companyId}/entities/clients/{$entityId}");
        return ($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300;
    } catch (Throwable $e) {
        return true;
    }
}

function fic_get_or_create_client_entity_id(PDO $pdo, string $companyId, array $fattura): ?string
{
    static $runtimeCache = [];

    $clienteId = (int)($fattura['cliente_id'] ?? 0);
    if ($clienteId <= 0 || $companyId === '') {
        return null;
    }

    $cacheKey = $companyId . '|' . $clienteId;
    if (array_key_exists($cacheKey, $runtimeCache)) {
        return $runtimeCache[$cacheKey] !== '' ? $runtimeCache[$cacheKey] : null;
    }

    $entity = fic_prepare_entity($fattura);
    if (trim((string)($entity['name'] ?? '')) === '') {
        $runtimeCache[$cacheKey] = '';
        return null;
    }

    $mapped = fic_get_mapped_entity_id($pdo, $clienteId, $companyId);
    if ($mapped !== null) {
        if (!fic_client_entity_exists($companyId, $mapped)) {
            fic_delete_mapped_entity_id($pdo, $clienteId, $companyId);
            $mapped = null;
        }
    }
    if ($mapped !== null) {
        fic_update_client_entity($companyId, $mapped, $entity);
        $runtimeCache[$cacheKey] = $mapped;
        return $mapped;
    }

    $existing = fic_find_existing_client_entity_id($companyId, $entity);
    if ($existing !== null) {
        fic_update_client_entity($companyId, $existing, $entity);
        fic_set_mapped_entity_id($pdo, $clienteId, $companyId, $existing, 'lookup');
        $runtimeCache[$cacheKey] = $existing;
        return $existing;
    }

    $created = fic_create_client_entity($companyId, $entity);
    if ($created !== null) {
        fic_set_mapped_entity_id($pdo, $clienteId, $companyId, $created, 'create');
        $runtimeCache[$cacheKey] = $created;
        return $created;
    }

    $runtimeCache[$cacheKey] = '';
    return null;
}

function fic_resolve_vat_type_id(array $cfg, string $companyId): int
{
    static $cache = [];

    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }

    if (array_key_exists('vat_type_id', $cfg) && is_numeric($cfg['vat_type_id'])) {
        $configured = (int)$cfg['vat_type_id'];
        $cache[$companyId] = $configured;
        return $configured;
    }

    try {
        $resp = fic_request('GET', "/c/{$companyId}/info/vat_types");
        if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
            $cache[$companyId] = 0;
            return 0;
        }

        $data = $resp['json']['data'] ?? null;
        $vatTypes = [];
        if (is_array($data) && isset($data['vat_types']) && is_array($data['vat_types'])) {
            $vatTypes = $data['vat_types'];
        } elseif (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            $vatTypes = $data;
        }

        if (!$vatTypes) {
            $cache[$companyId] = 0;
            return 0;
        }

        $first = 0;
        $prefer22 = 0;
        $preferDefault = 0;
        foreach ($vatTypes as $v) {
            $id = (int)($v['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if ($first === 0) {
                $first = $id;
            }

            $isDefault = !empty($v['is_default']) || !empty($v['default']);
            if ($isDefault && $preferDefault === 0) {
                $preferDefault = $id;
            }

            $value = (float)($v['value'] ?? $v['vat_percentage'] ?? -1);
            if ($prefer22 === 0 && $value >= 21.9 && $value <= 22.1) {
                $prefer22 = $id;
            }
        }

        $resolved = $preferDefault > 0 ? $preferDefault : ($prefer22 > 0 ? $prefer22 : $first);
        $cache[$companyId] = (int)$resolved;
        return (int)$resolved;
    } catch (Throwable $e) {
        $cache[$companyId] = 0;
        return 0;
    }
}

function fic_resolve_vat_type_value(array $cfg, string $companyId, int $vatTypeId): float
{
    static $cache = [];

    $cacheKey = $companyId . '|' . $vatTypeId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $resp = fic_request('GET', "/c/{$companyId}/info/vat_types");
        if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
            $cache[$cacheKey] = 0.0;
            return 0.0;
        }

        $data = $resp['json']['data'] ?? null;
        $vatTypes = [];
        if (is_array($data) && isset($data['vat_types']) && is_array($data['vat_types'])) {
            $vatTypes = $data['vat_types'];
        } elseif (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            $vatTypes = $data;
        }

        foreach ($vatTypes as $v) {
            $id = (int)($v['id'] ?? 0);
            if ($id !== $vatTypeId) {
                continue;
            }
            $value = (float)($v['value'] ?? $v['vat_percentage'] ?? 0);
            if ($value < 0) {
                $value = 0;
            }
            $cache[$cacheKey] = $value;
            return $value;
        }
    } catch (Throwable $e) {
        // no-op
    }

    $cache[$cacheKey] = 0.0;
    return 0.0;
}

function fic_is_stripe_origin_invoice(array $fattura): bool
{
    $stripePaymentIntent = trim((string)($fattura['stripe_payment_intent_id'] ?? ''));
    if ($stripePaymentIntent !== '') {
        return true;
    }

    $metodo = fic_lower(trim((string)($fattura['metodo_pagamento'] ?? '')));
    if ($metodo !== '' && strpos($metodo, 'stripe') !== false) {
        return true;
    }

    return false;
}

function fic_invoice_prices_include_vat(array $fattura, array $cfg): bool
{
    // Regola business: tutti i pagamenti sono incassati con IVA inclusa.
    // Invia sempre importi lordi a Fatture in Cloud (gross_price).
    return true;
}

function fic_compact_single_line_text($value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

function fic_build_invoice_label(array $fattura, int $maxLen = 190): string
{
    $numero = fic_compact_single_line_text((string)($fattura['numero'] ?? ''));
    if ($numero === '') {
        $numero = 'SENZA-NUMERO';
    }

    $azienda = fic_compact_single_line_text((string)($fattura['azienda'] ?? ''));
    if ($azienda === '') {
        $azienda = fic_compact_single_line_text((string)($fattura['nome'] ?? ''));
    }

    $label = 'Fattura ' . $numero;
    if ($azienda !== '') {
        $label .= ' - ' . $azienda;
    }

    $stripePaymentIntent = trim((string)($fattura['stripe_payment_intent_id'] ?? ''));
    if ($stripePaymentIntent !== '') {
        $compactIntent = preg_replace('/[^A-Za-z0-9]/', '', $stripePaymentIntent) ?? '';
        if ($compactIntent !== '') {
            $label .= ' [PI ' . substr($compactIntent, -10) . ']';
        }
    }

    if ($maxLen <= 0) {
        return $label;
    }
    if (function_exists('mb_substr')) {
        return (string)mb_substr($label, 0, $maxLen, 'UTF-8');
    }
    return substr($label, 0, $maxLen);
}

function fic_auto_renumber_value(array $fattura): string
{
    $id = (int)($fattura['id'] ?? 0);
    $dateRaw = trim((string)($fattura['data_pagamento'] ?? $fattura['data_emissione'] ?? ''));
    $ts = strtotime($dateRaw);
    if ($ts === false) {
        $ts = time();
    }
    $day = date('Ymd', $ts);

    if ($id > 0) {
        return sprintf('STR-INV-%s-%06d', $day, $id);
    }

    return sprintf('STR-INV-%s-%s', $day, date('His', $ts));
}

function fic_generate_duplicate_retry_number(PDO $pdo, array $fattura): ?string
{
    $id = (int)($fattura['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    fic_ensure_db_connection($pdo);

    $stamp = date('Ymd-His');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'STR-INV-' . $stamp . '-R' . $id;
        if ($attempt > 0) {
            $candidate .= '-' . $attempt;
        }

        $check = $pdo->prepare('SELECT id FROM fatture WHERE numero = :numero AND id <> :id LIMIT 1');
        $check->execute([
            ':numero' => $candidate,
            ':id' => $id,
        ]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
    }

    return 'STR-INV-' . date('Ymd-His') . '-R' . $id . '-' . substr((string)microtime(true), -6);
}

function fic_try_auto_renumber_after_duplicate(PDO $pdo, array $fattura): ?string
{
    $id = (int)($fattura['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $oldNumber = trim((string)($fattura['numero'] ?? ''));
    $newNumber = fic_generate_duplicate_retry_number($pdo, $fattura);
    if ($newNumber === null || $newNumber === '') {
        $newNumber = fic_auto_renumber_value($fattura);
    }
    if ($newNumber === '') {
        return null;
    }

    if ($newNumber === $oldNumber) {
        $newNumber = $newNumber . '-R' . date('His');
    }

    try {
        fic_ensure_db_connection($pdo);
        $upd = $pdo->prepare("UPDATE fatture
                              SET numero = :numero,
                                  fic_status = NULL,
                                  ei_status = NULL,
                                  ei_error = NULL,
                                  fic_synced_at = NOW()
                              WHERE id = :id");
        $upd->execute([
            ':numero' => $newNumber,
            ':id' => $id,
        ]);
        return $newNumber;
    } catch (Throwable $e) {
        return null;
    }
}

function fic_is_generic_item_description(string $description, array $fattura): bool
{
    $value = fic_lower(fic_compact_single_line_text($description));
    if ($value === '' || $value === 'riga') {
        return true;
    }

    $numero = fic_lower(trim((string)($fattura['numero'] ?? '')));
    if ($numero !== '') {
        if ($value === 'fattura ' . $numero || $value === 'fattura #' . $numero) {
            return true;
        }
    }

    return in_array($value, [
        'fattura',
        'fattura servizi',
        'pagamento stripe',
        'fattura da pagamento stripe',
        'invoice',
    ], true);
}

function fic_resolve_stripe_payment_causale(PDO $pdo, array $fattura): string
{
    if (!fic_is_stripe_origin_invoice($fattura)) {
        return '';
    }

    $stripePaymentIntent = trim((string)($fattura['stripe_payment_intent_id'] ?? ''));
    if ($stripePaymentIntent === '') {
        return '';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT descrizione
            FROM pagamenti
            WHERE stripe_id = :sid
              AND descrizione IS NOT NULL
              AND TRIM(descrizione) <> ''
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':sid' => $stripePaymentIntent]);
        $causale = fic_compact_single_line_text((string)($stmt->fetchColumn() ?: ''));
        if ($causale !== '') {
            return $causale;
        }
    } catch (Throwable $e) {
        // no-op
    }

    return '';
}

function fic_build_items(array $fattura, array $righe, array $cfg): array
{
    $hasVatId = array_key_exists('vat_type_id', $cfg) && is_numeric($cfg['vat_type_id']);
    $vatId = (int)($cfg['vat_type_id'] ?? 0);
    $fallbackVatRate = (float)($cfg['vat_type_value'] ?? 0);
    $pricesIncludeVat = fic_invoice_prices_include_vat($fattura, $cfg);
    $isStripeInvoice = fic_is_stripe_origin_invoice($fattura);
    $stripeCausale = fic_compact_single_line_text((string)($cfg['_stripe_causale'] ?? ''));
    $items = [];
    if ($righe) {
        foreach ($righe as $r) {
            $qty = (float)($r['quantita'] ?? 1);
            if ($qty <= 0) {
                $qty = 1.0;
            }

            $lineVatRate = (float)($r['iva'] ?? 0);
            $effectiveVatRate = $lineVatRate > 0 ? $lineVatRate : $fallbackVatRate;

            $unitPrice = (float)($r['prezzo_unitario'] ?? 0);
            $lineTotal = (float)($r['totale_riga'] ?? 0);
            $baseUnitPrice = $unitPrice;
            if ($lineTotal > 0) {
                $baseUnitPrice = $lineTotal / $qty;
            }
            $itemName = fic_compact_single_line_text($r['descrizione'] ?? '');
            if ($isStripeInvoice && $stripeCausale !== '' && fic_is_generic_item_description($itemName, $fattura)) {
                $itemName = $stripeCausale;
            }
            if ($itemName === '') {
                $itemName = 'Riga';
            }

            $item = [
                'name' => $itemName,
                'qty' => $qty,
            ];
            if ($pricesIncludeVat) {
                $item['gross_price'] = round($baseUnitPrice, 6);
            } else {
                $item['net_price'] = round($baseUnitPrice, 6);
            }
            if ($hasVatId && $vatId >= 0) {
                $item['vat'] = ['id' => $vatId];
            }
            $items[] = $item;
        }
    } else {
        $qty = 1.0;
        $grossOrNet = (float)($fattura['totale'] ?? 0);
        $netPrice = $grossOrNet;
        if ($pricesIncludeVat && $fallbackVatRate > 0) {
            $netPrice = $grossOrNet / (1 + ($fallbackVatRate / 100));
        }

        $item = [
            'name' => $stripeCausale !== '' ? $stripeCausale : fic_build_invoice_label($fattura, 150),
            'qty' => $qty,
        ];
        if ($pricesIncludeVat) {
            $item['gross_price'] = round($grossOrNet, 6);
        } else {
            $item['net_price'] = round($netPrice, 6);
        }
        if ($hasVatId && $vatId >= 0) {
            $item['vat'] = ['id' => $vatId];
        }
        $items[] = $item;
    }
    return $items;
}

function fic_response_has_duplicate_number(array $response): bool
{
    $body = (string)($response['body'] ?? '');
    $message = '';
    if (!empty($response['json']['error']['message'])) {
        $message = (string)$response['json']['error']['message'];
    }

    $haystack = fic_lower($message . ' ' . $body);
    return strpos($haystack, 'stesso numero') !== false
        || strpos($haystack, 'same number') !== false
        || strpos($haystack, 'numero inferiore') !== false
        || strpos($haystack, 'numero superiore') !== false;
}

function fic_get_document_status(string $companyId, string $docId): ?array
{
    try {
        $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }
        
        $doc = $response['json']['data'] ?? null;
        if (is_array($doc)) {
            return [
                'id' => $doc['id'] ?? null,
                'number' => $doc['number'] ?? null,
                'status' => $doc['status'] ?? null,
                'visible_subject' => $doc['visible_subject'] ?? null,
                'e_invoice' => $doc['e_invoice'] ?? null,
                'ei_status' => $doc['ei_status'] ?? null,
            ];
        }
        
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function fic_doc_matches_local_invoice(array $doc, array $fattura): bool
{
    $localNumber = trim((string)($fattura['numero'] ?? ''));
    if ($localNumber === '') {
        return true;
    }

    $docSubject = fic_lower(trim((string)($doc['visible_subject'] ?? '')));
    if ($docSubject !== '' && strpos($docSubject, fic_lower($localNumber)) !== false) {
        return true;
    }

    if (preg_match('/^\d+$/', $localNumber)) {
        $docNumberRaw = trim((string)($doc['number'] ?? ''));
        if ($docNumberRaw !== '' && ctype_digit($docNumberRaw)) {
            return ((int)$docNumberRaw) === ((int)$localNumber);
        }
    }

    return false;
}

/**
 * Pubblica un documento se e' in stato draft
 */
function fic_publish_document_if_draft_ex(string $companyId, string $docId, ?array $fattura = null, ?string $docType = null, bool $skipInitialStatusCheck = false): array
{
    $currentDocId = trim($docId);
    if ($currentDocId === '') {
        return [
            'ok' => false,
            'doc_id' => '',
            'message' => 'ID documento FIC mancante.',
        ];
    }

    try {
        $docStatus = $skipInitialStatusCheck ? ['status' => 'draft'] : fic_get_document_status($companyId, $currentDocId);
        $relinked = false;

        // Fallback robusto: se l'ID locale non e' raggiungibile, prova a ricollegare per numero/fingerprint.
        if ($docStatus === null && is_array($fattura)) {
            $effectiveDocType = trim((string)($docType ?? ($fattura['document_type'] ?? 'invoice')));
            if ($effectiveDocType === '') {
                $effectiveDocType = 'invoice';
            }
            [$foundDocId] = fic_find_existing_document_by_number($companyId, $effectiveDocType, $fattura, true);
            if ($foundDocId !== null && (string)$foundDocId !== $currentDocId) {
                $currentDocId = (string)$foundDocId;
                $docStatus = fic_get_document_status($companyId, $currentDocId);
                $relinked = true;
            }
        }

        if ($docStatus === null) {
            return [
                'ok' => false,
                'doc_id' => $currentDocId,
                'message' => 'Documento FIC non raggiungibile via API (docId ' . $currentDocId . ').',
            ];
        }

        $status = fic_lower((string)($docStatus['status'] ?? ''));

        // Se gia' pubblicato, ok.
        if ($status !== '' && $status !== 'draft') {
            $message = 'Documento gia pubblicato (status ' . $status . ').';
            if ($relinked) {
                $message = 'Documento ricollegato su FIC (docId ' . $currentDocId . ') e gia pubblicato (status ' . $status . ').';
            }
            return [
                'ok' => true,
                'doc_id' => $currentDocId,
                'message' => $message,
            ];
        }

        // Pubblica il documento.
        $response = fic_request('POST', "/c/{$companyId}/issued_documents/{$currentDocId}/publish", [
            'data' => []
        ]);
        $httpStatus = (int)($response['status'] ?? 0);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            // 404/405 sulla publish: spesso il documento e' gia' pubblicato o non accetta piu' la publish.
            // Ricontrolla lo stato: se non e' draft, considera ok e prosegui.
            if (in_array($httpStatus, [404, 405, 409, 422], true)) {
                $recheck = fic_get_document_status($companyId, $currentDocId);
                if ($recheck !== null) {
                    $recheckStatus = fic_lower((string)($recheck['status'] ?? ''));
                    if ($recheckStatus !== '' && $recheckStatus !== 'draft') {
                        return [
                            'ok' => true,
                            'doc_id' => $currentDocId,
                            'message' => 'Publish non necessaria: documento gia in stato ' . $recheckStatus . ' (HTTP ' . $httpStatus . ' sulla publish ignorato).',
                        ];
                    }
                }
            }

            $apiMessage = trim((string)($response['json']['error']['message'] ?? ''));
            if ($apiMessage === '') {
                $apiMessage = trim((string)($response['body'] ?? ''));
            }
            if ($apiMessage === '') {
                $apiMessage = 'nessun dettaglio restituito da FIC';
            }
            $apiMessage = preg_replace('/\s+/', ' ', $apiMessage) ?? $apiMessage;
            $apiMessage = substr($apiMessage, 0, 220);

            return [
                'ok' => false,
                'doc_id' => $currentDocId,
                'message' => 'Publish FIC fallita (HTTP ' . $httpStatus . '): ' . $apiMessage,
            ];
        }

        // Verifica post-publish (FIC puo' essere eventualmente consistente).
        $finalStatus = '';
        for ($i = 0; $i < 3; $i++) {
            $post = fic_get_document_status($companyId, $currentDocId);
            if ($post !== null) {
                $finalStatus = fic_lower((string)($post['status'] ?? ''));
                if ($finalStatus !== '' && $finalStatus !== 'draft') {
                    $message = 'Publish FIC completata (status ' . $finalStatus . ').';
                    if ($relinked) {
                        $message = 'Documento ricollegato e publish FIC completata (status ' . $finalStatus . ').';
                    }
                    return [
                        'ok' => true,
                        'doc_id' => $currentDocId,
                        'message' => $message,
                    ];
                }
            }
            if ($i < 2) {
                usleep(350 * 1000);
            }
        }

        if ($finalStatus === 'draft') {
            return [
                'ok' => false,
                'doc_id' => $currentDocId,
                'message' => 'Publish FIC chiamata ma documento ancora draft.',
            ];
        }

        $message = 'Publish FIC completata.';
        if ($relinked) {
            $message = 'Documento ricollegato e publish FIC completata.';
        }
        return [
            'ok' => true,
            'doc_id' => $currentDocId,
            'message' => $message,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'doc_id' => $currentDocId,
            'message' => 'Eccezione publish FIC: ' . substr((string)$e->getMessage(), 0, 220),
        ];
    }
}

function fic_publish_document_if_draft(string $companyId, string $docId): bool
{
    $result = fic_publish_document_if_draft_ex($companyId, $docId, null, null);
    return !empty($result['ok']);
}

function fic_list_documents_page(string $companyId, string $docType, int $page): array
{
    $query = http_build_query([
        'type' => $docType,
        'page' => $page,
        'per_page' => 100,
    ]);

    return fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
}

function fic_list_documents_page_any_type(string $companyId, int $page): array
{
    $query = http_build_query([
        'page' => $page,
        'per_page' => 100,
    ]);

    return fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
}

function fic_date_only(?string $value): string
{
    $v = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v, $m)) {
        return $m[0];
    }
    return '';
}

function fic_numeric_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    $normalized = str_replace(',', '.', (string)$value);
    return is_numeric($normalized) ? (float)$normalized : null;
}

function fic_integer_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_numeric($value)) {
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }

    $normalized = trim((string)$value);
    if ($normalized !== '' && ctype_digit($normalized)) {
        $intValue = (int)$normalized;
        return $intValue > 0 ? $intValue : null;
    }

    return null;
}

function fic_doc_matches_target_numeration(array $doc, string $targetNumeration): bool
{
    $targetNumeration = trim($targetNumeration);
    if ($targetNumeration === '') {
        return true;
    }

    $targetNorm = fic_normalize_doc_number_token($targetNumeration);
    if ($targetNorm === '') {
        return true;
    }

    $fields = [
        $doc['numeration'] ?? null,
        $doc['full_number'] ?? null,
        $doc['formatted_number'] ?? null,
        $doc['number_formatted'] ?? null,
    ];

    foreach ($fields as $field) {
        $value = trim((string)$field);
        if ($value === '') {
            continue;
        }

        $valueNorm = fic_normalize_doc_number_token($value);
        if ($valueNorm === '') {
            continue;
        }

        if (strpos($valueNorm, $targetNorm) !== false) {
            return true;
        }
    }

    return false;
}

function fic_pick_chronological_number(string $companyId, string $docType, array $fattura, int $maxPages = 15, array $skipNumbers = [], string $targetNumeration = ''): ?int
{
    $targetDate = fic_date_only((string)($fattura['data_emissione'] ?? $fattura['data_pagamento'] ?? ''));
    if ($targetDate === '') {
        return null;
    }

    $targetNumeration = trim($targetNumeration);

    $lowerBound = 0;
    $upperBound = null;
    $pagesRead = 0;
    $occupiedNumbers = [];

    for ($page = 1; $page <= $maxPages; $page++) {
        $pagesRead++;
        $response = fic_list_documents_page($companyId, $docType, $page);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            break;
        }

        $docs = $response['json']['data'] ?? [];
        if (!is_array($docs) || !$docs) {
            break;
        }

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            if ($targetNumeration !== '' && !fic_doc_matches_target_numeration($doc, $targetNumeration)) {
                continue;
            }

            $docDate = fic_date_only((string)($doc['date'] ?? ''));
            $docNumber = fic_integer_or_null($doc['number'] ?? null);
            if ($docDate === '' || $docNumber === null) {
                continue;
            }

            $occupiedNumbers[$docNumber] = true;

            if ($docDate <= $targetDate) {
                if ($docNumber > $lowerBound) {
                    $lowerBound = $docNumber;
                }
                continue;
            }

            if ($upperBound === null || $docNumber < $upperBound) {
                $upperBound = $docNumber;
            }
        }

        if (count($docs) < 100) {
            break;
        }
    }

    if ($upperBound !== null) {
        for ($candidate = $lowerBound + 1; $candidate < $upperBound; $candidate++) {
            if (!isset($occupiedNumbers[$candidate]) && !isset($skipNumbers[$candidate])) {
                return $candidate;
            }
        }

        if ($lowerBound === 0 && $upperBound > 1) {
            for ($candidate = 1; $candidate < $upperBound; $candidate++) {
                if (!isset($occupiedNumbers[$candidate]) && !isset($skipNumbers[$candidate])) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    if ($lowerBound > 0) {
        $candidate = $lowerBound + 1;
        while (isset($occupiedNumbers[$candidate]) || isset($skipNumbers[$candidate])) {
            $candidate++;
        }
        return $candidate;
    }

    if ($pagesRead > 0) {
        $candidate = 1;
        while (isset($occupiedNumbers[$candidate]) || isset($skipNumbers[$candidate])) {
            $candidate++;
        }
        return $candidate;
    }

    return null;
}

function fic_duplicate_recovery_numeration(array $cfg, array $fattura = []): string
{
    $target = trim((string)($fattura['fic_target_numeration'] ?? ''));
    if ($target !== '') {
        return $target;
    }

    $forcedNumber = trim((string)($fattura['fic_forced_number'] ?? ''));
    if ($forcedNumber === '') {
        return '';
    }

    $configured = trim((string)($cfg['duplicate_recovery_numeration'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    $dateToken = fic_date_only((string)($fattura['data_emissione'] ?? $fattura['data_pagamento'] ?? ''));
    if ($dateToken === '') {
        $dateToken = date('Y-m-d');
    }

    $seriesDate = preg_replace('/[^0-9]/', '', $dateToken) ?? '';
    if (!preg_match('/^\d{8}$/', $seriesDate)) {
        $seriesDate = date('Ymd');
    }

    return 'SR' . $seriesDate;
}

function fic_build_smart_recovery_number(array $fattura): ?int
{
    $invoiceId = (int)($fattura['id'] ?? 0);
    if ($invoiceId <= 0) {
        return null;
    }

    $dateRaw = trim((string)($fattura['data_pagamento'] ?? $fattura['data_emissione'] ?? ''));
    $ts = strtotime($dateRaw);
    if ($ts === false) {
        $ts = time();
    }

    $secondsOfDay = (int)date('G', $ts) * 3600 + (int)date('i', $ts) * 60 + (int)date('s', $ts);
    $candidate = (($secondsOfDay + 1) * 1000) + ($invoiceId % 1000);

    if ($candidate <= 0 || $candidate > 2147483646) {
        return null;
    }

    return $candidate;
}

function fic_normalize_doc_number_token(string $value): string
{
    $upper = strtoupper(trim($value));
    if ($upper === '') {
        return '';
    }
    return preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
}

function fic_split_doc_token_tail(string $token): array
{
    if (preg_match('/^(.*?)(\d+)$/', $token, $m)) {
        return [$m[1], $m[2]];
    }
    return [$token, ''];
}

function fic_doc_matches_local_number(array $doc, string $numeroFattura, bool $isPureNumeric): bool
{
    $localRaw = trim($numeroFattura);
    if ($localRaw === '') {
        return false;
    }
    $localNorm = fic_normalize_doc_number_token($localRaw);

    $docSubject = (string)($doc['visible_subject'] ?? '');
    if ($docSubject !== '' && stripos($docSubject, $localRaw) !== false) {
        return true;
    }
    if ($docSubject !== '') {
        $subjectNorm = fic_normalize_doc_number_token($docSubject);
        if ($localNorm !== '' && $subjectNorm !== '' && strlen($localNorm) >= 8 && strpos($subjectNorm, $localNorm) !== false) {
            return true;
        }
    }

    $docNumberRaw = (string)($doc['number'] ?? '');
    if ($isPureNumeric && $docNumberRaw !== '' && ctype_digit($docNumberRaw) && (int)$docNumberRaw === (int)$localRaw) {
        return true;
    }

    $docNumeration = trim((string)($doc['numeration'] ?? ''));
    $fullCandidates = [];
    if ($docNumeration !== '' || $docNumberRaw !== '') {
        $fullCandidates[] = trim($docNumeration . $docNumberRaw);
    }
    foreach (['full_number', 'formatted_number', 'number_formatted', 'number'] as $field) {
        if (!empty($doc[$field])) {
            $fullCandidates[] = (string)$doc[$field];
        }
    }

    foreach ($fullCandidates as $candidate) {
        $candidateNorm = fic_normalize_doc_number_token((string)$candidate);
        if ($candidateNorm === '' || $localNorm === '') {
            continue;
        }
        if ($candidateNorm === $localNorm) {
            return true;
        }
        // Evita match deboli (es. candidate "7" che matcha qualsiasi numero lungo contenente "7").
        // Consentiamo il contains solo quando entrambi i token sono significativi.
        if (strlen($candidateNorm) >= 8 && strlen($localNorm) >= 8 &&
            (strpos($candidateNorm, $localNorm) !== false || strpos($localNorm, $candidateNorm) !== false)) {
            return true;
        }

        // Match tollerante per suffisso numerico con zeri iniziali:
        // STR-INV-20260201-006 <-> STR-INV-20260201-6
        [$lp, $ln] = fic_split_doc_token_tail($localNorm);
        [$cp, $cn] = fic_split_doc_token_tail($candidateNorm);
        if ($lp !== '' && $cp !== '' && $ln !== '' && $cn !== '' &&
            strlen($lp) >= 8 && $lp === $cp && ((int)$ln === (int)$cn)) {
            return true;
        }
    }

    return false;
}

function fic_doc_matches_fingerprint(array $doc, array $fattura): bool
{
    // Se abbiamo gia' un numero fattura locale, evitiamo match "deboli" su fingerprint:
    // su clienti con stesso importo/data possono causare collegamenti errati.
    $localNumber = trim((string)($fattura['numero'] ?? ''));
    if ($localNumber !== '') {
        return false;
    }

    $localDate = fic_date_only((string)($fattura['data_emissione'] ?? ''));
    $localVat = strtoupper(trim((string)($fattura['piva'] ?? '')));
    $localEmail = strtolower(trim((string)($fattura['email'] ?? '')));
    $localName = fic_lower(trim((string)($fattura['azienda'] ?? $fattura['nome'] ?? '')));
    $localTotal = fic_numeric_or_null($fattura['totale'] ?? null);

    // Fingerprint solo "forte": richiede almeno data e totale locali.
    if ($localDate === '' || $localTotal === null) {
        return false;
    }

    $docDate = fic_date_only((string)($doc['date'] ?? $doc['issued_date'] ?? ''));
    // Se manca la data del documento FIC, non lo consideriamo match affidabile.
    if ($docDate === '' || $localDate !== $docDate) {
        return false;
    }

    $docEntity = is_array($doc['entity'] ?? null) ? $doc['entity'] : [];
    $docVat = strtoupper(trim((string)($docEntity['vat_number'] ?? $doc['entity_vat_number'] ?? '')));
    $docEmail = strtolower(trim((string)($docEntity['email'] ?? $doc['entity_email'] ?? '')));
    $docName = fic_lower(trim((string)($docEntity['name'] ?? $doc['entity_name'] ?? '')));

    $entityMatched = false;
    if ($localVat !== '' && $docVat !== '' && $localVat === $docVat) {
        $entityMatched = true;
    } elseif ($localEmail !== '' && $docEmail !== '' && $localEmail === $docEmail) {
        $entityMatched = true;
    } elseif ($localName !== '' && $docName !== '' && $localName === $docName) {
        $entityMatched = true;
    }

    if (!$entityMatched) {
        return false;
    }

    $docAmountCandidates = [
        fic_numeric_or_null($doc['amount_gross'] ?? null),
        fic_numeric_or_null($doc['gross_amount'] ?? null),
        fic_numeric_or_null($doc['amount_net'] ?? null),
        fic_numeric_or_null($doc['net_amount'] ?? null),
        fic_numeric_or_null($doc['amount_due'] ?? null),
        fic_numeric_or_null($doc['total'] ?? null),
    ];

    foreach ($docAmountCandidates as $docAmount) {
        if ($docAmount === null) {
            continue;
        }
        if (abs($docAmount - $localTotal) <= 0.02) {
            return true;
        }
    }

    // Se non abbiamo un match importo affidabile, non colleghiamo.
    return false;
}

function fic_extract_documents(array $response): array
{
    if (empty($response['json']) || !is_array($response['json'])) {
        return [];
    }

    $data = $response['json']['data'] ?? null;
    if (is_array($data)) {
        if (isset($data['items_list']) && is_array($data['items_list'])) {
            return $data['items_list'];
        }

        $isList = array_keys($data) === range(0, count($data) - 1);
        if ($isList) {
            return $data;
        }
    }

    return [];
}

function fic_find_existing_document_by_number(string $companyId, string $docType, array $fattura, bool $allowFingerprint = false): array
{
    $numeroFattura = trim((string)($fattura['numero'] ?? ''));
    if ($numeroFattura === '') {
        return [null, null];
    }
    $isPureNumeric = preg_match('/^\d+$/', $numeroFattura) === 1;

    // Strategia 1: ricerca con filtro "q" (testo libero) per trovare direttamente il documento
    $searchTerms = [$numeroFattura];
    if ($isPureNumeric) {
        $searchTerms[] = (string)((int)$numeroFattura);
    }

    foreach ($searchTerms as $term) {
        $maxQPages = 20;
        $lastPage = 1;
        for ($page = 1; $page <= $maxQPages; $page++) {
            $query = http_build_query([
                'type' => $docType,
                'q' => $term,
                'per_page' => 100,
                'page' => $page,
            ]);
            try {
                $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    break;
                }
                $documents = fic_extract_documents($response);
                foreach ($documents as $doc) {
                    $docId = $doc['id'] ?? null;
                    $docStatus = $doc['status'] ?? null;
                    if (!$docId) {
                        continue;
                    }
                    if (fic_doc_matches_local_number((array)$doc, $numeroFattura, $isPureNumeric)) {
                        return [(string)$docId, $docStatus];
                    }
                    // Match per fingerprint documento (cliente + data + importo)
                    if ($allowFingerprint && fic_doc_matches_fingerprint((array)$doc, $fattura)) {
                        return [(string)$docId, $docStatus];
                    }
                }

                $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
                if ($page >= $lastPage) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    // Strategia 1b: ricerca testuale senza filtro type (cattura duplicati cross-tipo).
    foreach ($searchTerms as $term) {
        $maxQPages = 20;
        for ($page = 1; $page <= $maxQPages; $page++) {
            $query = http_build_query([
                'q' => $term,
                'per_page' => 100,
                'page' => $page,
            ]);
            try {
                $response = fic_request('GET', "/c/{$companyId}/issued_documents?{$query}");
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    break;
                }
                $documents = fic_extract_documents($response);
                foreach ($documents as $doc) {
                    $docId = $doc['id'] ?? null;
                    $docStatus = $doc['status'] ?? null;
                    if (!$docId) {
                        continue;
                    }
                    if (fic_doc_matches_local_number((array)$doc, $numeroFattura, $isPureNumeric)) {
                        return [(string)$docId, $docStatus];
                    }
                    if ($allowFingerprint && fic_doc_matches_fingerprint((array)$doc, $fattura)) {
                        return [(string)$docId, $docStatus];
                    }
                }

                $lastPage = (int)($response['json']['pagination']['last_page'] ?? 1);
                if ($page >= $lastPage) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    // Fallback aggiuntivo per numerazioni alfanumeriche: scansione pagine recenti e match su subject.
    if (!$isPureNumeric) {
        $maxPagesBySubject = 40;
        for ($page = 1; $page <= $maxPagesBySubject; $page++) {
            $response = fic_list_documents_page($companyId, $docType, $page);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                break;
            }
            $documents = fic_extract_documents($response);
            if (!$documents) {
                break;
            }

            foreach ($documents as $doc) {
                $docId = $doc['id'] ?? null;
                $docStatus = $doc['status'] ?? null;
                if (!$docId) {
                    continue;
                }
                if (fic_doc_matches_local_number((array)$doc, $numeroFattura, $isPureNumeric)) {
                    return [(string)$docId, $docStatus];
                }
                if ($allowFingerprint && fic_doc_matches_fingerprint((array)$doc, $fattura)) {
                    return [(string)$docId, $docStatus];
                }
            }

            $lastPage = (int)($response['json']['pagination']['last_page'] ?? 0);
            if ($lastPage > 0 && $page >= $lastPage) {
                break;
            }
        }
    }

    // Strategia 2: scansione pagine (fallback solo per numeri puri)
    if (!$isPureNumeric) {
        return [null, null];
    }
    $targetNumber = (int)$numeroFattura;
    if ($targetNumber <= 0 || $targetNumber > 2147483646) {
        return [null, null];
    }

    $maxPages = 10;
    for ($page = 1; $page <= $maxPages; $page++) {
        $response = fic_list_documents_page($companyId, $docType, $page);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            break;
        }
        $documents = fic_extract_documents($response);
        if (!$documents) break;

        foreach ($documents as $doc) {
            $docId = $doc['id'] ?? null;
            if (!$docId) continue;
            if (fic_doc_matches_local_number((array)$doc, $numeroFattura, $isPureNumeric)) {
                return [(string)$docId, $doc['status'] ?? null];
            }
            if ($allowFingerprint && fic_doc_matches_fingerprint((array)$doc, $fattura)) {
                return [(string)$docId, $doc['status'] ?? null];
            }
        }

        $lastPage = (int)($response['json']['pagination']['last_page'] ?? 0);
        if ($lastPage > 0 && $page >= $lastPage) break;
    }

    // Strategia 3: scansione pagine globali senza filtro tipo (ultima chance).
    $maxPagesAnyType = 60;
    for ($page = 1; $page <= $maxPagesAnyType; $page++) {
        $response = fic_list_documents_page_any_type($companyId, $page);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            break;
        }
        $documents = fic_extract_documents($response);
        if (!$documents) {
            break;
        }

        foreach ($documents as $doc) {
            $docId = $doc['id'] ?? null;
            $docStatus = $doc['status'] ?? null;
            if (!$docId) {
                continue;
            }
            if (fic_doc_matches_local_number((array)$doc, $numeroFattura, $isPureNumeric)) {
                return [(string)$docId, $docStatus];
            }
            if ($allowFingerprint && fic_doc_matches_fingerprint((array)$doc, $fattura)) {
                return [(string)$docId, $docStatus];
            }
        }

        $lastPage = (int)($response['json']['pagination']['last_page'] ?? 0);
        if ($lastPage > 0 && $page >= $lastPage) {
            break;
        }
    }

    return [null, null];
}

function fic_extract_doc_id_from_duplicate_response(array $response): ?string
{
    $candidates = [];
    $candidates[] = (string)($response['json']['error']['id'] ?? '');
    $candidates[] = (string)($response['json']['error']['extra']['id'] ?? '');
    $candidates[] = (string)($response['json']['data']['id'] ?? '');

    $body = (string)($response['body'] ?? '');
    if ($body !== '') {
        if (preg_match('/"id"\s*:\s*"?(\d{6,})"?/i', $body, $m)) {
            $candidates[] = $m[1];
        }
        if (preg_match('/document(?:_id)?\D+(\d{6,})/i', $body, $m)) {
            $candidates[] = $m[1];
        }
    }

    foreach ($candidates as $candidate) {
        $id = trim($candidate);
        if ($id !== '' && ctype_digit($id) && (int)$id > 0) {
            return (string)((int)$id);
        }
    }

    return null;
}

function fic_is_payments_mismatch_error(array $response): bool
{
    if ((int)($response['status'] ?? 0) !== 422) {
        return false;
    }

    $message = (string)($response['json']['error']['message'] ?? '');
    $messageLower = fic_lower($message);

    if (strpos($messageLower, 'totale dei pagamenti non corrisponde al totale da pagare') !== false) {
        return true;
    }

    $amountDue = (float)($response['json']['extra']['totals']['amount_due'] ?? 0);
    $paymentsSum = (float)($response['json']['extra']['totals']['payments_sum'] ?? 0);
    return $amountDue > 0 && $paymentsSum >= 0 && $paymentsSum < $amountDue;
}

function fic_extract_amount_due_from_error(array $response): float
{
    $amountDue = (float)($response['json']['extra']['totals']['amount_due'] ?? 0);
    return $amountDue > 0 ? round($amountDue, 2) : 0.0;
}

function fic_normalize_paid_date(array $fattura): string
{
    $rawDate = (string)($fattura['data_pagamento'] ?? '');
    if ($rawDate === '') {
        $rawDate = (string)($fattura['data_emissione'] ?? '');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate, $m)) {
        return $m[0];
    }

    return date('Y-m-d');
}

function fic_normalize_due_date(array $fattura): string
{
    $rawDate = (string)($fattura['data_scadenza'] ?? '');
    if ($rawDate === '') {
        $rawDate = (string)($fattura['data_emissione'] ?? '');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate, $m)) {
        return $m[0];
    }

    return date('Y-m-d');
}

function fic_invoice_payment_status(array $fattura): string
{
    $stato = fic_lower((string)($fattura['stato'] ?? ''));
    return $stato === 'pagata' ? 'paid' : 'not_paid';
}

function fic_invoice_total_amount(array $fattura, array $righe = []): float
{
    if (array_key_exists('totale', $fattura) && is_numeric($fattura['totale'])) {
        $total = round((float)$fattura['totale'], 2);
        if ($total > 0) {
            return $total;
        }
    }

    $sum = 0.0;
    foreach ($righe as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (array_key_exists('totale_riga', $r) && is_numeric($r['totale_riga'])) {
            $sum += (float)$r['totale_riga'];
            continue;
        }
        $qty = isset($r['quantita']) && is_numeric($r['quantita']) ? (float)$r['quantita'] : 1.0;
        $unit = isset($r['prezzo_unitario']) && is_numeric($r['prezzo_unitario']) ? (float)$r['prezzo_unitario'] : 0.0;
        $sum += max($qty, 1.0) * $unit;
    }

    return round(max($sum, 0.0), 2);
}

function fic_prepare_payment_data(array $fattura, float $amount, array $cfg, array $preCreate): array
{

    // Sicurezza: l'importo del pagamento deve essere esattamente il totale della fattura (già comprensivo di IVA)
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return [[], 0, 0];
    }

    $paymentAccountId = isset($cfg['payment_account_id']) ? (int)$cfg['payment_account_id'] : 0;
    if ($paymentAccountId <= 0) {
        $paymentAccountId = fic_pick_payment_account_id_from_precreate($preCreate);
    }

    $status = fic_invoice_payment_status($fattura);
    if ($status === 'paid' && $paymentAccountId <= 0) {
        // FIC ignora un pagamento "paid" senza payment_account e calcola payments_sum=0.
        // Meglio creare la scadenza non pagata: l'importo resta coerente e il documento passa.
        $status = 'not_paid';
    }

    $payment = [
        'amount' => $amount,
        'due_date' => fic_normalize_due_date($fattura),
        'status' => $status,
    ];

    if ($status === 'paid') {
        $payment['paid_date'] = fic_normalize_paid_date($fattura);
        if ($paymentAccountId > 0) {
            $payment['payment_account'] = ['id' => $paymentAccountId];
        }
    }

    $paymentMethodId = isset($cfg['payment_method_id']) ? (int)$cfg['payment_method_id'] : 0;
    if ($paymentMethodId <= 0) {
        $paymentMethodId = fic_pick_payment_method_id_from_precreate($preCreate);
    }

    return [$payment, $paymentMethodId, $paymentAccountId];
}

function fic_create_document_request(array $data, bool $fixPayments = false): array
{
    $payload = ['data' => $data];
    if ($fixPayments) {
        $payload['options'] = ['fix_payments' => true];
    }
    return $payload;
}

function fic_create_if_missing(PDO $pdo, array $fattura, array $righe, array $cfg, string $companyId): array
{
    // Salta fatture di test: se il numero o il nome cliente contiene "test".
    $numeroFattura = strtolower(trim((string)($fattura['numero'] ?? '')));
    $nomeCliente = strtolower(trim((string)($fattura['nome'] ?? $fattura['azienda'] ?? '')));
    if (strpos($numeroFattura, 'test') !== false || strpos($nomeCliente, 'test') !== false) {
        fic_log_if_possible($pdo, (int)($fattura['id'] ?? 0), 'fic_sync', 'skipped', 'Fattura di test saltata (numero o nome contiene "test").', [
            'numero' => $fattura['numero'] ?? '',
            'nome' => $fattura['nome'] ?? '',
            'azienda' => $fattura['azienda'] ?? '',
        ]);
        return [null, null];
    }

    // Assicura che la connessione MySQL sia attiva
    fic_ensure_db_connection($pdo);
    // Default: sincronizza su FIC senza forzare e-fattura.
    // L'e-fattura viene forzata solo dai flussi espliciti di invio SDI.
    $forceEInvoice = isset($cfg['force_e_invoice']) && $cfg['force_e_invoice'] === true;
    $allowDuplicateForceCreate = !empty($cfg['allow_duplicate_force_create']);
    $strictNoLinkOnDuplicate = !empty($cfg['strict_no_link_on_duplicate']);
    $docType = $fattura['document_type'] ?? ($cfg['document_type'] ?? 'invoice');
    // Se il flusso richiede e-fattura, prova sempre la creazione/ricreazione elettronica
    // e lascia la validazione finale ai controlli FIC.
    $requireEInvoice = ($forceEInvoice && $docType === 'invoice');
    
    if (!empty($fattura['fic_document_id'])) {
        $existingDocId = (string)$fattura['fic_document_id'];
        $existingStatus = $fattura['fic_status'] ?? null;
        $mustRecreate = false;
        $clearMessage = '';
        $clearMeta = [
            'company_id' => $companyId,
            'fic_document_id' => $existingDocId,
        ];

        // Verifica che l'ID gia salvato sia ancora valido/raggiungibile su FIC
        $docCheck = fic_get_document_status($companyId, $existingDocId);
        if ($docCheck !== null) {
            if (!fic_doc_matches_local_invoice($docCheck, $fattura)) {
                $mustRecreate = true;
                $clearMessage = 'fic_document_id locale collegato a documento FIC non coerente (numero/oggetto): reset e nuova creazione.';
                $clearMeta['local_numero'] = (string)($fattura['numero'] ?? '');
                $clearMeta['fic_visible_subject'] = (string)($docCheck['visible_subject'] ?? '');
                $clearMeta['fic_number'] = (string)($docCheck['number'] ?? '');
            } elseif ($requireEInvoice && array_key_exists('e_invoice', $docCheck) && $docCheck['e_invoice'] === false) {
                fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Documento FIC esistente non elettronico: collegato per conversione in e-fattura nel passo successivo.', $clearMeta);
                return [$existingDocId, $existingStatus];
            } else {
                return [$existingDocId, $existingStatus];
            }
        } else {
            // ID locale non piu valido (documento eliminato/non raggiungibile): reset e ricrea
            $mustRecreate = true;
            $clearMessage = 'fic_document_id locale non piu raggiungibile su FIC: reset collegamento e nuova creazione.';
        }

        if ($mustRecreate) {
            fic_ensure_db_connection($pdo);
            $clear = $pdo->prepare("UPDATE fatture SET fic_document_id = NULL, fic_status = NULL, fic_pdf_url = NULL WHERE id = :id");
            $clear->execute([':id' => (int)$fattura['id']]);

            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', $clearMessage, $clearMeta);

            $fattura['fic_document_id'] = null;
            $fattura['fic_status'] = null;
        }
    }

    // Usa data_pagamento se disponibile, altrimenti data_emissione.
    $ficDate = '';
    $emissioneRaw = trim((string)($fattura['data_emissione'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $emissioneRaw, $m)) {
        $ficDate = $m[0];
    }
    if (!empty($fattura['data_pagamento'])) {
        $parsedTs = strtotime((string)$fattura['data_pagamento']);
        if ($parsedTs !== false) {
            $parsed = date('Y-m-d', $parsedTs);
            if ($parsed && $parsed !== '1970-01-01') {
                $ficDate = $parsed;
            }
        }
    }
    if ($ficDate === '') {
        $ficDate = date('Y-m-d');
        fic_log_if_possible($pdo, (int)($fattura['id'] ?? 0), 'fic_sync', 'warning', 'Data emissione mancante nel payload locale: uso la data corrente per FIC.', [
            'numero' => $fattura['numero'] ?? null,
        ]);
    }

    $effectiveCfg = $cfg;
    $preCreateInfo = fic_get_pre_create_info($companyId, (string)$docType);
    $resolvedVatTypeId = fic_resolve_vat_type_id($cfg, $companyId);
    if ($resolvedVatTypeId >= 0) {
        $effectiveCfg['vat_type_id'] = $resolvedVatTypeId;
        $resolvedVatTypeValue = fic_resolve_vat_type_value($cfg, $companyId, $resolvedVatTypeId);
        if ($resolvedVatTypeValue > 0) {
            $effectiveCfg['vat_type_value'] = $resolvedVatTypeValue;
        }
    }
    $effectiveCfg['prices_include_vat'] = fic_invoice_prices_include_vat($fattura, $effectiveCfg);
    $stripeCausale = fic_resolve_stripe_payment_causale($pdo, $fattura);
    if ($stripeCausale !== '') {
        $effectiveCfg['_stripe_causale'] = $stripeCausale;
    }
    $mappedEntityId = fic_get_or_create_client_entity_id($pdo, $companyId, $fattura);
    $entityPayload = fic_prepare_entity($fattura);
    if ($mappedEntityId !== null) {
        $entityPayload = ['id' => (int)$mappedEntityId];
    }

    $data = [
        'type' => $docType,
        'entity' => $entityPayload,
        'date' => $ficDate,
        'visible_subject' => fic_build_invoice_label($fattura),
        'currency' => [
            'id' => $cfg['currency_id'] ?? 'EUR',
        ],
        'language' => [
            'code' => $cfg['language_code'] ?? 'it',
            'name' => $cfg['language_name'] ?? 'Italiano',
        ],
        'items_list' => fic_build_items($fattura, $righe, $effectiveCfg),
    ];
    if (!empty($effectiveCfg['prices_include_vat'])) {
        $data['use_gross_prices'] = true;
    }

    // Forza il flag e_invoice sul documento e delega a FIC la validazione finale.
    if ($forceEInvoice && $docType === 'invoice') {
        $data['e_invoice'] = true;
        if (!isset($data['ei_data']) || !is_array($data['ei_data'])) {
            $data['ei_data'] = [];
        }
        $data['ei_data']['payment_method'] = fic_pick_einvoice_payment_method_code($cfg, $preCreateInfo);
    }

    // Imposta number su FIC solo se il numero locale e' numerico puro.
    // Evita collisioni su numerazioni alfanumeriche tipo STR-INV-YYYYMMDD-001
    // che, se compresse a sole cifre finali, producono duplicati (1,2,3...).
    $forcedFicNumber = trim((string)($fattura['fic_forced_number'] ?? ''));
    $forcedNumeration = fic_duplicate_recovery_numeration($cfg, $fattura);
    if ($forcedFicNumber !== '' && preg_match('/^\d+$/', $forcedFicNumber)) {
        $num = (int)$forcedFicNumber;
        if ($num > 0 && $num <= 2147483646) {
            $data['number'] = $num;
            if ($forcedNumeration !== '') {
                $data['numeration'] = $forcedNumeration;
            }
        }
    } else {
        $numeroFattura = trim((string)($fattura['numero'] ?? ''));
        if ($numeroFattura !== '' && preg_match('/^\d+$/', $numeroFattura)) {
            $num = (int)$numeroFattura;
            if ($num > 0 && $num <= 2147483646) {
                $data['number'] = $num;
            }
        }
    }

    $initialPaymentAmount = fic_invoice_total_amount($fattura, $righe);
    // FIC richiede almeno un pagamento >= 0.01 se amount_due > 0
    if ($initialPaymentAmount > 0) {
        $safeAmount = $initialPaymentAmount < 0.01 ? 0.01 : $initialPaymentAmount;
        [$paymentData, $paymentMethodId] = fic_prepare_payment_data($fattura, $safeAmount, $cfg, $preCreateInfo);
        if ($paymentData) {
            $data['payments_list'] = [$paymentData];
        }
        if ($paymentMethodId > 0) {
            $data['payment_method'] = ['id' => $paymentMethodId];
        }
    }

    $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($data, !empty($data['payments_list'])));

    // Se il riferimento entity.id non e' valido, riprova con entity inline.
    if (($response['status'] ?? 0) >= 400 && isset($data['entity']['id'])) {
        $retryData = $data;
        $retryData['entity'] = fic_prepare_entity($fattura);
        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry creazione FIC con entity inline dopo errore su entity.id.', [
            'company_id' => $companyId,
            'entity_id' => $data['entity']['id'],
            'http_status' => $response['status'] ?? null,
        ]);
        $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
        $data = $retryData;
    }

    // Se abbiamo gia' scelto un number cronologico, su 409 proviamo il successivo numero cronologico libero.
    if ((int)($response['status'] ?? 0) === 409 && $forcedFicNumber !== '' && fic_response_has_duplicate_number($response)) {
        $baseForcedNumber = (int)$forcedFicNumber;
        for ($offset = 1; $offset <= 3; $offset++) {
            $nextForcedNumber = $baseForcedNumber + $offset;
            if ($nextForcedNumber <= 0 || $nextForcedNumber > 2147483646) {
                break;
            }

            $retryData = $data;
            $retryData['number'] = $nextForcedNumber;
            if ($forcedNumeration !== '') {
                $retryData['numeration'] = $forcedNumeration;
            }
            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry con progressivo intelligente alternativo dopo 409.', [
                'original_number' => $forcedFicNumber,
                'retry_number' => $nextForcedNumber,
                'retry_numeration' => $forcedNumeration !== '' ? $forcedNumeration : null,
                'http_status' => $response['status'] ?? null,
                'retry_offset' => $offset,
            ]);
            $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
            $data = $retryData;
            $forcedFicNumber = (string)$nextForcedNumber;
            $fattura['fic_forced_number'] = $forcedFicNumber;
            if (($response['status'] ?? 0) < 400 || !fic_response_has_duplicate_number($response)) {
                break;
            }
        }
    }

    // 409 per conflitto sequenza numerica: riprova senza il campo number (FIC assegna il prossimo disponibile)
    if ((int)($response['status'] ?? 0) === 409 && isset($data['number'])) {
        $bodyLower = fic_lower((string)($response['json']['error']['message'] ?? '') . ' ' . (string)($response['body'] ?? ''));
        if ($forcedFicNumber === '' && (strpos($bodyLower, 'numero inferiore') !== false || strpos($bodyLower, 'numero superiore') !== false)) {
            $retryData = $data;
            unset($retryData['number']);
            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry senza number dopo 409 conflitto sequenza.', [
                'original_number' => $data['number'],
            ]);
            $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
        }
    }

    if ($response['status'] >= 400 && fic_is_payments_mismatch_error($response)) {
        $amountDue = fic_extract_amount_due_from_error($response);
        $paymentsSum = (float)($response['json']['extra']['totals']['payments_sum'] ?? 0);
        // Se amountDue > 0 e payments_sum = 0, forzo la rigenerazione della payments_list
        if ($amountDue > 0 && $paymentsSum < $amountDue) {
            $retryData = $data;
            // Se amountDue < 0.01, forzo almeno 0.01 per superare i controlli FIC
            $safeAmount = $amountDue < 0.01 ? 0.01 : $amountDue;
            [$paymentData, $paymentMethodId, $paymentAccountId] = fic_prepare_payment_data($fattura, $safeAmount, $cfg, $preCreateInfo);
            $retryData['payments_list'] = [$paymentData];
            if ($paymentMethodId > 0) {
                $retryData['payment_method'] = ['id' => $paymentMethodId];
            }

            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry creazione documento FIC con payments_list dopo 422 pagamenti. amount_due=' . $amountDue . ' payments_sum=' . $paymentsSum, [
                'company_id' => $companyId,
                'document_type' => $docType,
                'amount_due' => $amountDue,
                'payments_sum' => $paymentsSum,
                'due_date' => $retryData['payments_list'][0]['due_date'],
                'paid_date' => $retryData['payments_list'][0]['paid_date'] ?? null,
                'payment_status' => $retryData['payments_list'][0]['status'] ?? null,
                'payment_method_id' => $paymentMethodId > 0 ? $paymentMethodId : null,
                'payment_account_id' => $paymentAccountId > 0 ? $paymentAccountId : null,
            ]);

            $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, true));
        }
    }


    // Fallback robustezza: alcuni account FIC rispondono 500 su payload con IVA esplicita.
    if ((int)($response['status'] ?? 0) === 500 && $resolvedVatTypeId > 0) {
        // Logga la risposta completa per diagnosi
        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'error', 'HTTP 500 da FIC: risposta completa', [
            'company_id' => $companyId,
            'response_status' => $response['status'] ?? null,
            'response_body' => $response['body'] ?? null,
            'response_json' => $response['json'] ?? null,
            'payload' => $data,
        ]);
        $retryData = $data;
        if (isset($retryData['items_list']) && is_array($retryData['items_list'])) {
            foreach ($retryData['items_list'] as &$item) {
                if (is_array($item) && array_key_exists('vat', $item)) {
                    unset($item['vat']);
                }
            }
            unset($item);
        }
        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry creazione FIC senza VAT esplicita dopo HTTP 500.', [
            'company_id' => $companyId,
            'resolved_vat_type_id' => $resolvedVatTypeId,
        ]);
        $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
    }

    // Fallback estremo su HTTP 500: payload minimale (solo nome cliente + righe essenziali).
    if ((int)($response['status'] ?? 0) === 500) {
        $minimalData = $data;
        $fallbackUseGrossPrices = fic_invoice_prices_include_vat($fattura, $effectiveCfg);

        $entityName = trim((string)($minimalData['entity']['name'] ?? ''));
        if ($entityName === '') {
            $entityName = 'Cliente #' . (string)($fattura['cliente_id'] ?? $fattura['id'] ?? 'N/D');
        }
        $minimalData['entity'] = ['name' => $entityName];
        unset($minimalData['e_invoice']);

        $safeItems = [];
        $rawItems = $minimalData['items_list'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $idx => $it) {
                if (!is_array($it)) {
                    continue;
                }
                $name = trim((string)($it['name'] ?? 'Riga ' . ($idx + 1)));
                $qty = (float)($it['qty'] ?? 1);
                $priceValue = (float)($it['gross_price'] ?? ($it['net_price'] ?? 0));
                if ($qty <= 0) {
                    $qty = 1;
                }
                if ($name === '') {
                    $name = 'Riga ' . ($idx + 1);
                }
                $safeItem = [
                    'name' => $name,
                    'qty' => $qty,
                ];
                if ($fallbackUseGrossPrices) {
                    $safeItem['gross_price'] = $priceValue;
                } else {
                    $safeItem['net_price'] = $priceValue;
                }
                $safeItems[] = $safeItem;
            }
        }
        if (!$safeItems) {
            $safeItem = [
                'name' => 'Fattura ' . (string)($fattura['numero'] ?? 'senza-numero'),
                'qty' => 1,
            ];
            $totalAmount = fic_invoice_total_amount($fattura, $righe);
            if ($fallbackUseGrossPrices) {
                $safeItem['gross_price'] = $totalAmount;
            } else {
                $safeItem['net_price'] = $totalAmount;
            }
            $safeItems[] = $safeItem;
        }
        $minimalData['items_list'] = $safeItems;

        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry creazione FIC con payload minimale dopo HTTP 500.', [
            'company_id' => $companyId,
            'items_count' => count($safeItems),
        ]);
        $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($minimalData, !empty($minimalData['payments_list'])));
    }


    // Ultimo fallback su HTTP 500:
    // 1) prova a collegare un eventuale documento gia presente su FIC con lo stesso numero
    // 2) prova creazione "ultra minimale" con una sola riga totale
    // 3) se ancora 500, attende e riprova una sola volta
    if ((int)($response['status'] ?? 0) === 500) {
        [$existingDocId500, $existingStatus500] = fic_find_existing_document_by_number($companyId, $docType, $fattura);
        if ($existingDocId500 !== null) {
            fic_ensure_db_connection($pdo);
            $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
            $upd->execute([
                ':doc_id' => $existingDocId500,
                ':status' => $existingStatus500,
                ':id' => (int)$fattura['id'],
            ]);

            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Documento collegato su FIC dopo HTTP 500 (ricerca per numero).', [
                'company_id' => $companyId,
                'fic_document_id' => $existingDocId500,
                'fic_status' => $existingStatus500,
            ]);

            return [$existingDocId500, $existingStatus500];
        }

        $numero = trim((string)($fattura['numero'] ?? 'senza-numero'));
        $totale = fic_invoice_total_amount($fattura, $righe);
        if ($totale <= 0) {
            $totale = 0.01;
        }
        $ultraUseGrossPrices = fic_invoice_prices_include_vat($fattura, $effectiveCfg);
        $ultraNet = $totale;
        $ultraVatRate = (float)($effectiveCfg['vat_type_value'] ?? 0);
        if ($ultraUseGrossPrices && $ultraVatRate > 0) {
            $ultraNet = $totale / (1 + ($ultraVatRate / 100));
        }
        $dateRaw = trim((string)($ficDate ?? ''));
        $dateSafe = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) ? $dateRaw : date('Y-m-d');
        $entityName = trim((string)($fattura['azienda'] ?? $fattura['nome'] ?? ''));
        if ($entityName === '') {
            $entityName = 'Cliente #' . (string)($fattura['cliente_id'] ?? $fattura['id'] ?? 'N/D');
        }

        $ultraData = [
            'type' => $docType,
            'entity' => ['name' => $entityName],
            'date' => $dateSafe,
            'visible_subject' => fic_build_invoice_label($fattura),
            'items_list' => [[
                'name' => fic_build_invoice_label($fattura, 150),
                'qty' => 1,
                $ultraUseGrossPrices ? 'gross_price' : 'net_price' => round($ultraUseGrossPrices ? $totale : $ultraNet, 6),
            ]],
        ];
        if ($ultraUseGrossPrices) {
            $ultraData['use_gross_prices'] = true;
        }

        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry creazione FIC ultra-minimale dopo HTTP 500.', [
            'company_id' => $companyId,
            'totale' => $totale,
            'date' => $dateSafe,
        ]);
        $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($ultraData, false));

        // Se ancora 500, attende e riprova una sola volta
        if ((int)($response['status'] ?? 0) === 500) {
            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'HTTP 500 persistente: attendo 2 secondi e riprovo una sola volta.', [
                'company_id' => $companyId,
                'numero' => $numero,
            ]);
            usleep(2000000); // 2 secondi
            $response = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($ultraData, false));
            if ((int)($response['status'] ?? 0) === 500) {
                fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'error', 'HTTP 500 persistente dopo retry finale.', [
                    'company_id' => $companyId,
                    'numero' => $numero,
                ]);
            } else {
                fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Retry HTTP 500 riuscito.', [
                    'company_id' => $companyId,
                    'numero' => $numero,
                ]);
            }
        }
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        if (fic_response_has_duplicate_number($response)) {
            $canLinkDuplicate = (!$strictNoLinkOnDuplicate) || $requireEInvoice;
            if ($canLinkDuplicate) {
                $docIdFromError = fic_extract_doc_id_from_duplicate_response($response);
                if ($docIdFromError !== null) {
                    $docCheckByError = fic_get_document_status($companyId, $docIdFromError);
                    if ($docCheckByError !== null && fic_doc_matches_local_invoice($docCheckByError, $fattura)) {
                        fic_ensure_db_connection($pdo);
                        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                        $upd->execute([
                            ':doc_id' => $docIdFromError,
                            ':status' => $docCheckByError['status'] ?? null,
                            ':id' => (int)$fattura['id'],
                        ]);
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Documento duplicato collegato su FIC tramite ID presente nel body errore.', [
                            'company_id' => $companyId,
                            'document_type' => $docType,
                            'fic_document_id' => $docIdFromError,
                        ]);
                        return [$docIdFromError, $docCheckByError['status'] ?? null];
                    } elseif ($docCheckByError !== null) {
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'ID duplicato ricevuto da FIC ma documento non coerente con numero locale: ignorato.', [
                            'company_id' => $companyId,
                            'fic_document_id' => $docIdFromError,
                            'local_numero' => (string)($fattura['numero'] ?? ''),
                            'fic_visible_subject' => (string)($docCheckByError['visible_subject'] ?? ''),
                            'fic_number' => (string)($docCheckByError['number'] ?? ''),
                        ]);
                    }
                }

                [$existingDocId, $existingStatus] = fic_find_existing_document_by_number($companyId, $docType, $fattura);
                if ($existingDocId !== null) {
                    // Verifica che il documento esista effettivamente e sia raggiungibile
                    $docCheck = fic_get_document_status($companyId, (string)$existingDocId);
                    if ($docCheck !== null) {
                        fic_ensure_db_connection($pdo);
                        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                        $upd->execute([
                            ':doc_id' => $existingDocId,
                            ':status' => $existingStatus,
                            ':id' => (int)$fattura['id'],
                        ]);

                        $logOutcome = 'info';
                        $logMessage = 'Documento duplicato collegato su FIC (verificato).';
                        if ($requireEInvoice && array_key_exists('e_invoice', $docCheck) && $docCheck['e_invoice'] === false) {
                            $logOutcome = 'warning';
                            $logMessage = 'Documento duplicato trovato su FIC (non elettronico): collegato per conversione e-fattura.';
                        }
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', $logOutcome, $logMessage, [
                            'company_id' => $companyId,
                            'document_type' => $docType,
                            'fic_document_id' => $existingDocId,
                            'fic_status' => $existingStatus,
                        ]);

                        return [$existingDocId, $existingStatus];
                    } else {
                        // Documento trovato in ricerca ma non raggiungibile: skip e ricrea
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Documento duplicato trovato ma non raggiungibile, ricreo con numero modificato.', [
                            'company_id' => $companyId,
                            'fic_document_id' => $existingDocId,
                        ]);
                    }
                }
            } else {
                fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Duplicato FIC rilevato: in modalita\' massiva strict non collego documenti esistenti, provo creazione univoca.', [
                    'company_id' => $companyId,
                    'document_type' => $docType,
                    'local_numero' => (string)($fattura['numero'] ?? ''),
                ]);
            }
            
            // Fallback: duplicato non trovabile → riprova senza numero (FIC auto-assegna)
            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Duplicato 409 non collegabile, riprovo senza numero.', [
                'company_id' => $companyId,
                'original_number' => $fattura['numero'] ?? 'N/A',
            ]);
            $retryData = $data;
            unset($retryData['number'], $retryData['numeration']);
            $retryResp = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
            if ($retryResp['status'] >= 200 && $retryResp['status'] < 300) {
                $retryDocId = $retryResp['json']['data']['id'] ?? null;
                $retryStatus = $retryResp['json']['data']['status'] ?? null;
                if ($retryDocId) {
                    fic_ensure_db_connection($pdo);
                    $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                    $upd->execute([':doc_id' => $retryDocId, ':status' => $retryStatus, ':id' => (int)$fattura['id']]);
                    fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Documento creato su FIC senza numero (auto-assegnato).', [
                        'fic_document_id' => $retryDocId,
                    ]);
                    return [$retryDocId, $retryStatus];
                }
            }
            fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry senza numero non riuscito dopo duplicato 409.', [
                'company_id' => $companyId,
                'http_status' => $retryResp['status'] ?? null,
                'body' => substr((string)($retryResp['body'] ?? ''), 0, 300),
            ]);

            // Ultima chance: riesegui ricerca duplicato dopo il retry senza numero.
            if ($canLinkDuplicate) {
                [$existingDocId2, $existingStatus2] = fic_find_existing_document_by_number($companyId, $docType, $fattura);
                if ($existingDocId2 !== null) {
                    fic_ensure_db_connection($pdo);
                    $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                    $upd->execute([
                        ':doc_id' => $existingDocId2,
                        ':status' => $existingStatus2,
                        ':id' => (int)$fattura['id'],
                    ]);
                    fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'info', 'Documento duplicato collegato su FIC al secondo tentativo di ricerca.', [
                        'company_id' => $companyId,
                        'document_type' => $docType,
                        'fic_document_id' => $existingDocId2,
                        'fic_status' => $existingStatus2,
                    ]);
                    return [$existingDocId2, $existingStatus2];
                }
            }

            // Fallback finale opzionale (solo flussi manuali):
            // crea comunque un documento univoco su FIC cambiando solo il visible_subject.
            if ($allowDuplicateForceCreate) {
                $forceCreateData = $data;
                unset($forceCreateData['number'], $forceCreateData['numeration']);
                $safeSuffix = ' [CRM-' . (int)$fattura['id'] . '-' . date('YmdHis') . ']';
                $baseSubject = trim((string)($forceCreateData['visible_subject'] ?? fic_build_invoice_label($fattura)));
                $forceCreateData['visible_subject'] = $baseSubject . $safeSuffix;
                $forceResp = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($forceCreateData, !empty($forceCreateData['payments_list'])));
                if (($forceResp['status'] ?? 0) >= 200 && ($forceResp['status'] ?? 0) < 300) {
                    $forceDocId = $forceResp['json']['data']['id'] ?? null;
                    $forceStatus = $forceResp['json']['data']['status'] ?? null;
                    if ($forceDocId) {
                        fic_ensure_db_connection($pdo);
                        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                        $upd->execute([
                            ':doc_id' => $forceDocId,
                            ':status' => $forceStatus,
                            ':id' => (int)$fattura['id'],
                        ]);
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Duplicato non collegabile: creato documento FIC con subject univoco.', [
                            'company_id' => $companyId,
                            'fic_document_id' => $forceDocId,
                            'visible_subject' => $forceCreateData['visible_subject'],
                        ]);
                        return [(string)$forceDocId, $forceStatus];
                    }
                }
            }

            // Recovery automatico: rinumera la fattura locale in modo univoco e ritenta subito.
            // Preserva data pagamento, causale/note e righe: cambia solo il numero locale.
            $renumberedNumber = fic_try_auto_renumber_after_duplicate($pdo, $fattura);
            if ($renumberedNumber !== null) {
                $retryFattura = $fattura;
                $retryFattura['numero'] = $renumberedNumber;

                $retryData = $data;
                unset($retryData['number'], $retryData['numeration']);
                $retryData['visible_subject'] = fic_build_invoice_label($retryFattura);

                fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Duplicato FIC: rinumerazione automatica e nuovo tentativo.', [
                    'old_number' => (string)($fattura['numero'] ?? ''),
                    'new_number' => $renumberedNumber,
                    'company_id' => $companyId,
                ]);

                $retryRenumberResp = fic_request('POST', "/c/{$companyId}/issued_documents", fic_create_document_request($retryData, !empty($retryData['payments_list'])));
                if (($retryRenumberResp['status'] ?? 0) >= 200 && ($retryRenumberResp['status'] ?? 0) < 300) {
                    $retryDocId = $retryRenumberResp['json']['data']['id'] ?? null;
                    $retryStatus = $retryRenumberResp['json']['data']['status'] ?? null;
                    if ($retryDocId) {
                        fic_ensure_db_connection($pdo);
                        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
                        $upd->execute([
                            ':doc_id' => $retryDocId,
                            ':status' => $retryStatus,
                            ':id' => (int)$fattura['id'],
                        ]);
                        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'ok', 'Duplicato risolto automaticamente con rinumerazione locale.', [
                            'old_number' => (string)($fattura['numero'] ?? ''),
                            'new_number' => $renumberedNumber,
                            'fic_document_id' => $retryDocId,
                        ]);
                        return [(string)$retryDocId, $retryStatus];
                    }
                } else {
                    fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'warning', 'Retry dopo rinumerazione non riuscito.', [
                        'new_number' => $renumberedNumber,
                        'http_status' => (int)($retryRenumberResp['status'] ?? 0),
                        'body' => substr((string)($retryRenumberResp['body'] ?? ''), 0, 240),
                    ]);
                }
            }

            throw new RuntimeException('FIC_DUPLICATE_NOT_RECOVERABLE:' . ($fattura['numero'] ?? 'N/A'));
        }

        $bodySnippet = trim((string)($response['body'] ?? ''));
        if ($bodySnippet === '') {
            $bodySnippet = 'content_type=' . ($response['content_type'] ?? 'n/a');
        } else {
            $bodySnippet = substr($bodySnippet, 0, 800);
        }
        fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', 'error', 'Errore creazione documento su FIC.', [
            'company_id' => $companyId,
            'document_type' => $docType,
            'resolved_vat_type_id' => $resolvedVatTypeId >= 0 ? $resolvedVatTypeId : null,
            'http_status' => $response['status'],
            'body' => $bodySnippet,
        ]);
        throw new RuntimeException('Fatture in Cloud: risposta non valida (HTTP ' . $response['status'] . '). ' . $bodySnippet);
    }

    $docId = $response['json']['data']['id'] ?? null;
    $status = $response['json']['data']['status'] ?? null;

    // Verifica raggiungibilita' con retry breve (FIC puo' essere eventualmente consistente)
    $docReachable = false;
    if ($docId) {
        $skipReachabilityCheck = !empty($cfg['_skip_post_create_reachability_check']);
        if (!$skipReachabilityCheck) {
            $maxChecks = 5;
            for ($i = 0; $i < $maxChecks; $i++) {
                $docCheck = fic_get_document_status($companyId, (string)$docId);
                if ($docCheck !== null) {
                    $docReachable = true;
                    break;
                }
                if ($i < ($maxChecks - 1)) {
                    usleep(600 * 1000);
                }
            }
        }
    }

    fic_ensure_db_connection($pdo);
    $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
    $upd->execute([
        ':doc_id' => $docId,
        ':status' => $status,
        ':id' => (int)$fattura['id'],
    ]);

    $logMessage = $docReachable
        ? 'Documento creato e verificato su FIC.'
        : 'Documento creato su FIC ma non ancora interrogabile immediatamente (delay API).';
    $logOutcome = $docReachable ? 'ok' : 'info';

    fic_log_if_possible($pdo, (int)$fattura['id'], 'fic_sync', $logOutcome, $logMessage, [
        'company_id' => $companyId,
        'document_type' => $docType,
        'fic_document_id' => $docId,
        'fic_status' => $status,
        'doc_reachable' => $docReachable,
    ]);

    return [$docId, $status];
}

function fic_fetch_pdf(string $companyId, string $docId): array
{
    $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}/pdf");
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $bodySnippet = trim((string)($response['body'] ?? ''));
        if ($bodySnippet === '') {
            $bodySnippet = 'content_type=' . ($response['content_type'] ?? 'n/a');
        } else {
            $bodySnippet = substr($bodySnippet, 0, 800);
        }
        if ((int)$response['status'] === 404) {
            throw new RuntimeException('FIC_DOC_NOT_FOUND:' . $bodySnippet);
        }
        throw new RuntimeException('Fatture in Cloud: errore download PDF (HTTP ' . $response['status'] . '). ' . $bodySnippet);
    }

    if (stripos($response['content_type'], 'application/json') !== false && !empty($response['json']['data']['url'])) {
        $pdfUrl = $response['json']['data']['url'];
        $pdfBody = fic_download_binary_url($pdfUrl);
        if ($pdfBody === false) {
            return [$pdfUrl, ''];
        }
        return [$pdfUrl, $pdfBody];
    }

    return ['', $response['body']];
}

function fic_try_get_pdf_url(string $companyId, string $docId): string
{
    try {
        $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}/pdf");
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return fic_get_document_public_url($companyId, $docId);
        }
        $url = (string)($response['json']['data']['url'] ?? '');
        if ($url !== '') {
            return $url;
        }
        return fic_get_document_public_url($companyId, $docId);
    } catch (Throwable $e) {
        return fic_get_document_public_url($companyId, $docId);
    }
}

function fic_get_document_public_url(string $companyId, string $docId): string
{
    try {
        $response = fic_request('GET', "/c/{$companyId}/issued_documents/{$docId}");
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return '';
        }
        return (string)($response['json']['data']['url'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function fic_download_binary_url(string $url)
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CRM-Fatturazione/1.0');
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    return @file_get_contents($url);
}

function fic_public_pdf_signature_key(): string
{
    $cfg = fic_config();
    $cronSecret = (string)(getenv('CRON_SECRET') ?: '');
    $clientSecret = (string)($cfg['client_secret'] ?? '');
    $clientId = (string)($cfg['client_id'] ?? '');
    $base = 'crm_fic_public_pdf_v1|' . $cronSecret . '|' . $clientSecret . '|' . $clientId;
    return hash('sha256', $base);
}

function fic_build_public_pdf_link(string $companyId, string $docId, bool $download = true, int $ttlSeconds = 1209600): string
{
    $config = require __DIR__ . '/../../../config/config.php';
    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host;
    }

    $expiresAt = time() + max(300, $ttlSeconds);
    $payload = $companyId . '|' . $docId . '|' . $expiresAt;
    $signature = hash_hmac('sha256', $payload, fic_public_pdf_signature_key());

    $query = http_build_query([
        'cid' => $companyId,
        'doc' => $docId,
        'exp' => $expiresAt,
        'sig' => $signature,
        'download' => $download ? '1' : '0',
    ]);

    return $baseUrl . '/modules/fatture/actions/fic_pdf_public.php?' . $query;
}

function fic_verify_public_pdf_signature(string $companyId, string $docId, int $expiresAt, string $signature): bool
{
    if ($companyId === '' || $docId === '' || $expiresAt <= 0 || $signature === '') {
        return false;
    }

    if ($expiresAt < time()) {
        return false;
    }

    $maxFuture = time() + (86400 * 60);
    if ($expiresAt > $maxFuture) {
        return false;
    }

    $payload = $companyId . '|' . $docId . '|' . $expiresAt;
    $expected = hash_hmac('sha256', $payload, fic_public_pdf_signature_key());
    return hash_equals($expected, $signature);
}

function fic_fetch_pdf_with_wait(string $companyId, string $docId, int $attempts = 15, int $delayMs = 2000): array
{
    $tries = 0;
    $lastPdfError = null;
    
    while (true) {
        try {
            return fic_fetch_pdf($companyId, $docId);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $lastPdfError = $msg;
            
            if (strpos($msg, 'FIC_DOC_NOT_FOUND:') === 0 && $tries < ($attempts - 1)) {
                usleep(max(0, $delayMs) * 1000);
                $tries++;
                continue;
            }
            
            // Se tutti i tentativi sono falliti, controlla lo stato del documento
            if ($tries >= ($attempts - 1)) {
                $docStatus = fic_get_document_status($companyId, $docId);
                if ($docStatus === null) {
                    throw new RuntimeException("FIC_DOC_UNREACHABLE: il documento esiste ma non e' raggiungibile via API. Riprova tra poco.");
                }
                if ($docStatus['status'] === 'draft') {
                    throw new RuntimeException('FIC_DOC_STATUS_INVALID: il documento e\' in stato "draft", deve essere pubblicato. companyId=' . $companyId . ' docId=' . $docId);
                }
            }
            
            throw $e;
        }
    }
}

function fic_escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fic_invoice_email_logo_url(): string
{
    $config = require __DIR__ . '/../../../config/config.php';
    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    return $baseUrl . '/assets/brand/logo.svg';
}

function fic_build_invoice_email_html(array $fattura, string $publicPdfUrl, string $dashboardUrl): string
{
    $nome = trim((string)($fattura['nome'] ?? ''));
    $numero = trim((string)($fattura['numero'] ?? ''));
    $titoloCliente = $nome !== '' ? ('Ciao ' . fic_escape_html($nome) . ',') : 'Ciao,';

    $html = '';
    $html .= '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $html .= '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827;">';
    $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:20px 10px;">';
    $html .= '<tr><td align="center">';
    $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 10px 24px rgba(15,23,42,0.12);">';
    $html .= '<tr><td style="padding:16px 20px;border-bottom:1px solid #e5e7eb;background:#ffffff;" align="left">';
    $html .= '<div style="display:inline-block;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;box-shadow:0 4px 14px rgba(15,23,42,0.10);">';
    $html .= '<img src="' . fic_escape_html(fic_invoice_email_logo_url()) . '" alt="CRM Fatturazione" style="height:36px;width:auto;display:block;">';
    $html .= '</div>';
    $html .= '</td></tr>';
    $html .= '<tr><td style="padding:24px;">';
    $html .= '<p style="margin:0 0 10px 0;font-size:18px;font-weight:700;color:#111827;">' . $titoloCliente . '</p>';
    $html .= '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#374151;">Grazie per aver scelto i nostri servizi. Ecco la tua fattura <strong>' . fic_escape_html($numero) . '</strong>.</p>';
    $html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;"><tr><td>';
    $html .= '<a href="' . fic_escape_html($publicPdfUrl) . '" style="display:inline-block;background:#0ea5e9;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 18px;border-radius:8px;">Scarica Fattura</a>';
    $html .= '</td></tr></table>';
    $html .= '<p style="margin:0 0 6px 0;font-size:13px;color:#6b7280;">Se il pulsante non funziona, copia questo link nel browser:</p>';
    $html .= '<p style="margin:0 0 16px 0;font-size:13px;word-break:break-all;"><a href="' . fic_escape_html($publicPdfUrl) . '" style="color:#0369a1;text-decoration:underline;">' . fic_escape_html($publicPdfUrl) . '</a></p>';
    $html .= '<p style="margin:0 0 6px 0;font-size:12px;color:#9ca3af;">Link area riservata Fatture in Cloud:</p>';
    $html .= '<p style="margin:0;font-size:12px;word-break:break-all;"><a href="' . fic_escape_html($dashboardUrl) . '" style="color:#64748b;text-decoration:underline;">' . fic_escape_html($dashboardUrl) . '</a></p>';
    $html .= '</td></tr></table>';
    $html .= '</td></tr></table>';
    $html .= '</body></html>';

    return $html;
}

function fic_einvoice_missing_data_error(array $fattura): ?string
{
    $vatNumber = fic_normalize_vat_number($fattura['piva'] ?? '');
    $sdiCode = fic_normalize_sdi_code($fattura['codice_sdi'] ?? '');
    $pec = fic_normalize_pec($fattura['pec'] ?? '');

    $missing = [];
    if ($vatNumber === '') {
        $missing[] = 'P.IVA';
    } elseif (preg_match('/^\d{11}$/', $vatNumber) && !fic_is_valid_italian_vat_number($vatNumber)) {
        $missing[] = 'P.IVA valida';
    }
    if ($sdiCode === '' && $pec === '') {
        $missing[] = 'Codice SDI (7 caratteri) o PEC valida';
    }

    $addressError = fic_einvoice_address_error($fattura);
    if ($addressError !== null) {
        $missing[] = 'indirizzo cliente completo (CAP + Comune + Provincia)';
    }

    if (!$missing) {
        return null;
    }

    return 'Dati cliente insufficienti per e-fattura: manca ' . implode(' + ', $missing) . '.';
}

function fic_send_invoice_electronic(PDO $pdo, array $fattura, array $righe, array $cfg, string $companyId, string $invioTipo = 'manuale_bulk'): void
{
    fic_ensure_db_connection($pdo);
    $cfgEInvoice = $cfg;
    $preferExistingOnly = !empty($cfg['prefer_existing_fic_only']);
    $cfgEInvoice['force_e_invoice'] = false;
    // Anti-doppione: in invio SDI non creare copie "forzate" con subject univoco.
    // Se serve, puo' essere riattivato solo via configurazione esplicita.
    $cfgEInvoice['allow_duplicate_force_create'] = !empty($cfg['allow_duplicate_force_create_einvoice']);
    $docType = $fattura['document_type'] ?? ($cfg['document_type'] ?? 'invoice');
    // In invio SDI su fatture, prova sempre il percorso elettronico:
    // la validazione definitiva resta lato FIC.
    $requireEInvoice = ($docType === 'invoice');

    $fatturaId = (int)($fattura['id'] ?? 0);
    $logMeta = [
        'numero' => $fattura['numero'] ?? null,
        'invio_tipo' => $invioTipo,
        'doc_id' => $fattura['fic_document_id'] ?? null,
        'codice_sdi' => fic_trim_or_empty($fattura['codice_sdi'] ?? ''),
        'pec' => fic_trim_or_empty($fattura['pec'] ?? ''),
    ];

    $missingDataError = fic_einvoice_missing_data_error($fattura);
    if ($missingDataError !== null) {
        $clienteId = (int)($fattura['cliente_id'] ?? 0);
        $clienteNome = trim((string)($fattura['nome'] ?? $fattura['azienda'] ?? ''));
        $clienteLabel = $clienteNome !== '' ? $clienteNome : ('Cliente #' . ($clienteId > 0 ? (string)$clienteId : 'N/D'));
        $clienteHint = ' Cliente da correggere: ' . $clienteLabel;
        if ($clienteId > 0) {
            $clienteHint .= ' (ID ' . $clienteId . '). Apri: /modules/clienti/cliente_scheda.php?id=' . $clienteId;
        }
        $missingDataErrorWithHint = $missingDataError . $clienteHint;

        fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $missingDataErrorWithHint, false);
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', $missingDataErrorWithHint, array_merge($logMeta, [
            'precheck_error' => $missingDataErrorWithHint,
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'cliente_nome' => $clienteNome !== '' ? $clienteNome : null,
        ]));
        throw new RuntimeException($missingDataErrorWithHint);
    }

    if ($preferExistingOnly) {
        $docId = trim((string)($fattura['fic_document_id'] ?? ''));
        if ($docId === '') {
            $msg = 'Modalita solo-da-FIC: documento non collegato in CRM.';
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', $msg, $logMeta);
            throw new RuntimeException($msg);
        }
    } else {
        [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfgEInvoice, $companyId);
        if (!$docId) {
            $msg = 'Documento FIC non disponibile per invio SDI.';
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', $msg, $logMeta);
            throw new RuntimeException($msg);
        }
    }

    $convertExistingToElectronic = function (string $sourceDocId, string $reason) use ($pdo, $fattura, $cfgEInvoice, $companyId, $fatturaId, $logMeta): ?string {
        [$transformStatus, $transformPayload, $transformError] = fic_build_transform_einvoice_payload($companyId, $sourceDocId, $fattura, $cfgEInvoice);
        if ($transformStatus !== 'ok' || !is_array($transformPayload)) {
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', 'Conversione API (transform) non riuscita: ' . substr((string)$transformError, 0, 220), array_merge($logMeta, [
                'source_fic_document_id' => $sourceDocId,
                'reason' => $reason,
            ]));
            return null;
        }

        [$createStatus, $newDocId, $newDocStatus, $createError] = fic_create_transformed_einvoice($companyId, $transformPayload);
        if ($createStatus !== 'ok' || $newDocId === null) {
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', 'Conversione API (create da transform) non riuscita: ' . substr((string)$createError, 0, 220), array_merge($logMeta, [
                'source_fic_document_id' => $sourceDocId,
                'reason' => $reason,
            ]));
            return null;
        }

        fic_ensure_db_connection($pdo);
        $upd = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id");
        $upd->execute([
            ':doc_id' => (string)$newDocId,
            ':status' => $newDocStatus,
            ':id' => $fatturaId,
        ]);

        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'ok', 'Documento duplicato in elettronico tramite API transform/create.', array_merge($logMeta, [
            'source_fic_document_id' => $sourceDocId,
            'fic_document_id' => (string)$newDocId,
            'reason' => $reason,
        ]));

        return (string)$newDocId;
    };

    $publishInfo = fic_publish_document_if_draft_ex($companyId, (string)$docId, $fattura, (string)$docType);
    $publishDocId = trim((string)($publishInfo['doc_id'] ?? ''));
    if ($publishDocId !== '' && $publishDocId !== (string)$docId) {
        $docId = $publishDocId;
        try {
            fic_ensure_db_connection($pdo);
            $updDoc = $pdo->prepare("UPDATE fatture SET fic_document_id = :doc_id, fic_synced_at = NOW() WHERE id = :id");
            $updDoc->execute([
                ':doc_id' => $docId,
                ':id' => $fatturaId,
            ]);
        } catch (Throwable $e) {
            // Se update locale fallisce, continua comunque con l'ID risolto a runtime.
        }
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'info', 'Documento FIC ricollegato automaticamente prima della publish.', array_merge($logMeta, [
            'old_fic_document_id' => $logMeta['doc_id'] ?? null,
            'fic_document_id' => $docId,
        ]));
    }
    if (empty($publishInfo['ok'])) {
        $publishDetail = trim((string)($publishInfo['message'] ?? ''));
        $publishSoftFail = ($publishDetail !== '')
            && (
                stripos($publishDetail, 'HTTP 404') !== false
                || stripos($publishDetail, 'HTTP 405') !== false
            );
        if ($publishSoftFail) {
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', 'Publish FIC non bloccante: proseguo con invio SDI.', array_merge($logMeta, [
                'fic_document_id' => $docId,
                'publish_detail' => substr($publishDetail, 0, 220),
            ]));
        } else {
        $msg = 'Impossibile pubblicare il documento FIC prima dell\'invio SDI.'
            . ($publishDetail !== '' ? ' Dettaglio: ' . $publishDetail : '');
        fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', $msg, array_merge($logMeta, [
            'fic_document_id' => $docId,
            'publish_detail' => $publishDetail !== '' ? $publishDetail : null,
        ]));
        throw new RuntimeException($msg);
        }
    }
    $publishMessage = trim((string)($publishInfo['message'] ?? ''));
    if ($publishMessage !== '') {
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'info', substr($publishMessage, 0, 220), array_merge($logMeta, [
            'fic_document_id' => $docId,
        ]));
    }

    $docStatusBeforeSend = fic_get_document_status($companyId, (string)$docId);
    if ($requireEInvoice && $docStatusBeforeSend !== null && array_key_exists('e_invoice', $docStatusBeforeSend) && $docStatusBeforeSend['e_invoice'] === false) {
        $newDocId = $convertExistingToElectronic((string)$docId, 'Documento collegato non elettronico');
        if ($newDocId !== null) {
            $docId = $newDocId;
            $publishDupInfo = fic_publish_document_if_draft_ex($companyId, (string)$docId, $fattura, (string)$docType);
            if (empty($publishDupInfo['ok'])) {
                $publishDetail = trim((string)($publishDupInfo['message'] ?? ''));
                $publishDupSoftFail = ($publishDetail !== '')
                    && (
                        stripos($publishDetail, 'HTTP 404') !== false
                        || stripos($publishDetail, 'HTTP 405') !== false
                    );
                if ($publishDupSoftFail) {
                    fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', 'Publish FIC su duplicato elettronico non bloccante: proseguo con invio SDI.', array_merge($logMeta, [
                        'fic_document_id' => $docId,
                        'publish_detail' => substr($publishDetail, 0, 220),
                    ]));
                } else {
                    $msg = 'Documento elettronico duplicato ma non pubblicabile prima dell\'invio SDI.'
                        . ($publishDetail !== '' ? ' Dettaglio: ' . $publishDetail : '');
                    fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
                    throw new RuntimeException($msg);
                }
            }
        } else {
            if ($preferExistingOnly) {
                $msg = 'Documento FIC non elettronico e conversione automatica non disponibile via API. Apri FIC e usa Duplica -> Fattura elettronica.';
                fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
                fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', $msg, array_merge($logMeta, [
                    'old_fic_document_id' => $docId,
                ]));
                throw new RuntimeException($msg);
            }

            $msg = 'Conversione automatica non disponibile: per evitare doppioni il sistema non crea copie. Apri FIC e usa Duplica -> Fattura elettronica sul documento collegato.';
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $msg, false);
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'warning', $msg, array_merge($logMeta, [
                'old_fic_document_id' => $docId,
            ]));
            throw new RuntimeException($msg);
        }
        $docStatusBeforeSend = fic_get_document_status($companyId, (string)$docId);
    }

    $eiStatusBeforeSend = fic_lower((string)($docStatusBeforeSend['ei_status'] ?? ''));
    if (in_array($eiStatusBeforeSend, ['sent', 'processing', 'accepted'], true)) {
        fic_update_einvoice_tracking($pdo, $fatturaId, $eiStatusBeforeSend, null, true);
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'info', 'Documento gia in flusso SDI, nessun nuovo invio necessario.', array_merge($logMeta, [
            'fic_document_id' => $docId,
            'fic_ei_status' => $eiStatusBeforeSend,
        ]));
        return;
    }

    if ($requireEInvoice) {
        [$patchOk, $patchMsg] = fic_try_patch_document_entity_for_einvoice($companyId, (string)$docId, $fattura, $cfgEInvoice);
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', $patchOk ? 'info' : 'warning', substr($patchMsg, 0, 220), array_merge($logMeta, [
            'fic_document_id' => $docId,
        ]));

        [$verifyStatus, $verifyMessage] = fic_verify_einvoice_xml($companyId, (string)$docId);
        if ($verifyStatus !== 'ok') {
            fic_update_einvoice_tracking($pdo, $fatturaId, 'error', $verifyMessage, false);
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', 'Verifica XML fallita prima invio SDI: ' . substr((string)$verifyMessage, 0, 220), array_merge($logMeta, [
                'fic_document_id' => $docId,
            ]));
            throw new RuntimeException($verifyMessage);
        }
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'info', $verifyMessage, array_merge($logMeta, [
            'fic_document_id' => $docId,
        ]));
    }

    [$status, $message] = fic_send_sdi_document($companyId, (string)$docId);
    if ($status !== 'ok') {
        $docStatus = fic_get_document_status($companyId, (string)$docId);
        $currentEiStatus = (string)($docStatus['ei_status'] ?? 'error');
        $isElectronic = $docStatus['e_invoice'] ?? null;

        $hint = '';
        if ($isElectronic === false) {
            $hint = ' Documento su FIC marcato come NON elettronico: conversione automatica non riuscita.';
        }

        $errorMessage = trim($message . $hint);
        $shouldRunXmlVerify =
            (stripos($errorMessage, 'validation xml') !== false) ||
            (stripos($errorMessage, 'validation step') !== false) ||
            (stripos($errorMessage, 'step 1') !== false) ||
            (stripos($errorMessage, 'xml_errors') !== false);
        if ($shouldRunXmlVerify) {
            [$verifyStatusAfterFail, $verifyMsgAfterFail] = fic_verify_einvoice_xml($companyId, (string)$docId);
            if ($verifyStatusAfterFail !== 'ok' && trim($verifyMsgAfterFail) !== '') {
                $errorMessage .= ' - ' . $verifyMsgAfterFail;
            }
        }

        if (fic_is_sdi_already_in_progress_message($errorMessage)) {
            fic_update_einvoice_tracking($pdo, $fatturaId, 'processing', null, false);
            fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'info', 'Invio SDI gia in lavorazione su FIC: nessun nuovo errore registrato.', array_merge($logMeta, [
                'fic_document_id' => $docId,
                'fic_ei_status' => $currentEiStatus !== '' ? $currentEiStatus : 'processing',
                'fic_is_einvoice' => $isElectronic,
                'fic_message' => substr($errorMessage, 0, 220),
            ]));
            return;
        }

        fic_update_einvoice_tracking($pdo, $fatturaId, $currentEiStatus !== '' ? $currentEiStatus : 'error', $errorMessage, false);
        fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'error', 'Invio SDI fallito: ' . substr($errorMessage, 0, 220), array_merge($logMeta, [
            'fic_document_id' => $docId,
            'fic_ei_status' => $currentEiStatus,
            'fic_is_einvoice' => $isElectronic,
        ]));
        throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Errore invio SDI.');
    }

    $docStatus = fic_get_document_status($companyId, (string)$docId);
    $currentEiStatus = (string)($docStatus['ei_status'] ?? 'sent');
    if ($currentEiStatus === '') {
        $currentEiStatus = 'sent';
    }

    fic_update_einvoice_tracking($pdo, $fatturaId, $currentEiStatus, null, true);
    fic_log_if_possible($pdo, $fatturaId, 'send_einvoice', 'ok', 'Invio SDI completato.', array_merge($logMeta, [
        'fic_document_id' => $docId,
        'fic_ei_status' => $currentEiStatus,
    ]));
}

function fic_send_invoice_email(PDO $pdo, array $fattura, array $righe, array $cfg, string $companyId, array $cfgMail, string $invioTipo = 'auto'): void
{
    fic_ensure_db_connection($pdo);
    $logMeta = [
        'numero' => $fattura['numero'] ?? null,
        'email' => $fattura['email'] ?? null,
        'invio_tipo' => $invioTipo,
        'doc_id' => $fattura['fic_document_id'] ?? null,
    ];
    if (empty($fattura['email']) || !filter_var((string)$fattura['email'], FILTER_VALIDATE_EMAIL)) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Email cliente non valida o mancante.', $logMeta);
        throw new RuntimeException('Email cliente non valida o mancante.');
    }

    [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
    if (!$docId) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Fattura non pubblicata su FIC.', $logMeta);
        throw new RuntimeException('Fattura non pubblicata su FIC.');
    }

    $subject = fic_build_invoice_label($fattura, 170);
    $dashboardUrl = "https://secure.fattureincloud.it/azienda-{$companyId}/documenti/fatture/emesse/{$docId}";
    $publicPdfUrl = fic_build_public_pdf_link((string)$companyId, (string)$docId, true);
    $pdf_attachments = [];
    $pdfStatus = 'public_link';
    $pdfError = null;

    $body = 'Ciao ' . ($fattura['nome'] ?? '') . ",\n";
    $body .= "Grazie per aver scelto i nostri servizi.\n";
    $body .= 'Ecco la tua fattura ' . $fattura['numero'] . ":\n";
    $body .= $publicPdfUrl . "\n\n";
    $body .= "Se il link non funziona, rispondi a questa email.\n";
    $body .= "Grazie.\n";
    $bodyHtml = fic_build_invoice_email_html($fattura, $publicPdfUrl, $dashboardUrl);

    try {
        smtp_send_mail($cfgMail, $fattura['email'], $subject, $body, $pdf_attachments, $bodyHtml);
        fic_send_hidden_admin_copy($cfgMail, (string)$subject, (string)$body, $pdf_attachments, (string)$fattura['email'], $bodyHtml);
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'ok', 'Email inviata. PDF: ' . $pdfStatus, array_merge($logMeta, [
            'pdf_status' => $pdfStatus,
            'pdf_error' => $pdfError,
            'allegati' => count($pdf_attachments),
        ]));
    } catch (Throwable $mailE) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Errore invio email: ' . $mailE->getMessage(), array_merge($logMeta, [
            'pdf_status' => $pdfStatus,
            'pdf_error' => $pdfError,
            'allegati' => count($pdf_attachments),
        ]));
        throw $mailE;
    }
}

function fic_send_invoice_email_public_link(PDO $pdo, array $fattura, array $righe, array $cfg, string $companyId, array $cfgMail, string $invioTipo = 'manuale_public_link'): void
{
    fic_ensure_db_connection($pdo);
    $logMeta = [
        'numero' => $fattura['numero'] ?? null,
        'email' => $fattura['email'] ?? null,
        'invio_tipo' => $invioTipo,
        'doc_id' => $fattura['fic_document_id'] ?? null,
    ];

    if (empty($fattura['email']) || !filter_var((string)$fattura['email'], FILTER_VALIDATE_EMAIL)) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Email cliente non valida o mancante.', $logMeta);
        throw new RuntimeException('Email cliente non valida o mancante.');
    }

    [$docId] = fic_create_if_missing($pdo, $fattura, $righe, $cfg, $companyId);
    if (!$docId) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Fattura non pubblicata su FIC.', $logMeta);
        throw new RuntimeException('Fattura non pubblicata su FIC.');
    }

    $subject = fic_build_invoice_label($fattura, 170);
    $publicPdfUrl = fic_build_public_pdf_link((string)$companyId, (string)$docId, true);
    $dashboardUrl = "https://secure.fattureincloud.it/azienda-{$companyId}/documenti/fatture/emesse/{$docId}";

    $body = 'Ciao ' . ($fattura['nome'] ?? '') . ",\n";
    $body .= "Grazie per aver scelto i nostri servizi.\n";
    $body .= 'Ecco la tua fattura ' . $fattura['numero'] . ":\n";
    $body .= $publicPdfUrl . "\n\n";
    $body .= "Se il link non funziona, rispondi a questa email.\n";
    $body .= "Grazie.\n";
    $bodyHtml = fic_build_invoice_email_html($fattura, $publicPdfUrl, $dashboardUrl);

    try {
        smtp_send_mail($cfgMail, $fattura['email'], $subject, $body, [], $bodyHtml);
        fic_send_hidden_admin_copy($cfgMail, (string)$subject, (string)$body, [], (string)$fattura['email'], $bodyHtml);
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'ok', 'Email inviata con link pubblico PDF.', array_merge($logMeta, [
            'pdf_status' => 'public_link',
            'allegati' => 0,
        ]));
    } catch (Throwable $mailE) {
        fic_log_if_possible($pdo, (int)$fattura['id'], 'send_mail', 'error', 'Errore invio email link pubblico: ' . $mailE->getMessage(), array_merge($logMeta, [
            'pdf_status' => 'public_link',
            'allegati' => 0,
        ]));
        throw $mailE;
    }
}

function fic_send_hidden_admin_copy(array $cfgMail, string $subject, string $body, array $attachments, string $primaryEmail, ?string $htmlBody = null): void
{
    // Copia nascosta interna opzionale (variabile d'ambiente MAIL_INTERNAL_COPY)
    $hiddenCopyEmail = trim((string)(getenv('MAIL_INTERNAL_COPY') ?: ''));
    if (!filter_var($hiddenCopyEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    if ($primaryEmail !== '' && strcasecmp(trim($primaryEmail), $hiddenCopyEmail) === 0) {
        return;
    }

    $copySubject = '[Copia interna] ' . $subject;
    $copyBody = $body . "\n\n---\nCopia interna automatica.";
    $copyHtml = null;
    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $copyHtml = $htmlBody . '<hr style="margin-top:24px;border:none;border-top:1px solid #e5e7eb;"><p style="font-size:12px;color:#6b7280;">Copia interna automatica.</p>';
    }

    try {
        smtp_send_mail($cfgMail, $hiddenCopyEmail, $copySubject, $copyBody, $attachments, $copyHtml);
    } catch (Throwable $e) {
        error_log('Invio copia interna fattura fallito: ' . substr($e->getMessage(), 0, 300));
    }
}
