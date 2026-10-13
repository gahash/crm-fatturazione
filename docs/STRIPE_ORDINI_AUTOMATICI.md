# Integrazione Stripe - Sistema Ordini Automatici

## Flusso Completo

### 1. Cliente effettua pagamento Stripe
- Cliente completa checkout Stripe
- Stripe registra il charge (pagamento)
- Charge contiene: importo, cliente, stato, metodo

### 2. Sincronizzazione Pagamenti (Automatica o Manuale)

**Endpoint: `modules/pagamenti/sincronizza_stripe.php`**

**Modalità Automatica:**
- Configurata in Impostazioni > Sincronizzazione Automatica
- Intervallo: 5-1440 minuti
- Chiamata via cron con query param `?scheduled=1&cron=[secret]`

**Modalità Manuale:**
- Pulsante "Sincronizza ora" nella pagina Pagamenti
- Admin può eseguire on-demand

**Cosa fa:**
1. Recupera charges da Stripe API (ultimi 90 giorni)
2. Per ogni charge:
   - Crea/aggiorna cliente in tabella `clienti` (se ha email)
   - Inserisce pagamento in tabella `pagamenti`
   - **NUOVO**: Se charge è `succeeded` → crea automaticamente ordine in `fatture`

### 3. Creazione Ordine Automatica

**Quando viene creato:**
- Pagamento Stripe con stato `succeeded`
- Cliente identificato (ha email)
- Non esiste già un ordine per quel payment_intent_id

**Dati ordine:**
- **Numero**: `ORD-YYYYMMDD-XXX` (progressivo giornaliero)
- **Tipo**: `document_type = 'order'`
- **Stato**: `pagata` (già confermato da Stripe)
- **Totale**: Importo charge (convertito da centesimi a euro)
- **Cliente**: Collegato tramite `cliente_id`
- **Payment ID**: `stripe_payment_intent_id = charge.id`
- **Metodo**: `Stripe - [card/sepa_debit/...]`
- **Data**: Timestamp del charge Stripe
- **Righe**: Una riga generica con descrizione dal charge

### 4. Sincronizzazione Recupero (Retroattiva)

**Endpoint: `modules/ordini/sincronizza_ordini_stripe.php`**

**Quando usare:**
- Pagamenti storici già presenti in tabella `pagamenti`
- Ordini non creati automaticamente (sistema aggiunto dopo)
- Dopo aver corretto dati clienti

**Cosa fa:**
1. Query su `pagamenti` con `stato = 'succeeded'`
2. Filtra pagamenti senza ordine associato
3. Crea ordini mancanti con stessa logica della sync automatica

**UI:**
- Pulsante "🔄 Sincronizza Stripe" in `modules/ordini/index.php`
- Mostra alert con risultati (creati/saltati)

## Tabelle Database

