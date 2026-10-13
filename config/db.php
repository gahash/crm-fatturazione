<?php
if (!function_exists('tdg_cached_pdo_if_alive')) {
    function tdg_cached_pdo_if_alive(): ?PDO
    {
        $pdo = $GLOBALS['_tdg_pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (Throwable $e) {
            unset($GLOBALS['_tdg_pdo']);
            return null;
        }
    }
}

if (!function_exists('get_db')) {
    function get_db(bool $forceReconnect = false): PDO
    {
        if ($forceReconnect) {
            unset($GLOBALS['_tdg_pdo']);
        }

        return require __DIR__ . '/db.php';
    }
}

$cachedPdo = tdg_cached_pdo_if_alive();
if ($cachedPdo instanceof PDO) {
    return $cachedPdo;
}

// config.php legge le variabili d'ambiente e applica eventuali override di config.local.php
$configFile = __DIR__ . '/config.php';

$configCache = $GLOBALS['_tdg_config_cache'] ?? [];
if (!isset($configCache[$configFile])) {
    $configCache[$configFile] = require $configFile;
    $GLOBALS['_tdg_config_cache'] = $configCache;
}
$config = $configCache[$configFile];

date_default_timezone_set($config['app']['timezone']);

$db = $config['db'];
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $GLOBALS['_tdg_pdo'] = $pdo;
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Errore connessione DB.';
    exit;
}

return $pdo;
