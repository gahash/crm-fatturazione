<?php
// modules/pagamenti/genera_fatture_elettroniche.php
// Sync su Fatture in Cloud delle fatture locali legate ai pagamenti Stripe riusciti.

require_once __DIR__ . '/../../auth.php';
require_login();
if (!has_admin_access()) {
    http_response_code(403);
    die('Accesso non autorizzato.');
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

function pagamenti_cfg_int(string $key, int $default, int $min = 1, ?int $max = null): int
{
    $raw = $GLOBALS[$key] ?? $default;
    $value = (int)$raw;
    if ($value < $min) {
        $value = $min;
    }
    if ($max !== null && $value > $max) {
        $value = $max;
    }
    return $value;
}

/**
 * Estrae un errore leggibile dalla risposta FIC.
 */
function pagamenti_fic_error_from_response(array $resp): string
{
    $status = (int)($resp['status'] ?? 0);
    $parts = [];

    $errorCode = trim((string)($resp['json']['error']['code'] ?? ''));
    $errorMsg = trim((string)($resp['json']['error']['message'] ?? ''));
    $genericMsg = trim((string)($resp['json']['message'] ?? ''));
    $body = trim((string)($resp['body'] ?? ''));

    if ($status > 0) {
        $parts[] = 'HTTP ' . $status;
    }
    if ($errorCode !== '') {
        $parts[] = $errorCode;
    }
    if ($errorMsg !== '') {
        $parts[] = $errorMsg;
    } elseif ($genericMsg !== '') {
        $parts[] = $genericMsg;
    } elseif ($body !== '') {
        $parts[] = substr($body, 0, 280);
    }

    if (!$parts) {
        return 'Errore Fatture in Cloud non dettagliato.';
    }

    return implode(' - ', $parts);
}

function pagamenti_is_usage_limit_error_message(string $msg): bool
{
    return fic_is_usage_limit_error_message($msg);
}

/**
 * Restituisce l'elenco colonne disponibili su tabella pagamenti.
 */
function pagamenti_get_pagamenti_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM pagamenti');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $cache[$name] = true;
            }
        }
    } catch (Throwable $e) {
        // Se non leggibile, lasciamo cache vuota e saltiamo update tabella pagamenti.
    }

    return $cache;
}

/**
 * Aggiorna pagamenti con dati sync FIC solo sulle colonne davvero presenti.
 * Ritorna warning testuale se update saltato/fallito, altrimenti stringa vuota.
 */
function pagamenti_update_pagamento_sync(PDO $pdo, array $pagamentiColumns, string $stripeId, string $docId, string $status, string $numero): string
{
    $setParts = [];
    $params = [
        ':stripe_id' => $stripeId,
    ];

    if (isset($pagamentiColumns['fic_document_id'])) {
        $setParts[] = 'fic_document_id = :doc_id';
        $params[':doc_id'] = $docId;
    }
    if (isset($pagamentiColumns['fic_status'])) {
        $setParts[] = 'fic_status = :status';
        $params[':status'] = $status;
    }
    if (isset($pagamentiColumns['numero_fattura'])) {
        $setParts[] = 'numero_fattura = :numero';
        $params[':numero'] = $numero;
    }
    if (isset($pagamentiColumns['fic_last_sync'])) {
        $setParts[] = 'fic_last_sync = NOW()';
    }

    if (!$setParts) {
        return 'Colonne sync tabella pagamenti non presenti: aggiornamento pagamenti saltato.';
    }

    try {
        $sql = 'UPDATE pagamenti SET ' . implode(', ', $setParts) . ' WHERE stripe_id = :stripe_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return '';
    } catch (Throwable $e) {
        return 'Aggiornamento tabella pagamenti saltato: ' . substr((string)$e->getMessage(), 0, 180);
    }
}

function pagamenti_extract_iso_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
        return $m[0];
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', $ts);
}

function pagamenti_resolve_payment_dates(array $row): array
{
    $createdAt = trim((string)($row['pagamento_created_at'] ?? ''));
    $paymentDate = pagamenti_extract_iso_date($createdAt);
    if ($paymentDate === '') {
        $paymentDate = pagamenti_extract_iso_date((string)($row['data_pagamento'] ?? ''));
    }
    if ($paymentDate === '') {
        $paymentDate = pagamenti_extract_iso_date((string)($row['data_emissione'] ?? ''));
    }
    if ($paymentDate === '') {
        $paymentDate = date('Y-m-d');
    }

    $paymentDateTime = '';
    if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts !== false) {
            $paymentDateTime = date('Y-m-d H:i:s', $ts);
        }
    }
    if ($paymentDateTime === '') {
        $paymentDateTime = $paymentDate . ' 00:00:00';
    }

    return [$paymentDate, $paymentDateTime];
}

