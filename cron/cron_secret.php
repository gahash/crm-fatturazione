<?php
/**
 * Chiave segreta per proteggere gli endpoint cron.
 *
 * Impostare la variabile d'ambiente CRON_KEY (vedi .env.example) con una
 * stringa casuale lunga. Gli endpoint vanno chiamati con ?key=CRON_KEY.
 * Se la variabile non e' impostata, tutte le chiamate vengono rifiutate.
 */
require_once __DIR__ . '/../config/env.php';

define('CRON_KEY', (string)env('CRON_KEY', ''));
