<?php

if (!function_exists('fatture_log')) {

function fatture_log(PDO $pdo, ?int $fatturaId, string $azione, string $esito, string $messaggio, array $meta = []): void
{
    $allowed = ['ok', 'error', 'info'];
    if (!in_array($esito, $allowed, true)) {
        $esito = 'info';
    }

    $user = function_exists('current_user') ? current_user() : null;
    $utenteNome = is_array($user) ? (string)($user['nome'] ?? '') : '';
    $utenteEmail = is_array($user) ? (string)($user['email'] ?? '') : '';
    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $metaJson = $meta ? json_encode($meta, $jsonFlags) : null;
    if ($metaJson === false) {
        $metaJson = json_encode(['encode_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    }
    if ($metaJson !== null && strlen($metaJson) > 50000) {
        $metaJson = json_encode([
            'truncated' => true,
            'original_bytes' => strlen($metaJson),
            'preview' => substr($metaJson, 0, 48000),
        ], $jsonFlags);
    }

    try {
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            unset($GLOBALS['_tdg_pdo']);
            if (function_exists('get_db')) {
                $pdo = get_db(true);
            } else {
                $pdo = require __DIR__ . '/../../../config/db.php';
            }
        }

        $sql = "INSERT INTO fatture_log (fattura_id, azione, esito, messaggio, utente_nome, utente_email, meta_json) VALUES (:fattura_id, :azione, :esito, :messaggio, :utente_nome, :utente_email, :meta_json)";
        $stmt = $pdo->prepare($sql);
        if ($fatturaId !== null && $fatturaId > 0) {
            $stmt->bindValue(':fattura_id', $fatturaId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':fattura_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':azione', substr($azione, 0, 60));
        $stmt->bindValue(':esito', $esito);
        $stmt->bindValue(':messaggio', substr($messaggio, 0, 255));
        $stmt->bindValue(':utente_nome', $utenteNome !== '' ? substr($utenteNome, 0, 100) : null, $utenteNome !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':utente_email', $utenteEmail !== '' ? substr($utenteEmail, 0, 190) : null, $utenteEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':meta_json', $metaJson, $metaJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    } catch (Throwable $e) {
    }
}
}

if (!function_exists('fatture_recent_logs')) {

function fatture_count_logs(PDO $pdo, ?int $fatturaId = null): int
{
    try {
        if ($fatturaId !== null && $fatturaId > 0) {
            $sql = "SELECT COUNT(*) FROM fatture_log WHERE fattura_id = :fattura_id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':fattura_id', $fatturaId, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        $sql = "SELECT COUNT(*) FROM fatture_log";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('fatture_recent_logs')) {

function fatture_recent_logs(PDO $pdo, int $limit = 20, ?int $fatturaId = null, int $offset = 0): array
{
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);

    try {
        if ($fatturaId !== null && $fatturaId > 0) {
            $sql = "SELECT * FROM fatture_log WHERE fattura_id = :fattura_id ORDER BY creato_il DESC, id DESC LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':fattura_id', $fatturaId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        }

        $sql = "SELECT * FROM fatture_log ORDER BY creato_il DESC, id DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
}