function pagamenti_sync_local_invoice_dates(PDO $pdo, int $fatturaId, string $paymentDate, string $paymentDateTime): void
{
    if ($fatturaId <= 0 || $paymentDate === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare('UPDATE fatture SET data_emissione = :d, data_pagamento = :dt WHERE id = :id');
        $stmt->execute([
            ':d' => $paymentDate,
            ':dt' => $paymentDateTime,
            ':id' => $fatturaId,
        ]);
    } catch (Throwable $e) {
        // no-op: non bloccare la sincronizzazione se il DB locale non consente update data.
    }
}

function pagamenti_normalize_doc_token(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
    return is_string($normalized) ? $normalized : '';
}

function pagamenti_doc_matches_loose(array $doc, string $localNumber): bool
{
    $localNumber = trim($localNumber);
    if ($localNumber === '') {
        return false;
    }

    $localNorm = pagamenti_normalize_doc_token($localNumber);
    $docNumber = (string)($doc['number'] ?? '');
    $docSubject = (string)($doc['visible_subject'] ?? ($doc['subject'] ?? ''));
    $docNumberNorm = pagamenti_normalize_doc_token($docNumber);
    $docSubjectNorm = pagamenti_normalize_doc_token($docSubject);

    if ($localNorm !== '') {
        if ($docNumberNorm === $localNorm) {
            return true;
        }
        if ($docSubjectNorm !== '' && strpos($docSubjectNorm, $localNorm) !== false) {
            return true;
        }
    }

    if (preg_match('/(\d+)$/', $localNumber, $m)) {
        $tail = ltrim((string)$m[1], '0');
        if ($tail === '') {
            $tail = '0';
        }
        $docDigits = preg_replace('/\D+/', '', $docNumber);
        if (is_string($docDigits)) {
            $docTail = ltrim($docDigits, '0');
            if ($docTail === '') {
                $docTail = '0';
            }
            if ($docTail === $tail) {
                return true;
            }
        }
    }

    return false;
}

function pagamenti_find_existing_doc_loose(string $companyId, string $docType, string $localNumber): array
{
    $localNumber = trim($localNumber);
    if ($localNumber === '') {
        return [null, null];
    }

    $queries = [
        ['type' => $docType, 'q' => $localNumber, 'per_page' => 100],
        ['q' => $localNumber, 'per_page' => 100],
    ];

    foreach ($queries as $baseQuery) {
        $maxPages = pagamenti_cfg_int('PAGAMENTI_FIC_LOOSE_MAX_PAGES', 3, 1, 30);
        for ($page = 1; $page <= $maxPages; $page++) {
            $query = $baseQuery;
            $query['page'] = $page;
            $path = "/c/{$companyId}/issued_documents?" . http_build_query($query);
            try {
                $resp = fic_request('GET', $path);
                if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                    break;
                }

                $documents = fic_extract_documents($resp);
                if (!$documents) {
                    break;
                }

                foreach ($documents as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }
                    $docId = (string)($doc['id'] ?? '');
                    if ($docId === '') {
                        continue;
                    }
                    if (pagamenti_doc_matches_loose($doc, $localNumber)) {
                        return [$docId, (string)($doc['status'] ?? '')];
                    }
                }

                $lastPage = (int)($resp['json']['pagination']['last_page'] ?? 0);
                if ($lastPage > 0 && $page >= $lastPage) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    return [null, null];
}

function pagamenti_normalize_name(string $value): string
{
    $v = strtolower(trim($value));
    $v = preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/[^a-z0-9 ]+/', '', (string)$v);
    return trim((string)$v);
}

function pagamenti_doc_extract_amount(array $doc): ?float
{
    $candidates = [
        $doc['amount_gross'] ?? null,
        $doc['gross_amount'] ?? null,
        $doc['amount_net'] ?? null,
        $doc['net_amount'] ?? null,
        $doc['amount_due'] ?? null,
        $doc['total'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }
        $value = (float)$candidate;
        if ($value > 0) {
            return round($value, 2);
        }
    }

    return null;
}

function pagamenti_doc_extract_date(array $doc): string
{
    $raw = (string)($doc['date'] ?? ($doc['created_at'] ?? ($doc['date_issue'] ?? '')));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
        return $m[0];
    }
    return '';
}

