<?php
/**
 * Caricamento variabili d'ambiente.
 *
 * Se nella root del progetto esiste un file .env (non versionato), le coppie
 * CHIAVE=valore vengono caricate con putenv() senza sovrascrivere le variabili
 * gia' definite dal server (es. SetEnv di Apache o pannello hosting).
 */

if (!function_exists('tdg_load_env')) {
    function tdg_load_env(?string $path = null): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $path = $path ?? dirname(__DIR__) . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

if (!function_exists('env')) {
    /**
     * Legge una variabile d'ambiente con valore di default.
     */
    function env(string $key, $default = null)
    {
        tdg_load_env();
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        switch (strtolower($value)) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
        }
        return $value;
    }
}

tdg_load_env();
