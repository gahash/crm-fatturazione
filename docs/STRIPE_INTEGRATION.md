# Integrazione Stripe - Guida Tecnica

## ✅ Stato Installazione

- **Stripe SDK**: Installato (v19.3.0)
- **Composer**: Configurato
- **Account Stripe**: Connesso ✓
- **Chiave API**: Valida

## 📋 File Installati

```
crm-fatturazione/
├── vendor/
│   ├── stripe/
│   │   └── stripe-php/          # SDK Stripe ufficiale
│   ├── composer/
│   │   └── ClassLoader.php
│   └── autoload.php
├── composer.json                # Dipendenze del progetto
├── composer.lock                # Lock file
└── config/
    └── secret.php               # Credenziali (non committare!)
```

## Configurazione Richiesta

Modifica `config/secret.php` con le tue credenziali reali:

```php
'stripe' => [
    'secret_key' => '<STRIPE_SECRET_KEY>',      // Chiave segreta (obbligatoria)
    'webhook_secret' => '<STRIPE_WEBHOOK_SECRET>',    // Firma webhook (opzionale)
    'public_key' => '<STRIPE_PUBLIC_KEY>',      // Chiave pubblica (per frontend)
    'public_url' => 'https://crm.example.com',
],
```

### Come ottenere le chiavi:
1. Accedi a [dashboard.stripe.com](https://dashboard.stripe.com)
2. Vai a **Sviluppatori** → **Chiavi API**
3. Copia la chiave **segreta** (Stripe > Webhook segrete per i webhook)

## Modulo Pagamenti

### Index (`modules/pagamenti/index.php`)
- Visualizza stato connessione Stripe
- Mostra ultimi pagamenti sincronizzati
- Pulsante "Sincronizza Stripe" per importare nuovi pagamenti
- Tabella con filtri (stato, metodo, data)

### Sincronizzazione (`modules/pagamenti/sincronizza_stripe.php`)
- Endpoint POST che:
  1. Legge l'API key da config/secret.php
  2. Chiama `Stripe\Charge::all()` per recuperare i pagamenti
  3. Inserisce/aggiorna la tabella `pagamenti`
  4. Restituisce JSON con conteggio

**Flusso:**
```
User clicks "Sincronizza Stripe"
   ↓
fetch() POST to sincronizza_stripe.php
   ↓
PHP chiama Stripe API v3
   ↓
Scarica charges da Stripe
   ↓
Inserts in DB table `pagamenti`
   ↓
Returns {ok: true, count: N}
   ↓
JavaScript reload page
```

## Schema Database

```sql
CREATE TABLE pagamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_id VARCHAR(255) UNIQUE NOT NULL,           -- ID Stripe (py_xxx)
    cliente_id INT UNSIGNED NULL,                     -- Link a clienti
    importo INT NOT NULL,                             -- In centesimi (Stripe format)
    valuta VARCHAR(3) DEFAULT 'EUR',                  -- EUR, USD, GBP, etc
    stato VARCHAR(50) DEFAULT 'pending',              -- succeeded, pending, failed
    metodo VARCHAR(100) NULL,                         -- card, bank_transfer, ideal
    descrizione TEXT NULL,                            -- Note opzionali
    created_at DATETIME NOT NULL DEFAULT NOW(),       -- Data sincronizzazione
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
);
```

## 🧪 Test

Verificare la connessione dalla pagina **Pagamenti** del gestionale
(stato "Stripe connesso") oppure lanciando una sincronizzazione manuale.
Per i test usare le chiavi di test (modalita' test della dashboard Stripe) e le
[carte di test Stripe](https://stripe.com/docs/testing).

## 🔄 Come Usare

### 1. **Sincronizzazione Manuale**
Vai a **Avatar** → **Pagamenti** → Clicca "Sincronizza Stripe"

### 2. **Sincronizzazione Automatica (Cron)**
Crea un task schedulato che chiama:
```bash
# Ogni ora:
php -f modules/pagamenti/sincronizza_stripe.php
```

### 3. **Webhook Stripe** (Avanzato)
Per aggiornamenti in tempo reale, configura un webhook:

```php
// modules/pagamenti/webhook_stripe.php
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = \Stripe\Webhook::constructEvent(
    $input,
    $sig_header,
    $endpoint_secret
);

if ($event->type == 'charge.succeeded') {
    // Update pagamenti table
}
```

## 📊 Visualizzazione Dati

### Pagamenti per Cliente
```php
SELECT p.*, c.nome, c.email
FROM pagamenti p
LEFT JOIN clienti c ON p.cliente_id = c.id
WHERE p.stato = 'succeeded'
ORDER BY p.created_at DESC;
```

### Totale Incassato
```php
SELECT 
    SUM(importo) as totale_eur,
    COUNT(*) as numero_pagamenti
FROM pagamenti
WHERE stato = 'succeeded'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH);
```

## Considerazioni di Sicurezza

1. **Mai committare secret.php**: Aggiungi a `.gitignore`
   ```
   config/secret.php
   vendor/
   composer.lock
   ```

2. **Validazione Webhook**: Sempre verificare `Stripe-Signature` header

3. **Rate Limiting**: Stripe API ha limiti di richieste (100/s)

4. **Timeout Lunghi**: Per sincronizzazioni bulk, aumenta PHP timeout:
   ```php
   set_time_limit(300); // 5 minuti
   ```

5. **Logging**: Registra tutte le transazioni Stripe:
   ```php
   error_log("Stripe charge: " . json_encode($charge));
   ```

## 📞 Link Utili

- Docs Stripe PHP SDK: https://github.com/stripe/stripe-php
- Stripe API Reference: https://stripe.com/docs/api
- Dashboard: https://dashboard.stripe.com
- Webhook Testing: https://dashboard.stripe.com/webhooks

## 🆘 Troubleshooting

| Errore | Causa | Soluzione |
|--------|-------|----------|
| `failed to open stream: vendor/autoload.php` | Composer non eseguito | Esegui `php composer.phar install` |
| `Invalid API Key` | Chiave errata o non valida | Controlla config/secret.php |
| `Rate limit exceeded` | Troppe richieste | Implementa caching e retry exponential |
| `No such table: pagamenti` | Schema non creato | Esegui schema.sql in phpMyAdmin |

---
**Ultimo aggiornamento:** 10/02/2026 - Stripe SDK v19.3.0