function pagamenti_doc_extract_entity_name(array $doc): string
{
    $name = (string)($doc['entity']['name'] ?? ($doc['entity_name'] ?? ($doc['client_name'] ?? '')));
    return $name;
}

function pagamenti_doc_matches_fingerprint(array $doc, array $row): bool
{
    $normalizeVat = static function (string $v): string {
        $v = strtoupper(trim($v));
        $v = preg_replace('/[^A-Z0-9]+/', '', $v);
        return is_string($v) ? $v : '';
    };

    $localVat = $normalizeVat((string)($row['piva'] ?? ''));
    $docVat = $normalizeVat((string)($doc['entity']['vat_number'] ?? ($doc['vat_number'] ?? '')));
    if ($localVat !== '' && $docVat !== '' && $localVat !== $docVat) {
        return false;
    }

    $localEmail = strtolower(trim((string)($row['email'] ?? '')));
    $docEmail = strtolower(trim((string)($doc['entity']['email'] ?? ($doc['email'] ?? ''))));
    if ($localEmail !== '' && $docEmail !== '' && $localEmail !== $docEmail) {
        return false;
    }

    $localName = pagamenti_normalize_name((string)($row['azienda'] ?? ($row['cliente_nome'] ?? '')));
    if ($localName === '') {
        $localName = pagamenti_normalize_name((string)($row['cliente_nome'] ?? ''));
    }

    $docName = pagamenti_normalize_name(pagamenti_doc_extract_entity_name($doc));
    if ($localName !== '' && $docName !== '') {
        if (strpos($docName, $localName) === false && strpos($localName, $docName) === false) {
            return false;
        }
    }

    $localTotal = round((float)($row['totale'] ?? 0), 2);
    if ($localTotal <= 0) {
        $localTotal = round(((float)($row['pagamento_importo'] ?? 0)) / 100, 2);
    }
    $docTotal = pagamenti_doc_extract_amount($doc);
    if ($docTotal === null || $localTotal <= 0 || abs($docTotal - $localTotal) > 0.02) {
        return false;
    }

    $localDate = pagamenti_extract_iso_date((string)($row['data_emissione'] ?? ''));
    $docDate = pagamenti_doc_extract_date($doc);
    if ($localDate !== '') {
        if ($docDate === '') {
            return false;
        }
        if ($localDate !== $docDate) {
            return false;
        }
    }

    return true;
}

