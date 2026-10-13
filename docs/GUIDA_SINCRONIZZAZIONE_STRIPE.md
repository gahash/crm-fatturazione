# 🚀 Guida Sincronizzazione Stripe → Fatture → FIC

## ✅ Prerequisiti Completati

- ✅ Database migrato con colonne Stripe (`metodo_pagamento`, `stripe_session_id`, ecc.)
- ✅ Connessione Fatture in Cloud configurata
- ✅ Sistema di invio automatico email configurato

---

## 📋 Passo 1: Verifica Configurazione Stripe

### Vai a: **Pagamenti** dal menu principale

Verifica che mostri:
- ✅ **Stripe API**: "✓ Connesso" (verde)
- ✅ **Chiave Pubblica**: inizio chiave visibile (es. `pk_...`)
- ✅ **URL Pubblico**: configurato

### Se NON connesso:
Vai in **Impostazioni** → Stripe e configura:
- Secret Key (da Stripe Dashboard)
- Public Key (da Stripe Dashboard)
- URL pubblico del gestionale

---

## 📋 Passo 2: Prima Sincronizzazione

### Nella pagina Pagamenti:

1. **Clicca su "Sincronizza Stripe"** (pulsante blu in alto a destra)
2. **Conferma l'operazione** nel popup
3. **Attendere** il completamento (può richiedere alcuni minuti)

### Cosa succede:
- ✅ Importa ultimi 100 pagamenti da Stripe
- ✅ Crea clienti automaticamente (se non esistono)
- ✅ Crea fatture (NON ordini) con formato: `STR-INV-YYYYMMDD-XXX`
- ✅ Converte vecchi ordini in fatture (se esistono)
- ✅ Tenta invio email automatico se requisiti soddisfatti

---

## 📋 Passo 3: Verifica Risultati

### Dopo la sincronizzazione:

1. **Pagina Pagamenti**: verifica che mostri i pagamenti importati
2. **Pagina Fatture**: verifica le nuove fatture create (filtro metodo pagamento = "Stripe")
3. **Test Allineamento FIC**: vai in **Impostazioni** → "Test Allineamento Fatture in Cloud"

---

## 📋 Passo 4: Sincronizzazione Fatture in Cloud

### Per ogni fattura Stripe creata, il sistema deve:

1. **Creare il documento su FIC** (se non esiste)
2. **Pubblicare il documento** (cambio stato draft → pubblicato)
3. **Scaricare il PDF** (per allegarlo alla email)

### Opzioni:

#### A) Manuale (per testare):
- Vai in **Fatture**
- Clicca su una fattura Stripe
- Clicca "Invia per email"
- Il sistema crea automaticamente il documento FIC e invia

#### B) Automatico (dopo setup):
- Le fatture vengono create su FIC automaticamente durante la sincronizzazione Stripe
- Email inviata solo se cliente ha:
  - ✅ Email valida
  - ✅ Partita IVA
  - ✅ Anagrafica (nome o azienda)

---

## 📋 Passo 5: Invio Automatico Email

### Configurazione attuale (da Impostazioni):
- **Modalità invio**: Automatico
- **Stato automazione**: Attiva

### Verifica funzionamento:
1. Vai in **Impostazioni** → "Test Allineamento FIC"
2. Clicca "Esegui Test Allineamento"
3. Controlla:
   - Fatture senza `fic_document_id` → devono essere create su FIC
   - `mail_status = pending` con errore → anagrafica incompleta
   - `mail_status = sent` → invio completato con successo

---

## 📋 Passo 6: Automatizzazione (Opzionale)

### Setup Cron per Sincronizzazione Automatica

**URL da chiamare periodicamente:**
```
https://crm.example.com/modules/pagamenti/sincronizza_stripe.php?scheduled=1&cron=SECRET
```

### Frequenza consigliata:
- **Bassa**: ogni 4-6 ore (per pochi ordini)
- **Media**: ogni 1 ora
- **Alta**: ogni 15-30 minuti (per molti ordini)

### Setup nelle Impostazioni:
1. Vai in **Impostazioni** → "Sincronizzazione Automatica Stripe"
2. Abilita: **Attiva**
3. Imposta intervallo (es. 60 minuti)
4. Configura cron esterno con l'URL fornito

---

## Risoluzione Problemi