### `pagamenti`
```sql
CREATE TABLE pagamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_id VARCHAR(255) UNIQUE NOT NULL,  -- Charge ID (ch_xxx)
    cliente_id INT UNSIGNED NULL,
    importo INT NOT NULL,                     -- In centesimi
    valuta VARCHAR(3) DEFAULT 'EUR',
    stato VARCHAR(50) DEFAULT 'pending',      -- succeeded, failed, pending
    metodo VARCHAR(100) NULL,                 -- card, sepa_debit, etc.
    descrizione TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### `fatture` (ordini hanno `document_type='order'`)
```sql
-- Campi rilevanti per Stripe:
stripe_payment_intent_id VARCHAR(255) NULL,  -- Link a pagamenti.stripe_id
stripe_session_id VARCHAR(255) NULL,         -- Checkout session
stripe_checkout_url TEXT NULL,
metodo_pagamento VARCHAR(50) NULL,           -- "Stripe - card"
data_pagamento DATETIME NULL,
stato ENUM('bozza','emessa','pagata','scaduta')
```

### `fatture_righe`
```sql
CREATE TABLE fatture_righe (
    fattura_id INT UNSIGNED NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    quantita DECIMAL(10,2) DEFAULT 1.00,
    prezzo_unitario DECIMAL(10,2),
    iva DECIMAL(5,2) DEFAULT 0.00,
    totale_riga DECIMAL(10,2)
);
```

## Identificazione Ordini Stripe

**Query per contare ordini Stripe:**
```sql
SELECT COUNT(*) 
FROM fatture 
WHERE document_type = 'order' 
AND stripe_payment_intent_id IS NOT NULL 
AND stripe_payment_intent_id != '';
```

**Query per totale incassato Stripe:**
```sql
SELECT COALESCE(SUM(totale), 0) 
FROM fatture 
WHERE document_type = 'order' 
AND stripe_payment_intent_id IS NOT NULL 
AND stripe_payment_intent_id != '';
```

## Visualizzazione UI

### Pagina Ordini (`modules/ordini/index.php`)
**KPI Cards:**
- Totali ordini
- Valore totale
- Bozze
- Emesse
- **Pagati Stripe** (badge blu)
- **Valore Stripe** (verde)

**Tabella:**
- Colonna "Pagamento" con badge viola "Stripe"
- Oppure metodo pagamento alternativo
- Oppure "-" se non pagato

### Dettaglio Ordine (`modules/ordini/dettaglio.php`)
Mostra informazioni Stripe:
- Metodo pagamento
- Data pagamento
- Stripe Payment ID (codice in grigio)
- Badge "🟣 Ordine pagato tramite Stripe"

## Configurazione

### 1. Chiavi Stripe
File: `config/secret.php`
```php
return [
    'stripe' => [
        'public_key' => '<STRIPE_PUBLIC_KEY>',
        'secret_key' => '<STRIPE_SECRET_KEY>',
    ]
];
```

### 2. Scheduler Automatico
- Vai in Impostazioni > Sincronizzazione Automatica
- Abilita sincronizzazione pagamenti Stripe
- Imposta intervallo (consigliato: 60 minuti)
- Configura cron job per chiamare endpoint

### 3. Cron Job
```bash
# Ogni ora
0 * * * * curl -s "https://crm.example.com/modules/pagamenti/sincronizza_stripe.php?scheduled=1&cron=YOUR_SECRET_KEY" > /dev/null 2>&1
```

## Limitazioni e Note

### Limitazioni Attuali
1. **Riga ordine generica**: Ordini Stripe contengono una singola riga con descrizione del charge
2. **IVA 0%**: Impostata a zero, da configurare manualmente
3. **Prodotti non dettagliati**: Per e-commerce complesso, serve integrazione metadata

### Sicurezza
- Endpoint sincronizzazione richiede autenticazione admin
- Cron job protetto da secret key
- INSERT IGNORE previene duplicati

### Performance
- Sync recupera ultimi 90 giorni (limite Stripe API gratuita)
- AutoPagingIterator gestisce grandi volumi
- Transazioni database per consistenza

### Webhook vs Polling
**Attuale**: Polling periodico (ogni X minuti)
**Futuro**: Implementare webhook Stripe per real-time

## Troubleshooting

### Ordine non creato automaticamente
1. Verifica chiave Stripe in `config/secret.php`
2. Controlla log errori PHP (`error_log`)
3. Verifica cliente abbia email valida
4. Controlla campo `stato` in tabella `pagamenti` (deve essere 'succeeded')
5. Usa pulsante "Sincronizza Stripe" manuale

### Duplicati o errori
- Database usa `INSERT IGNORE` per prevenire duplicati
- `stripe_id` è UNIQUE in tabella `pagamenti`
- Numero ordine progressivo gestisce collisioni

### Cliente non trovato
- Sincronizzazione crea cliente automaticamente se ha email
- Se email mancante, ordine viene saltato
- Soluzione: Aggiungi email in Stripe Dashboard, poi ri-sincronizza

## Best Practices

1. **Testa in modalità test**: Usa chiavi Stripe test prima di produzione
2. **Monitora log**: Controlla `error_log` per errori silenziosi
3. **Backup prima sync**: Prima sincronizzazione massiva, fai backup database
4. **Pulizia dati**: Configura nomi/email clienti Stripe per ordini leggibili
5. **IVA nei metadata**: Passa IVA nei metadata Stripe per calcolo automatico
