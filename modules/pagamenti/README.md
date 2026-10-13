# Pagina Pagamenti

## 📍 URL
`/modules/pagamenti/index.php`

## 🎯 Funzionalità

### Dashboard Pagamenti
- ✅ Visualizzazione stato connessione Stripe
- ✅ Badge: Connesso ✓ / Disconnesso ✗
- ✅ Dettagli account:
  - Chiave pubblica Stripe (parziale)
  - URL pubblico del sistema
- ✅ Tabella ultimi 20 pagamenti sincronizzati

### Sincronizzazione Stripe
- ✅ Pulsante "Sincronizza Stripe"
- ✅ Conferma dialog prima dell'azione
- ✅ Feedback visivo: "Sincronizzazione in corso..."
- ✅ Alert con conteggio pagamenti importati
- ✅ Reload automatico della pagina

### Visualizzazione Pagamenti
**Colonne tabella:**
- ID Stripe (formato: `py_xxx...`)
- Importo (formattato con virgola: `47,58 €`)
- Valuta (EUR, USD, GBP...)
- Stato (badge colorato):
  - 🟢 **succeeded** (verde)
  - 🟡 **pending** (giallo)
  - 🔴 **failed** (rosso)
- Metodo (card, bank_transfer, ideal, sepa_debit)
- Data (formato italiano: `10/02/2026 14:30`)

### Stato Vuoto
Se nessun pagamento sincronizzato:
- Messaggio: "Nessun pagamento sincronizzato."
- Suggerimento: "Clicca 'Sincronizza Stripe'..."

## 🎨 Design

### Layout
- Container max-width: 1200px
- Padding responsive: 24px
- Font-family: system-ui
- Colori: Tailwind CSS palette