### Problema: Fatture non si sincronizzano con FIC

**Verifica:**
1. Company ID FIC configurato (Impostazioni)
2. Token FIC valido (riconnetti se scaduto)
3. Esegui "Test Sincronizzazione FIC" per vedere errori dettagliati

**Soluzione:**
- Vai in ogni fattura → "Sincronizza con FIC" manualmente
- Oppure usa script bulk: `/modules/clienti/fic_bulk_prepare.php`

---

### Problema: Email non vengono inviate

**Verifica:**
1. SMTP configurato correttamente (Impostazioni → Email)
2. Cliente ha email valida + P.IVA + anagrafica
3. `mail_status` della fattura:
   - `null` → mail non tentata
   - `pending` → requisiti mancanti (vedi `mail_error`)
   - `error` → errore invio (vedi `mail_error`)
   - `sent` → inviata con successo

**Soluzione:**
- Completa anagrafica cliente (email, P.IVA, nome/azienda)
- Testa invio email da Impostazioni → "Test configurazione email"

---

### Problema: PDF non disponibile

**Causa**: PDF FIC richiede qualche secondo per essere generato dopo la creazione del documento

**Soluzione automatica**: Il sistema:
1. Tenta download PDF con retry (3 tentativi × 500ms)
2. Se non disponibile, invia email con link FIC invece di PDF
3. Cron successivo può ritentare download PDF

---

## 📊 Monitoraggio

### Dashboard Consigliati:

1. **Pagamenti**: `/modules/pagamenti/`
   - Verifica pagamenti importati da Stripe

2. **Fatture**: `/modules/fatture/`
   - Filtra per metodo pagamento "Stripe"
   - Controlla `mail_status` per invii falliti

3. **Log Fatture**: `/modules/fatture/` → click su fattura → tab "Log eventi"
   - Storico sincronizzazioni e tentativi invio

4. **Test Allineamento**: `/modules/impostazioni/` → "Test Allineamento FIC"
   - Report completo stato sincronizzazione

---

## 🎯 Flusso Completo Ideale

```
PAGAMENTO STRIPE
       ↓
WEBHOOK/SINCRONIZZAZIONE
       ↓
CREA CLIENTE (se non esiste)
       ↓
CREA FATTURA (STR-INV-YYYYMMDD-XXX)
       ↓
VERIFICA ANAGRAFICA COMPLETA
       ↓
   SI ──┬──→ CREA DOCUMENTO FIC
        │          ↓
        │    PUBBLICA DOCUMENTO
        │          ↓
        │    FETCH PDF (con retry)
        │          ↓
        │    INVIA EMAIL (con PDF o link)
        │          ↓
        │    mail_status = 'sent'
        │
   NO ──┴──→ mail_status = 'pending'
             mail_error = 'anagrafica incompleta: ...'
```

---

## Sicurezza

- ✅ Solo admin possono sincronizzare
- ✅ Autenticazione richiesta per tutti gli endpoint
- ✅ Chiavi Stripe in `config/secret.php` (non committate su git)
- ✅ Cron protetto con secret key

---

## 📚 File Chiave

| File | Descrizione |
|------|-------------|
| `modules/pagamenti/sincronizza_stripe.php` | Script principale sincronizzazione |
| `modules/ordini/sincronizza_ordini_stripe.php` | Sincronizzazione manuale da pagamenti esistenti |
| `modules/fatture/actions/fic_mail_helpers.php` | Creazione documento FIC + invio email |
| `modules/fatture/actions/fic_api.php` | Wrapper API Fatture in Cloud |
| `modules/fatture/actions/test_fic_sync.php` | Test debug sincronizzazione |
| `modules/fatture/actions/fic_alignment_test.php` | Test allineamento completo |

---

## ✅ Checklist Finale

- [ ] Stripe configurato in Impostazioni
- [ ] FIC connesso (Company ID presente)
- [ ] SMTP configurato e testato
- [ ] Database migrato (colonne Stripe presenti)
- [ ] Invio automatico attivo (Impostazioni → Invio fatture)
- [ ] Prima sincronizzazione Stripe eseguita
- [ ] Test allineamento FIC OK
- [ ] Cron scheduler configurato (opzionale)

---

**Creato:** 23 Febbraio 2026  
**Ultima modifica:** 23 Febbraio 2026

