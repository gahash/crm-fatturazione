<?php
function fb_config(): array
{
    $secret = require __DIR__ . '/../../config/secret.php';
    return $secret['facebook'] ?? [];
}

function fb_token_path(): string
{
    return __DIR__ . '/../../tmp/facebook_token.json';
}

function fb_load_token(): ?array
{
    $path = fb_token_path();
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fb_save_token(array $data): void
{
    $path = fb_token_path();
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function fb_get_access_token(): string
{
    $token = fb_load_token();
    return (string)($token['access_token'] ?? '');
}

function fb_request(string $method, string $path, array $query = []): array
{
    $cfg = fb_config();
    $version = $cfg['api_version'] ?? 'v21.0';
    $baseUrl = 'https://graph.facebook.com/' . $version;

    $url = $baseUrl . $path;
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Facebook: errore cURL: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    return [
        'status' => $status,
        'body' => $response,
        'json' => $json,
    ];
}
