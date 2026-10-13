# Sistema Gestione Ordini

## Panoramica
Il modulo ordini permette di gestire ordini sia manuali che automatici da Stripe.

## Funzionalità

### 1. Ordini Manuali
Crea ordini manualmente tramite il pulsante "+ Nuovo ordine":
- Inserisci cliente, numero, data, stato e totale
- Aggiunge automaticamente righe fattura
- Stato: bozza, emessa, pagata, scaduta

### 2. Ordini da Stripe (Automatici)

#### Sincronizzazione Automatica
Quando sincronizzi i pagamenti Stripe (tramite `modules/pagamenti/sincronizza_stripe.php`):
- Ogni pagamento **succeeded** con cliente associato genera automaticamente un ordine
- Numero progressivo: `ORD-YYYYMMDD-XXX` (es. ORD-20260222-001)
- Stato: **pagata** (perché il pagamento è già confermato)
- Metodo pagamento: Stripe - [tipo]
- Note: Include descrizione del pagamento Stripe

#### Sincronizzazione Manuale
Usa il pulsante "🔄 Sincronizza Stripe" nella pagina ordini:
- Importa ordini da pagamenti Stripe che non hanno ancora un ordine associato
- Utile per recuperare ordini precedenti non sincronizzati
- Mostra quanti ordini sono stati creati/saltati

## Campi Stripe negli Ordini
Gli ordini creati da Stripe hanno questi campi popolati:
- `stripe_payment_intent_id`: ID del pagamento Stripe (es. ch_xxx)
- `metodo_pagamento`: "Stripe - card" o altro metodo
- `data_pagamento`: Data del pagamento su Stripe
- `stato`: "pagata"

## Statistiche Dashboard
La pagina ordini mostra:
- **Totali**: Tutti gli ordini (manuali + Stripe)
- **Valore**: Somma totale
- **Bozze/Emesse**: Ordini manuali non pagati
- **Pagati Stripe**: Solo ordini da Stripe (badge blu nella tabella)
- **Valore Stripe**: Totale incassato via Stripe

## Badge Pagamento
Nella tabella ordini, la colonna "Pagamento" mostra:
- 🟣 **Badge Stripe**: Ordine pagato con Stripe
- Testo grigio: Altri metodi di pagamento
- "-": Nessun pagamento registrato

## Workflow Consigliato

### Per ordini online (e-commerce, checkout):
1. Il cliente paga via Stripe Checkout
2. Webhook Stripe notifica il pagamento
3. Sincronizza pagamenti Stripe (manuale o schedulata)
4. Ordine creato automaticamente con stato "pagata"

### Per ordini manuali (telefono, email):
1. Crea ordine manualmente
2. Imposta stato: bozza/emessa
3. Quando il cliente paga, aggiorna stato a "pagata"
4. Sincronizza eventualmente con Fatture in Cloud

## Note Tecniche
- Gli ordini sono nella tabella `fatture` con `document_type='order'`
- I pagamenti Stripe sono nella tabella `pagamenti`
- Link: `fatture.stripe_payment_intent_id = pagamenti.stripe_id`
- Endpoint sincronizzazione: `modules/ordini/sincronizza_ordini_stripe.php`
- Sincronizzazione pagamenti: `modules/pagamenti/sincronizza_stripe.php`

## Limitazioni Attuali
- Gli ordini Stripe creano una riga generica (descrizione dal pagamento)
- Per dettagli prodotti specifici, modificare ordine manualmente dopo la creazione
- IVA impostata a 0% per default (da configurare manualmente se necessario)
