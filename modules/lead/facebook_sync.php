<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/facebook_api.php';

$cfg = fb_config();
$formId = $cfg['lead_form_id'] ?? '';
$accessToken = fb_get_access_token();

if ($formId === '' || $accessToken === '') {
    http_response_code(400);
    echo 'Facebook: configurazione o token mancante.';
    exit;
}

$limit = 50;
$after = $_GET['after'] ?? null;
$imported = 0;
$skipped = 0;

function mapLeadField(array $fieldData): array
{
    $map = [
        'full_name' => null,
        'first_name' => null,
        'last_name' => null,
        'email' => null,
        'phone_number' => null,
        'city' => null,
    ];

    foreach ($fieldData as $field) {
        $name = strtolower((string)($field['name'] ?? ''));
        $values = $field['values'] ?? [];
        $value = is_array($values) && count($values) ? (string)$values[0] : '';
        if ($name === '') continue;
        if (array_key_exists($name, $map)) {
            $map[$name] = $value;
        }
    }

    if ($map['first_name'] === null && $map['full_name']) {
        $parts = preg_split('/\s+/', trim($map['full_name']));
        $map['first_name'] = $parts[0] ?? '';
        $map['last_name'] = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
    }

    return $map;
}

$endpoint = '/' . $formId . '/leads';
$params = [
    'access_token' => $accessToken,
    'fields' => 'created_time,field_data',
    'limit' => $limit,
];
if ($after) {
    $params['after'] = $after;
}

try {
    $response = fb_request('GET', $endpoint, $params);
} catch (Throwable $e) {
    http_response_code(502);
    echo 'Facebook: ' . $e->getMessage();
    exit;
}

if ($response['status'] < 200 || $response['status'] >= 300) {
    http_response_code(502);
    echo 'Facebook: risposta non valida. ' . $response['body'];
    exit;
}

$data = $response['json'] ?? [];
$leads = $data['data'] ?? [];

$stmtCheck = $pdo->prepare("SELECT id FROM lead WHERE facebook_lead_id = :fid LIMIT 1");
$stmtInsert = $pdo->prepare("INSERT INTO lead (nome, cognome, email, telefono, citta, data_ricontatto, esito, note, source, facebook_lead_id) VALUES (:nome, :cognome, :email, :telefono, :citta, :data_ricontatto, :esito, :note, :source, :facebook_lead_id)");

foreach ($leads as $lead) {
    $fid = (string)($lead['id'] ?? '');
    if ($fid === '') {
        $skipped++;
        continue;
    }
    $stmtCheck->execute([':fid' => $fid]);
    if ($stmtCheck->fetch()) {
        $skipped++;
        continue;
    }

    $mapped = mapLeadField($lead['field_data'] ?? []);
    $nome = trim((string)($mapped['first_name'] ?? ''));
    $cognome = trim((string)($mapped['last_name'] ?? ''));
    if ($nome === '' && !empty($mapped['full_name'])) {
        $nome = (string)$mapped['full_name'];
    }

    $stmtInsert->execute([
        ':nome' => $nome !== '' ? $nome : 'Lead Facebook',
        ':cognome' => $cognome,
        ':email' => (string)($mapped['email'] ?? ''),
        ':telefono' => (string)($mapped['phone_number'] ?? ''),
        ':citta' => (string)($mapped['city'] ?? ''),
        ':data_ricontatto' => date('Y-m-d'),
        ':esito' => 'interessato',
        ':note' => 'Importato da Facebook Lead Ads',
        ':source' => 'facebook',
        ':facebook_lead_id' => $fid,
    ]);
    $imported++;
}

$nextAfter = $data['paging']['cursors']['after'] ?? null;
header('Location: index.php?success=1&import=' . $imported . '&skipped=' . $skipped . ($nextAfter ? '&after=' . urlencode($nextAfter) : ''));
exit;