### Componenti
1. **Header**
   - H1 + pulsante allineati (flex)
   - Pulsante blu (#3b82f6) con hover effect

2. **Card Stato**
   - Background bianco
   - Border grigio (#e5e7eb)
   - Border-radius: 8px
   - Grid layout responsivo (3 colonne)

3. **Tabella**
   - Full width
   - Background bianco
   - Header grigio (#f3f4f6)
   - Hover row: background #f9fafb
   - Border-radius: 8px

### Badge
- **Status Connected**: Verde (#d1fae5 / #065f46)
- **Status Disconnected**: Rosso (#fee2e2 / #991b1b)
- **Payment Succeeded**: Verde
- **Payment Pending**: Giallo (#fef3c7 / #92400e)
- **Payment Failed**: Rosso

## 🔧 Tecnologie

### Backend
- **PHP**: 8.0+
- **Database**: MySQL/MariaDB
- **Auth**: Session-based (`require_once auth.php`)
- **Stripe SDK**: v19.3.0 (`vendor/autoload.php`)

### Frontend
- **HTML5**: Semantic markup
- **CSS3**: Inline styles (future: extract to CSS file)
- **JavaScript**: Vanilla ES6+
  - Fetch API per AJAX
  - async/await pattern
  - Error handling con try/catch

## 📊 Database

### Tabella `pagamenti`
```sql
CREATE TABLE pagamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_id VARCHAR(255) UNIQUE NOT NULL,
    cliente_id INT UNSIGNED NULL,
    importo INT NOT NULL COMMENT 'Importo in centesimi',
    valuta VARCHAR(3) DEFAULT 'EUR',
    stato VARCHAR(50) DEFAULT 'pending',
    metodo VARCHAR(100) NULL,
    descrizione TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
);
```

### Query Principale
```php
SELECT * FROM pagamenti 
ORDER BY created_at DESC 
LIMIT 20;
```

## 🔌 API Endpoints

### `sincronizza_stripe.php`
**Method**: POST  
**Response**: JSON

**Success (200)**:
```json
{
  "ok": true,
  "count": 5,
  "message": "Sincronizzazione completata con successo",
  "imported": ["py_xxx", "py_yyy", ...]
}
```

**Error (400)**:
```json
{
  "ok": false,
  "error": "Stripe non configurato"
}
```

**Error (500)**:
```json
{
  "ok": false,
  "error": "Errore Stripe API: Invalid API key"
}
```

## 🚀 Workflow

```
User accede a /modules/pagamenti/index.php
   ↓
Sistema verifica autenticazione
   ↓
Carica config Stripe da secret.php
   ↓
Query DB: SELECT * FROM pagamenti LIMIT 20
   ↓
Renderizza tabella (o messaggio vuoto)
   ↓
User clicca "Sincronizza Stripe"
   ↓
Conferma dialog
   ↓
JavaScript: fetch('sincronizza_stripe.php', {method: 'POST'})
   ↓
PHP chiama Stripe\Charge::all(['limit' => 100])
   ↓
Loop su charges e INSERT INTO pagamenti
   ↓
Response JSON: {ok: true, count: N}
   ↓
JavaScript alert + location.reload()
   ↓
Tabella aggiornata con nuovi pagamenti
```

## 🧪 Testing

### Test Manuale
1. Avvia server: `php -S localhost:8000`
2. Accedi: `http://localhost:8000/modules/pagamenti/index.php`
3. Verifica badge connessione (verde = OK)
4. Clicca "Sincronizza Stripe"
5. Attendi alert di conferma
6. Verifica pagamenti in tabella

### Test Automatico
```bash
php test_stripe.php
# Output atteso:
# ✅ Stripe SDK installato e configurato correttamente!
# Account Stripe: acct_xxx...
# Ultimi 5 pagamenti su Stripe...
```

### Test Endpoint
```bash
curl -X POST http://localhost:8000/modules/pagamenti/sincronizza_stripe.php
# Output atteso (JSON):
# {"ok":true,"count":5,"message":"...","imported":[...]}
```

## 🔒 Sicurezza

- ✅ Autenticazione richiesta (`require_once auth.php`)
- ✅ Prepared statements per query SQL
- ✅ `htmlspecialchars()` su tutti gli output
- ✅ Chiave API non esposta in frontend
- ✅ HTTPS required in production
- ⚠️ **TODO**: CSRF token per sincronizzazione POST
- ⚠️ **TODO**: Rate limiting (max 1 sync ogni 60 secondi)

## 📱 Responsive Design

### Desktop (>1024px)
- Container 1200px max-width
- 3 colonne grid per stato
- Tabella full width con tutte le colonne

### Tablet (768px-1024px)
- Container 90% width
- 2 colonne grid
- Tabella scrollabile orizzontalmente

### Mobile (<768px)
- Container padding 16px
- 1 colonna grid
- Tabella con scroll orizzontale
- Font-size ridotto per codici Stripe

## 🐛 Troubleshooting

### "Stripe non configurato"
**Causa**: Chiave API mancante o invalida  
**Soluzione**: Verifica `config/secret.php` contiene `stripe.secret_key` valida

### "No such table: pagamenti"
**Causa**: Schema SQL non eseguito  
**Soluzione**: Esegui `database/schema.sql` in phpMyAdmin

### "Invalid API Key"
**Causa**: Chiave Stripe scaduta o revocata  
**Soluzione**: Rigenera chiave da Stripe Dashboard

### "Request failed with error code 429"
**Causa**: Rate limit Stripe superato  
**Soluzione**: Attendi 60 secondi e riprova

### "Tabella vuota dopo sincronizzazione"
**Causa**: Nessun pagamento su account Stripe  
**Soluzione**: Verifica su dashboard.stripe.com ci siano charges

## 📈 Future Enhancements

### v2.0
- [ ] Filtri avanzati (data range, stato, metodo)
- [ ] Paginazione (oltre 20 record)
- [ ] Export CSV pagamenti
- [ ] Link a cliente (se collegato)
- [ ] Dettaglio pagamento (modal con metadata)

### v2.1
- [ ] Webhook Stripe (sync real-time)
- [ ] Grafici incassi (Chart.js)
- [ ] Statistiche mensili/annuali
- [ ] Riconciliazione con fatture

### v3.0
- [ ] Rimborsi (refund) Stripe
- [ ] Split payments
- [ ] Multi-currency support
- [ ] PDF report mensili

## 🔗 Link Utili

- **Stripe Dashboard**: https://dashboard.stripe.com
- **Stripe API Docs**: https://stripe.com/docs/api/charges
- **Stripe PHP SDK**: https://github.com/stripe/stripe-php
- **Test Cards**: https://stripe.com/docs/testing

---
**Creato**: 10/02/2026  
**Ultima modifica**: 10/02/2026  
**Status**: ✅ Produzione ready
