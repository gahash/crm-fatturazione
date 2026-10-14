# CRM Fatturazione

[![Controllo sintassi PHP](https://github.com/gahash/crm-fatturazione/actions/workflows/php-lint.yml/badge.svg)](https://github.com/gahash/crm-fatturazione/actions/workflows/php-lint.yml)

Gestionale web in PHP per piccole e medie imprese che unisce **CRM clienti**, **fatturazione elettronica**, **incassi con Stripe** e **sincronizzazione con Fatture in Cloud**. Il sistema automatizza il percorso completo *pagamento → ordine → fattura → invio SDI → email al cliente*, con un pannello di monitoraggio per gli amministratori e job cron per le attività ricorrenti.

> Progetto realizzato per un cliente reale e pubblicato come portfolio. Tutti i dati, le credenziali e i riferimenti al cliente sono stati rimossi o sostituiti con valori di esempio.

---

## Funzionalità

### CRM
- Anagrafica clienti con dati fiscali (P.IVA, codice SDI, PEC, indirizzo) e validazione per la fatturazione elettronica
- Note, interazioni e storico per cliente; esportazione CSV
- Gestione **lead** con importazione CSV e integrazione Facebook Lead Ads, conversione lead → cliente
- Verifica massiva e unione di anagrafiche duplicate

### Fatturazione
- Fatture, preventivi e ordini con righe, IVA, scadenze e stati (bozza, emessa, pagata, scaduta)
- Numerazione, filtri, paginazione ed esportazione CSV
- **Scadenziario** dei pagamenti
- Invio fatture via email (SMTP) con link pubblico firmato al PDF

### Pagamenti Stripe e ordini automatici
- Sincronizzazione dei pagamenti (charges) da Stripe con mappatura automatica sul cliente
- Creazione automatica di ordini e fatture dai pagamenti ricevuti
- Webhook Stripe con verifica della firma
- Checkout Stripe per il pagamento delle fatture
- Supporto Stripe Connect (account collegati, saldi e bonifici) e dashboard incassi con ripartizione tra piattaforma e partner

### Integrazione Fatture in Cloud
- Connessione OAuth2 con refresh automatico del token
- Sincronizzazione clienti e fatture, creazione documenti e **invio al Sistema di Interscambio (SDI)**
- Gestione dei limiti di utilizzo delle API, retry degli errori transitori e recupero dei duplicati
- Audit e log di ogni operazione per singola fattura

### Amministrazione
- Dashboard con KPI di fatturato, incassi e stato delle fatture
- **Admin monitor**: sessioni utente attive, attività recenti e diagnostica
- Gestione utenti con ruoli (`admin`, `ragioneria`)
- Impostazioni da interfaccia: SMTP, modalità di invio fatture, pianificazione delle sincronizzazioni

### Cron
- Job per sincronizzazione Stripe, clienti e fatture FIC, retry degli invii e invio SDI delle fatture pagate
- Wrapper `cron/run_all.php` che esegue tutti i job in sequenza
- Endpoint protetti da chiave segreta

---

## Stack tecnologico

| Ambito | Tecnologia |
|---|---|
| Backend | PHP 7.4+ (procedurale, PDO) |
| Database | MySQL / MariaDB |
| Frontend | HTML, CSS, JavaScript vanilla |
| Pagamenti | Stripe API (`stripe/stripe-php`) |
| Fatturazione elettronica | API Fatture in Cloud v2 (OAuth2) |
| Email | SMTP nativo via socket |
| Dipendenze | Composer |

## Requisiti

- PHP 7.4 o superiore con estensioni `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`
- MySQL 5.7+ o MariaDB 10.6+
- Composer
- Web server Apache (con `mod_rewrite`/`.htaccess`) o Nginx
- Account Stripe e app OAuth Fatture in Cloud (per le relative integrazioni)

---

## Installazione

```bash
# 1. Clona il repository
git clone <url-repository> crm-fatturazione
cd crm-fatturazione

# 2. Installa le dipendenze
composer install

# 3. Crea il database e importa lo schema
mysql -u root -p -e "CREATE DATABASE crm_fatturazione CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p crm_fatturazione < sql/schema.sql

# (opzionale) dati di esempio fittizi
mysql -u root -p crm_fatturazione < sql/seed.sql

# 4. Configura l'ambiente
cp .env.example .env
cp config/secret.example.php config/secret.php
cp config/mail.example.php config/mail.php
# modifica .env con i tuoi valori

# 5. Avvia in locale
php -S localhost:8000
```

Con i dati di esempio è possibile accedere con l'utente `admin@example.com` (password indicata in `sql/seed.sql`, da cambiare subito).

### Configurazione

La configurazione è letta da **variabili d'ambiente**, caricate dal file `.env` nella root (vedi [`.env.example`](.env.example)) o impostate dal server/pannello di hosting.

| File | Versionato | Scopo |
|---|---|---|
| `config/config.php` | sì | Configurazione principale (DB, URL, Stripe) letta da env |
| `config/config.local.php` | no | Override locali opzionali (modello: `config.local.example.php`) |
| `config/secret.php` | no | Credenziali Stripe, Fatture in Cloud, Facebook (modello: `secret.example.php`) |
| `config/mail.php` | no | Configurazione SMTP/IMAP (modello: `mail.example.php`) |
| `.env` | no | Variabili d'ambiente |

`config/secret.php` e `config/mail.php` possono essere riscritti dalle pagine di Impostazioni: per questo sono esclusi dal versionamento.

### Stripe
1. Imposta `STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY` e `STRIPE_WEBHOOK_SECRET` (in sviluppo usare le chiavi di test).
2. Configura un webhook verso `https://<dominio>/modules/fatture/actions/stripe_webhook.php`.

### Fatture in Cloud
1. Crea un'app OAuth sul portale sviluppatori di Fatture in Cloud e imposta `FIC_CLIENT_ID`, `FIC_CLIENT_SECRET`, `FIC_OAUTH_REDIRECT`.
2. Dal gestionale avvia la connessione OAuth con il pulsante **Connetti Fatture in Cloud** (pagine Fatture o Impostazioni); il token viene salvato in `tmp/`.

---

## Cron

Imposta `CRON_KEY` nel file `.env` con una stringa casuale lunga, poi configura il cron di sistema:

```cron
# Tutti i job in sequenza, ogni 5 minuti
*/5 * * * * /usr/bin/php /percorso/crm-fatturazione/cron/run_all.php

# In alternativa, singoli job via HTTP
*/5  * * * * curl -s "https://crm.example.com/cron/sync_stripe.php?key=CRON_KEY" > /dev/null
*/15 * * * * curl -s "https://crm.example.com/cron/sync_customers.php?key=CRON_KEY" > /dev/null
*/30 * * * * curl -s "https://crm.example.com/cron/retry_pending_invoices.php?key=CRON_KEY" > /dev/null
```

| Job | Descrizione |
|---|---|
| `sync_stripe.php` | Importa i pagamenti Stripe e crea ordini/fatture |
| `sync_customers.php` | Allinea i clienti Stripe con l'anagrafica CRM |
| `sync_fic_invoices.php` | Sincronizza le fatture con Fatture in Cloud |
| `retry_pending_invoices.php` | Ritenta gli invii in sospeso |
| `retry_transient_sync_errors.php` | Ritenta gli errori temporanei di sincronizzazione |
| `retry_usage_limited_invoices.php` | Riprende gli invii bloccati dai limiti API |
| `cron_send_einvoice_paid.php` | Invia allo SDI le fatture pagate |

Gli altri script in `cron/` (`inspect_*`, `reconcile_*`, `merge_clienti.php`, ...) sono strumenti di manutenzione da lanciare manualmente, sempre protetti dalla stessa chiave.

---

## Struttura del progetto

```
crm-fatturazione/
├── admin-monitor/        # Pannello di monitoraggio (sessioni, attività, diagnostica)
├── assets/brand/         # Logo (segnaposto)
├── config/               # Configurazione, connessione DB, helper
├── cron/                 # Job pianificati e strumenti di manutenzione
├── database/             # Migrazioni SQL incrementali
├── docs/                 # Documentazione tecnica (Stripe, ordini automatici)
├── layouts/              # Header e footer comuni
├── modali/               # Componenti modali e menu (JS/CSS)
├── modules/
│   ├── clienti/          # CRM clienti
│   ├── connect/          # Stripe Connect
│   ├── dashboard/        # Dashboard e transazioni
│   ├── fatture/          # Fatture e integrazione Fatture in Cloud
│   ├── impostazioni/     # Impostazioni applicative
│   ├── lead/             # Lead e Facebook Lead Ads
│   ├── ordini/           # Ordini (anche automatici da Stripe)
│   ├── pagamenti/        # Pagamenti Stripe
│   ├── preventivi/       # Preventivi
│   ├── scadenziario/     # Scadenziario
│   └── utenti/           # Gestione utenti
├── sql/
│   ├── schema.sql        # Struttura completa del database
│   └── seed.sql          # Dati di esempio fittizi
├── tmp/                  # File runtime (token, cache) - non versionato
├── .env.example
├── composer.json
└── index.php
```

---

## Note di sicurezza

- Nessuna credenziale è presente nel repository: tutti i segreti sono letti da variabili d'ambiente o da file locali esclusi da Git (`.env`, `config/secret.php`, `config/mail.php`, `config/config.local.php`).
- Le cartelle `config/` e `tmp/` e i file nascosti (es. `.env`) sono bloccati via `.htaccess`; su Nginx replicare le stesse regole. Dove possibile, posizionare `.env` fuori dalla document root e impostare le variabili dal server.
- Gli endpoint cron rifiutano qualsiasi richiesta se `CRON_KEY` non è impostata.
- Il webhook Stripe verifica la firma con `STRIPE_WEBHOOK_SECRET`.
- I link pubblici ai PDF delle fatture sono firmati e hanno una scadenza.
- In produzione usare HTTPS, disattivare `display_errors` e ruotare periodicamente le chiavi.

---

## Licenza

Tutti i diritti riservati. Il codice è pubblicato a solo scopo dimostrativo e non può essere copiato, modificato o distribuito senza autorizzazione scritta dell'autore.

## Autore

Gaspare Pettinati - [gasparepettinati.it](https://gasparepettinati.it)
