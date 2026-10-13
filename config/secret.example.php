<?php
/**
 * Credenziali delle integrazioni esterne.
 *
 * Copiare in config/secret.php (non versionato):
 *     cp config/secret.example.php config/secret.php
 *
 * I valori vengono letti dalle variabili d'ambiente (vedi .env.example).
 * Nota: alcune schermate di Impostazioni riscrivono config/secret.php con i
 * valori salvati dall'interfaccia; per questo il file NON va mai committato.
 */
require_once __DIR__ . '/env.php';

$appUrl = rtrim((string)env('APP_URL', 'http://localhost:8000'), '/');

return [
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'public_key' => env('STRIPE_PUBLIC_KEY', ''),
        // URL pubblico del gestionale
        'public_url' => $appUrl,
    ],
    'fattureincloud' => [
        // Credenziali applicazione Fatture in Cloud (OAuth)
        'app_id' => env('FIC_APP_ID', ''),
        'client_id' => env('FIC_CLIENT_ID', ''),
        'client_secret' => env('FIC_CLIENT_SECRET', ''),
        // Redirect URI autorizzato in Fatture in Cloud
        'oauth_redirect' => env('FIC_OAUTH_REDIRECT', $appUrl . '/modules/fatture/actions/fic_oauth_callback.php'),
        // Scope opzionale (vuoto = default)
        'oauth_scope' => env('FIC_OAUTH_SCOPE', ''),
        // Base API ufficiale
        'base_url' => env('FIC_BASE_URL', 'https://api-v2.fattureincloud.it'),
        'access_token' => env('FIC_ACCESS_TOKEN', ''),
        'company_id' => env('FIC_COMPANY_ID', ''),
        // Parametri necessari per creare fatture
        'document_type' => 'invoice',
        'currency_id' => 'EUR',
        'language_code' => 'it',
        'language_name' => 'Italiano',
        // ID IVA da recuperare con: GET /c/{company_id}/info/vat_types
        'vat_type_id' => (int)env('FIC_VAT_TYPE_ID', 0),
        // (opzionale) payment_method id da /c/{company_id}/info/payment_methods
        'payment_method_id' => env('FIC_PAYMENT_METHOD_ID', null),
    ],
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
        'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI', $appUrl . '/modules/lead/facebook_callback.php'),
        'api_version' => env('FACEBOOK_API_VERSION', 'v21.0'),
        'page_id' => env('FACEBOOK_PAGE_ID', ''),
        'lead_form_id' => env('FACEBOOK_LEAD_FORM_ID', ''),
    ],
];