function pagamenti_find_existing_doc_by_fingerprint(string $companyId, string $docType, array $row): array
{
    $terms = [];
    $vat = trim((string)($row['piva'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $n1 = trim((string)($row['azienda'] ?? ''));
    $n2 = trim((string)($row['cliente_nome'] ?? ''));
    if ($vat !== '') {
        $terms[] = $vat;
    }
    if ($email !== '') {
        $terms[] = $email;
    }
    if ($n1 !== '') {
        $terms[] = $n1;
    }
    if ($n2 !== '' && strcasecmp($n2, $n1) !== 0) {
        $terms[] = $n2;
    }

    if (!$terms) {
        $terms[] = (string)($row['numero'] ?? '');
    }

    $terms = array_values(array_unique(array_filter($terms, static function ($v) {
        return trim((string)$v) !== '';
    })));

    foreach ($terms as $term) {
        $term = trim($term);
        if ($term === '') {
            continue;
        }
        $maxPages = pagamenti_cfg_int('PAGAMENTI_FIC_FINGERPRINT_MAX_PAGES', 2, 1, 25);
        for ($page = 1; $page <= $maxPages; $page++) {
            $query = http_build_query([
                'type' => $docType,
                'q' => $term,
                'per_page' => 100,
                'page' => $page,
            ]);
            $path = "/c/{$companyId}/issued_documents?{$query}";
            try {
                $resp = fic_request('GET', $path);
                if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                    break;
                }
                $documents = fic_extract_documents($resp);
                if (!$documents) {
                    break;
                }
                foreach ($documents as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }
                    $docId = (string)($doc['id'] ?? '');
                    if ($docId === '') {
                        continue;
                    }
                    if (pagamenti_doc_matches_fingerprint($doc, $row)) {
                        return [$docId, (string)($doc['status'] ?? '')];
                    }
                }

                $lastPage = (int)($resp['json']['pagination']['last_page'] ?? 0);
                if ($lastPage > 0 && $page >= $lastPage) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    // Ultima chance: scansione pagine recenti senza query testuale (solo se abilitata).
    $enableFallbackScan = !empty($GLOBALS['PAGAMENTI_FIC_ENABLE_FALLBACK_SCAN']);
    $maxPages = $enableFallbackScan ? pagamenti_cfg_int('PAGAMENTI_FIC_FALLBACK_MAX_PAGES', 1, 1, 10) : 0;
    for ($page = 1; $page <= $maxPages; $page++) {
        try {
            $resp = fic_request('GET', "/c/{$companyId}/issued_documents?" . http_build_query([
                'type' => $docType,
                'per_page' => 100,
                'page' => $page,
            ]));
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                break;
            }
            $documents = fic_extract_documents($resp);
            if (!$documents) {
                break;
            }
            foreach ($documents as $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $docId = (string)($doc['id'] ?? '');
                if ($docId === '') {
                    continue;
                }
                if (pagamenti_doc_matches_fingerprint($doc, $row)) {
                    return [$docId, (string)($doc['status'] ?? '')];
                }
            }

            $lastPage = (int)($resp['json']['pagination']['last_page'] ?? 0);
            if ($lastPage > 0 && $page >= $lastPage) {
                break;
            }
        } catch (Throwable $e) {
            break;
        }
    }

    return [null, null];
}

$results = [];
$candidateTotal = 0;
$batchSize = 5;
$remainingAfterBatch = 0;

try {
    $dbPath = __DIR__ . '/../../config/db.php';
    if (!is_file($dbPath)) {
        throw new RuntimeException('Configurazione database non trovata sul server.');
    }
    $pdo = require $dbPath;

    $ficApiPath = __DIR__ . '/../fatture/actions/fic_api.php';
    $ficHelpersPath = __DIR__ . '/../fatture/actions/fic_mail_helpers.php';

    if (!is_file($ficApiPath) || !is_file($ficHelpersPath)) {
        throw new RuntimeException('Modulo Fatture in Cloud incompleto sul server: file actions mancanti. Contatta assistenza per il deploy del modulo fatture.');
    }

    require_once $ficApiPath;
    require_once $ficHelpersPath;

    // Parametri conservativi per evitare timeout/503 su hosting condiviso.
    $deepSearch = isset($_GET['deep']) && (string)$_GET['deep'] === '1';
    $GLOBALS['FIC_API_MAX_ATTEMPTS'] = $deepSearch ? 4 : 2;
    $GLOBALS['FIC_API_CONNECT_TIMEOUT'] = $deepSearch ? 7 : 5;
    $GLOBALS['FIC_API_TIMEOUT'] = $deepSearch ? 20 : 14;
    $GLOBALS['PAGAMENTI_FIC_LOOSE_MAX_PAGES'] = $deepSearch ? 8 : 3;
    $GLOBALS['PAGAMENTI_FIC_FINGERPRINT_MAX_PAGES'] = $deepSearch ? 6 : 2;
    $GLOBALS['PAGAMENTI_FIC_ENABLE_FALLBACK_SCAN'] = $deepSearch ? 1 : 0;
    $GLOBALS['PAGAMENTI_FIC_FALLBACK_MAX_PAGES'] = $deepSearch ? 3 : 1;

    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = fic_get_company_id();
    }

    if ($companyId === '') {
        throw new RuntimeException('company_id Fatture in Cloud mancante. Ricollegare FIC da Impostazioni.');
    }

    if (fic_get_access_token() === '') {
        throw new RuntimeException('access_token Fatture in Cloud mancante. Ricollegare FIC da Impostazioni.');
    }

    // Validazione veloce token prima del loop massivo.
    $probe = fic_request('GET', "/c/{$companyId}/info/company");
    $probeError = pagamenti_fic_error_from_response($probe);
    if (($probe['status'] ?? 0) === 401 || stripos($probeError, 'invalid_token') !== false) {
        throw new RuntimeException('Token Fatture in Cloud non valido/scaduto. Esegui nuovamente la connessione OAuth.');
    }
    if (pagamenti_is_usage_limit_error_message($probeError)) {
        throw new RuntimeException('Limite API Fatture in Cloud raggiunto. Attendi il reset quota o aumenta il piano API su FIC, poi rilancia il batch.');
    }

    $batchSize = (int)($_GET['batch'] ?? 1);
    if ($batchSize < 1) {
        $batchSize = 1;
    }
    if ($batchSize > 5) {
        $batchSize = 5;
    }

    $countSql = "
        SELECT COUNT(*)
        FROM pagamenti p
        LEFT JOIN fatture f ON f.id = (
            SELECT f2.id
            FROM fatture f2
            WHERE f2.stripe_payment_intent_id = p.stripe_id
              AND f2.document_type = 'invoice'
              AND (f2.fic_document_id IS NULL OR f2.fic_document_id = '')
              AND (f2.fic_status IS NULL OR f2.fic_status <> 'duplicate_unresolved')
            ORDER BY f2.id DESC
            LIMIT 1
        )
        WHERE p.stato = 'succeeded'
          AND NOT EXISTS (
            SELECT 1
            FROM fatture fx
            WHERE fx.stripe_payment_intent_id = p.stripe_id
              AND fx.document_type = 'invoice'
              AND fx.fic_document_id IS NOT NULL
              AND fx.fic_document_id <> ''
          )
          AND NOT EXISTS (
            SELECT 1
            FROM fatture fd
            WHERE fd.stripe_payment_intent_id = p.stripe_id
              AND fd.document_type = 'invoice'
              AND fd.fic_status = 'duplicate_unresolved'
          )
    ";
    $candidateTotal = (int)$pdo->query($countSql)->fetchColumn();

    // Una riga per payment intent Stripe senza documento FIC collegato.
    // Se esiste gia una fattura collegata a FIC per lo stesso payment intent, la riga viene esclusa.
    $sql = "
        SELECT
            p.id AS pagamento_id,
            p.stripe_id,
            p.importo AS pagamento_importo,
            p.created_at AS pagamento_created_at,
            c.nome AS cliente_nome,
            c.email,
            c.piva,
            c.azienda,
            c.indirizzo,
            c.codice_sdi,
            c.pec,
            f.id AS fattura_id,
            f.cliente_id,
            f.numero,
            f.document_type,
            f.data_emissione,
            f.data_scadenza,
            f.data_pagamento,
            f.stato,
            f.totale,
            f.note,
            f.fic_document_id,
            f.fic_status,
            f.stripe_payment_intent_id
        FROM pagamenti p
        LEFT JOIN clienti c ON c.id = p.cliente_id
        LEFT JOIN fatture f ON f.id = (
            SELECT f2.id
            FROM fatture f2
            WHERE f2.stripe_payment_intent_id = p.stripe_id
              AND f2.document_type = 'invoice'
              AND (f2.fic_document_id IS NULL OR f2.fic_document_id = '')
              AND (f2.fic_status IS NULL OR f2.fic_status <> 'duplicate_unresolved')
            ORDER BY f2.id DESC
            LIMIT 1
        )
        WHERE p.stato = 'succeeded'
          AND NOT EXISTS (
            SELECT 1
            FROM fatture fx
            WHERE fx.stripe_payment_intent_id = p.stripe_id
              AND fx.document_type = 'invoice'
              AND fx.fic_document_id IS NOT NULL
              AND fx.fic_document_id <> ''
          )
          AND NOT EXISTS (
            SELECT 1
            FROM fatture fd
            WHERE fd.stripe_payment_intent_id = p.stripe_id
              AND fd.document_type = 'invoice'
              AND fd.fic_status = 'duplicate_unresolved'
          )
        ORDER BY p.created_at ASC, p.id ASC
        LIMIT " . (int)$batchSize . "
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagamentiColumns = pagamenti_get_pagamenti_columns($pdo);

    $tokenInvalid = false;
    $usageLimitReached = false;
    $processedRows = 0;

    foreach ($rows as $index => $row) {
        $processedRows++;
        $clienteNome = trim((string)($row['cliente_nome'] ?? ''));
        if ($clienteNome === '') {
            $clienteNome = 'Cliente #' . (string)($row['cliente_id'] ?? $row['pagamento_id'] ?? 'N/D');
        }

        $importoCents = (int)($row['pagamento_importo'] ?? 0);

        if (empty($row['fattura_id'])) {
            $results[] = [
                'cliente' => $clienteNome,
                'importo' => $importoCents,
                'esito' => 'ERRORE',
                'msg' => 'Fattura locale mancante per il pagamento Stripe. Eseguire prima "Sincronizza Stripe".',
                'fic_id' => null,
            ];
            continue;
        }

        $fatturaId = (int)$row['fattura_id'];
        [$paymentDate, $paymentDateTime] = pagamenti_resolve_payment_dates($row);
        $row['data_emissione'] = $paymentDate;
        $row['data_pagamento'] = $paymentDateTime;
        if (empty($row['data_scadenza'])) {
            $row['data_scadenza'] = $paymentDate;
        }
        pagamenti_sync_local_invoice_dates($pdo, $fatturaId, $paymentDate, $paymentDateTime);

        $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
        $righeStmt->execute([':id' => $fatturaId]);
        $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        try {
            $docType = trim((string)($row['document_type'] ?? 'invoice'));
            if ($docType === '') {
                $docType = 'invoice';
            }

            // Pre-link: se il documento esiste gia su FIC, colleghiamo senza tentare nuova creazione.
            $existingDocId = null;
            $existingStatus = null;
            [$preDocId, $preStatus] = fic_find_existing_document_by_number($companyId, $docType, $row, true);
            if ($preDocId !== null && $preDocId !== '') {
                $existingDocId = (string)$preDocId;
                $existingStatus = (string)$preStatus;
            } else {
                [$preDocId2, $preStatus2] = pagamenti_find_existing_doc_loose($companyId, $docType, (string)($row['numero'] ?? ''));
                if ($preDocId2 !== null && $preDocId2 !== '') {
                    $existingDocId = (string)$preDocId2;
                    $existingStatus = (string)$preStatus2;
                } else {
                    [$preDocId3, $preStatus3] = pagamenti_find_existing_doc_by_fingerprint($companyId, $docType, $row);
                    if ($preDocId3 !== null && $preDocId3 !== '') {
                        $existingDocId = (string)$preDocId3;
                        $existingStatus = (string)$preStatus3;
                    }
                }
            }

            if ($existingDocId !== null && $existingDocId !== '') {
                try {
                    $updF = $pdo->prepare('UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id');
                    $updF->execute([
                        ':doc_id' => $existingDocId,
                        ':status' => ($existingStatus !== '' ? $existingStatus : 'linked'),
                        ':id' => $fatturaId,
                    ]);
                } catch (Throwable $ignore) {
                    // no-op: non bloccare il collegamento lato risultato utente.
                }

                $pagamentiWarn = pagamenti_update_pagamento_sync(
                    $pdo,
                    $pagamentiColumns,
                    (string)($row['stripe_id'] ?? ''),
                    $existingDocId,
                    ($existingStatus !== '' ? $existingStatus : 'linked'),
                    (string)($row['numero'] ?? '')
                );

                $results[] = [
                    'cliente' => $clienteNome,
                    'importo' => $importoCents,
                    'esito' => 'OK',
                    'msg' => 'Documento gia presente su FIC: collegato.'
                        . ($pagamentiWarn !== '' ? ' (' . $pagamentiWarn . ')' : ''),
                    'fic_id' => $existingDocId,
                ];
                continue;
            }

            $syncCfg = $cfg;
            $syncCfg['force_e_invoice'] = false;
            $syncCfg['allow_duplicate_force_create'] = true;
            $syncCfg['strict_no_link_on_duplicate'] = false;

            [$docId, $status] = fic_create_if_missing($pdo, $row, $righe, $syncCfg, $companyId);
            if (empty($docId)) {
                throw new RuntimeException('Documento FIC non creato.');
            }

            fic_publish_document_if_draft($companyId, (string)$docId);
            $pagamentiWarn = pagamenti_update_pagamento_sync(
                $pdo,
                $pagamentiColumns,
                (string)($row['stripe_id'] ?? ''),
                (string)$docId,
                (string)($status ?? 'created'),
                (string)($row['numero'] ?? '')
            );

            $results[] = [
                'cliente' => $clienteNome,
                'importo' => $importoCents,
                'esito' => 'OK',
                'msg' => 'Fattura #' . (string)($row['numero'] ?? $fatturaId) . ' sincronizzata su FIC'
                    . ($pagamentiWarn !== '' ? ' (' . $pagamentiWarn . ')' : ''),
                'fic_id' => (string)$docId,
            ];
        } catch (Throwable $e) {
            $msg = trim((string)$e->getMessage());
            if ($msg === '') {
                $msg = 'Errore non specificato durante la sincronizzazione FIC.';
            }
            $esitoRow = 'ERRORE';

            if (strpos($msg, 'FIC_DUPLICATE_NOT_RECOVERABLE:') === 0) {
                $docType = trim((string)($row['document_type'] ?? 'invoice'));
                if ($docType === '') {
                    $docType = 'invoice';
                }

                [$existingDocId, $existingStatus] = fic_find_existing_document_by_number($companyId, $docType, $row, true);
                if ($existingDocId === null || $existingDocId === '') {
                    [$existingDocId, $existingStatus] = pagamenti_find_existing_doc_loose($companyId, $docType, (string)($row['numero'] ?? ''));
                }
                if ($existingDocId === null || $existingDocId === '') {
                    [$existingDocId, $existingStatus] = pagamenti_find_existing_doc_by_fingerprint($companyId, $docType, $row);
                }
                if ($existingDocId !== null && $existingDocId !== '') {
                    try {
                        $updF = $pdo->prepare('UPDATE fatture SET fic_document_id = :doc_id, fic_status = :status, fic_synced_at = NOW() WHERE id = :id');
                        $updF->execute([
                            ':doc_id' => (string)$existingDocId,
                            ':status' => (string)($existingStatus ?? 'linked'),
                            ':id' => $fatturaId,
                        ]);
                    } catch (Throwable $ignore) {
                        // Non bloccare la riga: il collegamento su FIC è stato trovato.
                    }

                    $pagamentiWarn = pagamenti_update_pagamento_sync(
                        $pdo,
                        $pagamentiColumns,
                        (string)($row['stripe_id'] ?? ''),
                        (string)$existingDocId,
                        (string)($existingStatus ?? 'linked'),
                        (string)($row['numero'] ?? '')
                    );

                    $results[] = [
                        'cliente' => $clienteNome,
                        'importo' => $importoCents,
                        'esito' => 'OK',
                        'msg' => 'Duplicato già presente su FIC: collegato documento esistente.'
                            . ($pagamentiWarn !== '' ? ' (' . $pagamentiWarn . ')' : ''),
                        'fic_id' => (string)$existingDocId,
                    ];
                    continue;
                }

                // Se non troviamo un duplicato collegabile, tentiamo una creazione forzata
                // con numero tecnico univoco per evitare collisioni.
                try {
                    $retryRow = $row;
                    $retryRow['numero'] = trim((string)($row['numero'] ?? '')) . '-R' . (string)$fatturaId;
                    $retryCfg = $cfg;
                    $retryCfg['force_e_invoice'] = false;
                    $retryCfg['allow_duplicate_force_create'] = true;
                    $retryCfg['strict_no_link_on_duplicate'] = false;

                    [$retryDocId, $retryStatus] = fic_create_if_missing($pdo, $retryRow, $righe, $retryCfg, $companyId);
                    if (!empty($retryDocId)) {
                        fic_publish_document_if_draft($companyId, (string)$retryDocId);

                        $pagamentiWarn = pagamenti_update_pagamento_sync(
                            $pdo,
                            $pagamentiColumns,
                            (string)($row['stripe_id'] ?? ''),
                            (string)$retryDocId,
                            (string)($retryStatus ?? 'created'),
                            (string)($row['numero'] ?? '')
                        );

                        $results[] = [
                            'cliente' => $clienteNome,
                            'importo' => $importoCents,
                            'esito' => 'OK',
                            'msg' => 'Creata su FIC con fallback univoco dopo errore duplicato.'
                                . ($pagamentiWarn !== '' ? ' (' . $pagamentiWarn . ')' : ''),
                            'fic_id' => (string)$retryDocId,
                        ];
                        continue;
                    }
                } catch (Throwable $retryErr) {
                    // Se anche il fallback fallisce, passiamo alla gestione SKIP.
                }

                // Evita loop infinito sui batch successivi.
                try {
                    $flagDup = $pdo->prepare("UPDATE fatture SET fic_status = 'duplicate_unresolved', fic_synced_at = NOW() WHERE id = :id");
                    $flagDup->execute([':id' => $fatturaId]);
                    $msg = 'Duplicato non collegabile automaticamente. Contrassegnata come duplicate_unresolved (saltata nei prossimi batch).';
                    $esitoRow = 'SKIP';
                } catch (Throwable $ignore) {
                    // no-op
                }
            }

            $results[] = [
                'cliente' => $clienteNome,
                'importo' => $importoCents,
                'esito' => $esitoRow,
                'msg' => substr($msg, 0, 420),
                'fic_id' => null,
            ];

            if (stripos($msg, 'invalid_token') !== false) {
                $tokenInvalid = true;
            }
            if (pagamenti_is_usage_limit_error_message($msg)) {
                $usageLimitReached = true;
            }

            if ($tokenInvalid) {
                $remaining = count($rows) - ($index + 1);
                if ($remaining > 0) {
                    $results[] = [
                        'cliente' => '-',
                        'importo' => 0,
                        'esito' => 'ERRORE',
                        'msg' => 'Elaborazione interrotta: token FIC non valido/scaduto (' . $remaining . ' elementi non elaborati).',
                        'fic_id' => null,
                    ];
                }
                break;
            }

            if ($usageLimitReached) {
                $remaining = count($rows) - ($index + 1);
                if ($remaining > 0) {
                    $results[] = [
                        'cliente' => '-',
                        'importo' => 0,
                        'esito' => 'ERRORE',
                        'msg' => 'Elaborazione interrotta: limite API Fatture in Cloud raggiunto (' . $remaining . ' elementi non elaborati).',
                        'fic_id' => null,
                    ];
                }
                break;
            }
        }

        usleep(200000);
    }

    $remainingAfterBatch = max(0, $candidateTotal - $processedRows);
} catch (Throwable $e) {
    $results[] = [
        'cliente' => '-',
        'importo' => 0,
        'esito' => 'ERRORE',
        'msg' => $e->getMessage(),
        'fic_id' => null,
    ];
    $remainingAfterBatch = max(0, $candidateTotal - count($results));
}

$totalRows = count($results);
$okRows = 0;
$skipRows = 0;
$errorRows = 0;
foreach ($results as $r) {
    if (($r['esito'] ?? '') === 'OK') {
        $okRows++;
    } elseif (($r['esito'] ?? '') === 'SKIP') {
        $skipRows++;
    } else {
        $errorRows++;
    }
}

$summaryClass = 'warning';
$summaryText = 'Elaborazione completata con errori.';
if ($totalRows === 0) {
    $summaryClass = 'info';
    $summaryText = 'Nessun pagamento da elaborare.';
} elseif ($errorRows === 0 && $skipRows === 0) {
    $summaryClass = 'success';
    $summaryText = 'Elaborazione completata con successo.';
} elseif ($errorRows === 0 && $skipRows > 0) {
    $summaryClass = 'warning';
    $summaryText = 'Elaborazione completata con elementi saltati.';
} elseif ($okRows === 0) {
    $summaryClass = 'error';
    $summaryText = 'Elaborazione completata: nessuna fattura sincronizzata.';
}

$headerPath = __DIR__ . '/../../layouts/header.php';
if (is_file($headerPath)) {
    require $headerPath;
} else {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Pagamenti</title></head><body>';
}
?>
<div class="wrap">
    <h2>Risultato generazione fatture elettroniche da Stripe</h2>
    <div class="alert <?php echo htmlspecialchars($summaryClass); ?>" style="margin-bottom:12px;">
        <strong><?php echo htmlspecialchars($summaryText); ?></strong>
        <div style="margin-top:6px;">Elaborati in questo batch: <?php echo (int)$totalRows; ?> | OK: <?php echo (int)$okRows; ?> | Saltati: <?php echo (int)$skipRows; ?> | Errori: <?php echo (int)$errorRows; ?></div>
        <div style="margin-top:4px;">Candidati iniziali: <?php echo (int)$candidateTotal; ?> | Batch size: <?php echo (int)$batchSize; ?> | Restanti stimati: <?php echo (int)$remainingAfterBatch; ?></div>
    </div>
    <table class="clienti-table">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Importo</th>
                <th>Esito</th>
                <th>Messaggio</th>
                <th>ID FIC</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars((string)$row['cliente']); ?></td>
                <td>&euro;<?php echo number_format(((int)$row['importo']) / 100, 2, ',', '.'); ?></td>
                <td><?php
                    if (($row['esito'] ?? '') === 'OK') {
                        echo '<span style="color:green">OK</span>';
                    } elseif (($row['esito'] ?? '') === 'SKIP') {
                        echo '<span style="color:#b45309">SKIP</span>';
                    } else {
                        echo '<span style="color:red">ERRORE</span>';
                    }
                ?></td>
                <td><?php echo htmlspecialchars((string)$row['msg']); ?></td>
                <td><?php echo !empty($row['fic_id']) ? htmlspecialchars((string)$row['fic_id']) : '-'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($remainingAfterBatch > 0): ?>
        <div style="margin-top:12px;">
            <a href="genera_fatture_elettroniche.php?batch=<?php echo (int)$batchSize; ?>" class="btn accent-green">Elabora prossimo batch (<?php echo (int)$batchSize; ?>)</a>
        </div>
    <?php endif; ?>
    <a href="index.php" class="btn secondary">&larr; Torna ai pagamenti</a>
</div>
<?php
$footerPath = __DIR__ . '/../../layouts/footer.php';
if (is_file($footerPath)) {
    require $footerPath;
} else {
    echo '</body></html>';
}
?>
