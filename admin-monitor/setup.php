<?php
/**
 * One-time script: create tables + fatturazione user.
 * Run via browser or CLI, then delete.
 */
require __DIR__ . '/../auth.php';
$pdo = require __DIR__ . '/../config/db.php';

$user = current_user();
if (!$user || strtolower($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Solo admin.');
}

$out = [];

// 1) Create user_activity table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        user_nome VARCHAR(100) NOT NULL,
        user_email VARCHAR(190) NOT NULL,
        user_ruolo VARCHAR(30) NOT NULL DEFAULT '',
        azione VARCHAR(30) NOT NULL DEFAULT 'pageview',
        pagina VARCHAR(500) NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        session_id VARCHAR(128) NOT NULL DEFAULT '',
        creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_creato (user_id, creato_il),
        KEY idx_creato (creato_il),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out[] = 'OK: user_activity created';
} catch (Throwable $e) {
    $out[] = 'ERR user_activity: ' . $e->getMessage();
}

// 2) Create user_sessions table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        user_nome VARCHAR(100) NOT NULL,
        user_email VARCHAR(190) NOT NULL,
        session_id VARCHAR(128) NOT NULL UNIQUE,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_page VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_active (is_active, last_seen_at),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out[] = 'OK: user_sessions created';
} catch (Throwable $e) {
    $out[] = 'ERR user_sessions: ' . $e->getMessage();
}

// 3) Create "fatturazione" user if not exists
//    Email e password vengono lette da BILLING_USER_EMAIL / BILLING_USER_PASSWORD;
//    se la password non e' impostata ne viene generata una casuale.
try {
    $billingEmail = billing_user_email();
    if ($billingEmail === '') {
        $out[] = 'SKIP: BILLING_USER_EMAIL non impostata, utente fatturazione non creato';
    } else {
        $check = $pdo->prepare('SELECT id FROM utenti WHERE email = :e LIMIT 1');
        $check->execute([':e' => $billingEmail]);
        if ($check->fetch()) {
            $out[] = 'SKIP: utente fatturazione già esiste';
        } else {
            $plainPassword = (string)(getenv('BILLING_USER_PASSWORD') ?: '');
            $generated = false;
            if ($plainPassword === '') {
                $plainPassword = bin2hex(random_bytes(8));
                $generated = true;
            }
            $hash = hash('sha256', $plainPassword);
            $stmt = $pdo->prepare("INSERT INTO utenti (nome, email, ruolo, password_hash, attivo) VALUES (:nome, :email, :ruolo, :hash, 1)");
            $stmt->execute([
                ':nome' => 'Fatturazione',
                ':email' => $billingEmail,
                ':ruolo' => 'ragioneria',
                ':hash' => $hash,
            ]);
            $out[] = 'OK: utente "fatturazione" creato (email: ' . $billingEmail . ', ruolo: ragioneria)'
                . ($generated ? ' - password generata: ' . $plainPassword . ' (cambiarla al primo accesso)' : '');
        }
    }
} catch (Throwable $e) {
    $out[] = 'ERR utente: ' . $e->getMessage();
}

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $out) . "\n\n⚠ Elimina questo file dopo l'uso.";
