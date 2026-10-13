<?php
/**
 * Override locali opzionali (copiare in config/config.local.php, non versionato).
 * I valori qui definiti sovrascrivono quelli di config/config.php.
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'crm_fatturazione',
        'user' => 'root',
        'pass' => '',
    ],
    'app' => [
        'base_url' => 'http://localhost:8000',
    ],
];
