<?php

function fic_trace_api_event(array $event): void
{
    $callback = $GLOBALS['FIC_API_TRACE_CALLBACK'] ?? null;
    if (!is_callable($callback)) {
        return;
    }

    try {
        $callback($event);
    } catch (Throwable $e) {
        // Il tracing non deve mai interrompere il flusso API principale.
    }
}

function fic_project_root(): string
{
    $candidates = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
    }
    $candidates[] = dirname(__DIR__, 3);

    foreach ($candidates as $base) {
        if (is_file($base . '/config/secret.php')) {
            return $base;
        }
    }

    return dirname(__DIR__, 3);
}

function fic_config(): array
{
    $root = fic_project_root();
    $secretPath = $root . '/config/secret.php';
    if (!is_file($secretPath)) {
        throw new RuntimeException('Fatture in Cloud: file config/secret.php non trovato');
    }
    $secret = require $secretPath;
    return $secret['fattureincloud'] ?? [];
}

function fic_token_path(): string
{
    $root = fic_project_root();
    return $root . '/tmp/fic_token.json';
}

function fic_load_token(): ?array
{
    $path = fic_token_path();
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fic_get_access_token(): string
{
    // Priorita al token OAuth piu recente salvato su tmp (riconnessione).
    $token = fic_load_token();
    if (!empty($token['access_token'])) {
        // Se il token è scaduto e c'è un refresh_token, rinnova in modo trasparente.
        $expiresIn = (int)($token['expires_in'] ?? 0);
        $createdAt = (int)($token['created_at'] ?? 0);
        $isExpired = ($expiresIn > 0 && $createdAt > 0 && time() >= ($createdAt + $expiresIn - 60));
        if ($isExpired && !empty($token['refresh_token'])) {
            $refreshed = fic_do_refresh_token($token['refresh_token']);
            if ($refreshed !== null && !empty($refreshed['access_token'])) {
                return (string)$refreshed['access_token'];
            }
        }
        return (string)$token['access_token'];
    }

    $cfg = fic_config();
    if (!empty($cfg['access_token'])) {
        return (string)$cfg['access_token'];
    }

    return '';
}

/**
 * Rinnova il token OAuth tramite refresh_token e salva il nuovo token su disco.
 * Ritorna il nuovo array token o null in caso di errore.
 */
function fic_do_refresh_token(string $refreshToken): ?array
{
    $cfg = fic_config();
    $baseUrl = rtrim($cfg['base_url'] ?? 'https://api-v2.fattureincloud.it', '/');
    $clientId = $cfg['client_id'] ?? '';
    $clientSecret = $cfg['client_secret'] ?? '';

    if ($clientId === '' || $clientSecret === '') {
        return null;
    }

    $payload = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);

    $ch = curl_init($baseUrl . '/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }

    // Recupera il company_id dal token salvato precedentemente
    $existingToken = fic_load_token();
    $companyId = $existingToken['company_id'] ?? null;

    $newToken = [
        'access_token' => $data['access_token'],
        'refresh_token' => $data['refresh_token'] ?? $refreshToken,
        'token_type' => $data['token_type'] ?? 'Bearer',
        'expires_in' => $data['expires_in'] ?? null,
        'created_at' => time(),
        'company_id' => $companyId,
    ];

    $tokenFile = fic_token_path();
    $tokenDir = dirname($tokenFile);
    if (!is_dir($tokenDir)) {
        @mkdir($tokenDir, 0755, true);
    }
    @file_put_contents($tokenFile, json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $newToken;
}

function fic_get_company_id(): string
{
    $token = fic_load_token();
    if (!empty($token['company_id'])) {
        return (string)$token['company_id'];
    }

    $cfg = fic_config();
    if (!empty($cfg['company_id'])) {
        return (string)$cfg['company_id'];
    }

    return '';
}

function fic_request(string $method, string $path, ?array $payload = null): array
{
    $cfg = fic_config();
    $baseUrl = rtrim($cfg['base_url'] ?? 'https://api-v2.fattureincloud.it', '/');
    $token = fic_get_access_token();

    if ($token === '') {
        throw new RuntimeException('Fatture in Cloud: access_token mancante.');
    }

    static $lastCallAt = 0.0;
    $minIntervalSec = 0.60; // throttle piu conservativo per sync massivi (riduce 429)
    $maxAttempts = isset($GLOBALS['FIC_API_MAX_ATTEMPTS'])
        ? max(1, (int)$GLOBALS['FIC_API_MAX_ATTEMPTS'])
        : 7;
    $connectTimeoutSec = isset($GLOBALS['FIC_API_CONNECT_TIMEOUT'])
        ? max(1, (int)$GLOBALS['FIC_API_CONNECT_TIMEOUT'])
        : 8;
    $timeoutSec = isset($GLOBALS['FIC_API_TIMEOUT'])
        ? max($connectTimeoutSec + 1, (int)$GLOBALS['FIC_API_TIMEOUT'])
        : 22;
    $method = strtoupper($method);

    $url = $baseUrl . $path;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $now = microtime(true);
        $elapsed = $now - $lastCallAt;
        if ($elapsed < $minIntervalSec) {
            usleep((int)(($minIntervalSec - $elapsed) * 1000000));
        }

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        if ($payload !== null) {
            $jsonFlags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $body = json_encode($payload, $jsonFlags);
            if ($body === false) {
                throw new RuntimeException('Fatture in Cloud: payload JSON non valido: ' . json_last_error_msg());
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);

        $response = curl_exec($ch);
        $lastCallAt = microtime(true);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fic_trace_api_event([
                'method' => $method,
                'path' => $path,
                'payload' => $payload,
                'attempt' => $attempt,
                'status' => null,
                'headers' => '',
                'content_type' => '',
                'body' => '',
                'json' => null,
                'curl_error' => $err,
            ]);
            if ($attempt < $maxAttempts) {
                usleep((int)(pow(2, $attempt - 1) * 350000));
                continue;
            }
            throw new RuntimeException('Fatture in Cloud: errore cURL: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $json = null;
        if (stripos($contentType, 'application/json') !== false) {
            $json = json_decode($body, true);
        }

        fic_trace_api_event([
            'method' => $method,
            'path' => $path,
            'payload' => $payload,
            'attempt' => $attempt,
            'status' => $status,
            'headers' => $rawHeaders,
            'content_type' => $contentType,
            'body' => $body,
            'json' => $json,
            'curl_error' => null,
        ]);

        // Retry automatico su rate limit / errori temporanei server
        if (($status === 429 || $status >= 500) && $attempt < $maxAttempts) {
            $retryAfterSec = 0;
            if (preg_match('/Retry-After:\s*(\d+)/i', $rawHeaders, $m)) {
                $retryAfterSec = (int)$m[1];
            }

            $baseDelaySec = ($status === 429)
                ? (1.2 * pow(2, $attempt - 1))
                : (0.8 * pow(2, $attempt - 1));
            $delaySec = max($baseDelaySec, (float)$retryAfterSec);
            usleep((int)($delaySec * 1000000));
            continue;
        }

        // Refresh automatico token scaduto (401 invalid_token / token expired)
        if ($status === 401 && $attempt === 1) {
            $errorCode = trim((string)($json['error'] ?? ''));
            $isExpiredToken = in_array($errorCode, ['invalid_token', 'expired_token'], true)
                || stripos((string)($json['error_description'] ?? ''), 'expired') !== false;
            if ($isExpiredToken) {
                $existingToken = fic_load_token();
                $refreshToken = (string)($existingToken['refresh_token'] ?? '');
                if ($refreshToken !== '') {
                    $refreshed = fic_do_refresh_token($refreshToken);
                    if ($refreshed !== null && !empty($refreshed['access_token'])) {
                        $token = (string)$refreshed['access_token'];
                        // aggiorna l'header Authorization e riprova
                        $headers = array_filter($headers, static function (string $h): bool {
                            return stripos($h, 'Authorization:') !== 0;
                        });
                        $headers = array_values($headers);
                        $headers[] = 'Authorization: Bearer ' . $token;
                        usleep(300000);
                        continue;
                    }
                }
            }
        }

        return [
            'status' => $status,
            'headers' => $rawHeaders,
            'content_type' => $contentType,
            'body' => $body,
            'json' => $json,
        ];
    }

    throw new RuntimeException('Fatture in Cloud: errore richiesta API non recuperabile.');
}
