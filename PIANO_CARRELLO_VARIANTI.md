# Piano: prodotti variabili + carrello con checkout completo

**Data:** 25/08/2026 · **Dove:** `kmoney-app` (shop interno, quindi kmoney.it **e** kosmopay.it)
**Stato:** piano da approvare — nessuna riga di codice ancora scritta.

---

## 1. Le tue decisioni

| Domanda | Decisione |
|---|---|
| Dove si costruisce | **Nello shop interno di kmoney-app.** Il piano kshop va in pausa. |
| Varianti | **L'admin crea e gestisce gli attributi e i loro valori** (Taglia, Colore, Formato…). Il venditore non inventa attributi: sceglie fra quelli che hai definito tu. |
| Carrello multi-venditore | **Un carrello solo.** Alla cassa si divide automaticamente in un ordine per venditore. |

### Cosa succede a kshop

Le fasi già fatte (**0a** test, **0b** snapshot ordini, **1** OAuth "Accedi con KMoney", **2a/2b** mandato di
pagamento) **non si buttano**: sono tutte funzionalità di KMoney *come banca*, vivono da sole e restano in
produzione. Quello che si ferma è solo la fase 3 (creare l'app kshop e migrarci il catalogo).

Il prezzo di questa scelta va detto chiaro: ogni tabella che aggiungiamo oggi allo shop interno
(ordini, righe ordine, carrello, varianti) è roba in più da portare fuori il giorno in cui kshop ripartirà.
Per questo il piano qui sotto è costruito in modo che **il nuovo ordine sia già un'entità autonoma, con i
suoi snapshot** — cioè già nella forma che kshop userebbe. Se un domani si migra, si esporta una tabella
`orders` pulita invece di ricostruire gli ordini dai movimenti bancari.

---

## 2. Da dove partiamo (e perché il carrello oggi non ci entra)

Nello shop di oggi **l'ordine non esiste come oggetto**. L'ordine *è* il movimento bancario:

```
ListingController::buy()  →  1 Transfer (kind = portal_marketplace_order)
                             con dentro: listing_id, quantity, indirizzo di
                             spedizione, order_title (snapshot), e basta.
```

E la quota in euro sta in `marketplace_order_payments`, agganciata a quel movimento con un vincolo
**`transfer_id` unique**: un pagamento EUR per movimento, cioè per prodotto.

Da qui discendono i tre muri contro cui sbatte il carrello:

1. **Un movimento = un prodotto.** Tre prodotti nel carrello oggi vorrebbero dire tre movimenti separati
   e nessun posto dove scrivere "questi tre sono lo stesso ordine".
2. **Un pagamento EUR per movimento.** Con il carrello l'acquirente deve pagare *una* quota euro per
   venditore, non una per prodotto. Il vincolo `unique` lo impedisce fisicamente.
3. **Lo stock sta sul prodotto** (`listings.stock_quantity`), non sulla combinazione taglia/colore.

Nessuno di questi tre è un difetto: era la scelta giusta per "compra un prodotto alla volta". Semplicemente
il carrello richiede un livello che oggi manca.

---

## 3. Il principio che tiene tutto insieme: **il denaro non cambia**

Questa è la risposta a "senza rompere niente".

Oggi un acquisto chiama `TransferBookingService::book()` una volta. Domani un carrello con prodotti di due
aziende chiamerà `book()` **due volte, con esattamente gli stessi parametri di oggi**: stesso mittente,
stesso destinatario, stesso importo, stesso `kind`, stessa idempotency key.

- Il motore finanziario **non viene toccato**: zero modifiche a `TransferBookingService`, al ledger, ai limiti.
- L'invariante del circuito chiuso (`SUM(available_balance) = 0`) è salvo per costruzione: stiamo solo
  facendo *più volte* un movimento che è già dimostrato corretto, non un movimento nuovo.
- I movimenti che il cliente vede in "Movimenti" restano identici a quelli di oggi.

Tutto il resto (ordini, righe, carrello, varianti) vive **sopra** la banca, in tabelle nuove che la banca
non legge. Se domani cancellassimo l'intero carrello, la contabilità resterebbe in piedi.

---

## 4. Le cinque fasi

Ogni fase è **deployabile da sola** e lascia il sito funzionante. Non si passa alla successiva finché la
precedente non è in produzione su entrambi i siti e verificata.

### Fase A — Rete di sicurezza (mezza giornata) · *nessuna modifica al codice*

I test di regressione sull'acquisto esistono (`ShopPurchaseRegressionTest`, fase 0a), ma coprono il caso
base. Prima di rifare le fondamenta li estendiamo a **tutti i rami che toccheremo**:

- acquisto 100% KY / mix KY+EUR / con offerta settimanale attiva
- stock limitato che si esaurisce, e stock illimitato (`NULL`)
- prodotto "da spedire" senza indirizzo → blocco prima dell'addebito
- venditore senza gateway EUR configurato → blocco prima dell'addebito
- azienda in debito → mix forzato al 100% KY
- prodotto sospeso/scaduto/della propria azienda → rifiuto

Ogni test viene verificato con una **mutazione deliberata** del controller (rompo la riga, il test deve
diventare rosso). Un test che resta verde su codice rotto è un test che non protegge niente — è già
successo in fase 0a.

**Deploy:** nessuno. È solo la cintura di sicurezza per le fasi B–E.

---

### Fase B — L'ordine diventa un'entità (1,5 giorni) · *la fase delicata*

Niente di visibile cambia per l'utente. Cambia cosa c'è sotto.

**Tabelle nuove:**

```
orders           uuid, buyer_account_id, company_id (venditore), status,
                 total_ky, total_eur, shipping_* (snapshot), placed_at
order_items      order_id, listing_id (nullable), variant_id (nullable),
                 title, variant_label, unit_price_ky, ky_percentage,
                 quantity, line_ky, line_eur      ← tutto snapshot
```

Le righe sono **snapshot puri**: titolo, prezzo e mix vengono congelati al momento dell'acquisto, come già
si fa con `order_title` sui movimenti (fase 0b). Se il venditore poi rinomina il prodotto, cambia prezzo o
lo cancella, l'ordine di ieri resta leggibile per sempre.

**Modifiche a tabelle esistenti (tutte additive):**

- `transfers` → nuova colonna `order_id` nullable. `listing_id`, `quantity`, `order_title` **restano dove
  sono**: sono lo snapshot bancario e non si toccano.
- `marketplace_order_payments` → nuova colonna `order_id`; `transfer_id` diventa nullable e **perde il
  vincolo unique**. È l'unica modifica distruttiva del piano e va fatta qui, da sola, quando ancora nessuno
  sta usando il carrello.

**Codice:** nasce `OrderService::place()`, che è letteralmente il corpo attuale di `buy()` spostato di
posto. `ListingController::buy()` diventa quattro righe che chiamano `OrderService` con un carrello di una
riga sola. Stessi controlli, stessi messaggi, stessi redirect.

**Backfill dello storico (raccomandato):** ogni movimento `portal_marketplace_order` esistente diventa un
`order` con una `order_item`. È un `INSERT ... SELECT` deterministico, e se sbaglia si svuota la tabella
nuova e si rifà — lo storico bancario non viene toccato. Il motivo per farlo: senza backfill ogni pagina
che mostra ordini deve gestire per sempre due formati ("vecchio stile" e "nuovo stile"), ed è lì che poi
nascono i bug che si vedono a marzo.

**Deploy:** prima l'SQL su phpMyAdmin (a blocchi ri-eseguibili, con la guardia sull'INSERT in `migrations`),
poi il codice. La regola di fase 0b.

**Come si verifica che è andata bene:** i test della fase A devono passare **senza essere stati modificati**.
Se ho dovuto cambiare un test per farlo passare, ho cambiato un comportamento e devo fermarmi.

---

### Fase C — Il carrello (1,5 giorni)

```
carts         account_id, status (active|ordered|expired), expires_at (+30gg)
cart_items    cart_id, listing_id, variant_id (nullable), quantity
```

- Il carrello vive **sul conto**, non in sessione: chi lo riempie dal telefono lo ritrova dal PC.
- Nel carrello **non si congela il prezzo**. Il prezzo si legge sempre dal prodotto al momento della cassa
  (con `effective_price_ky`, quindi le offerte settimanali continuano a funzionare da sole). Se un prezzo è
  cambiato da quando l'hai messo dentro, la pagina carrello lo segnala prima di pagare.
- Il bottone **"Compra ora" resta**, accanto a "Aggiungi al carrello". Chi compra un prodotto solo non deve
  passare da tre pagine — e per noi è la strada già collaudata che resta sempre percorribile.
- Pagina carrello **raggruppata per venditore**, con il totale KY e il totale EUR di ciascun gruppo, perché
  è quello che l'acquirente pagherà separatamente.

**Alla cassa**, dentro un'unica transazione di database:

1. lock delle righe prodotto/variante **in ordine di id crescente** (vedi rischio #3), controllo scorte
2. controllo saldo KY sul **totale complessivo**, non venditore per venditore — così se manca il saldo si
   scopre *prima* di aver pagato il primo venditore, e si mostra il link "Ricarica ora" con ritorno al
   carrello (meccanismo che esiste già dal 10/08)
3. un `order` + le sue `order_items` per ogni venditore
4. un `book()` per ogni venditore
5. un `marketplace_order_payment` per ogni ordine che ha una quota EUR > 0
6. carrello marcato `ordered`

Se qualunque passo fallisce, **cade tutto insieme**: nessun ordine a metà, nessun venditore pagato e
l'altro no.

---

### Fase D — Prodotti variabili (2 giorni)

**Gli attributi li gestisci tu dall'admin**, esattamente come già fai con le categorie shop
(`Admin → Shop → Categorie`, tabella `listing_categories`). Stessa pagina, stessa logica.

```
listing_attributes         name ("Taglia"), slug, position, is_active
listing_attribute_values   attribute_id, value ("M"), position, is_active
listing_variants           listing_id, sku, price_delta_ky, stock_quantity,
                           image, status
listing_variant_values     variant_id, attribute_value_id      ← pivot
listings                   + has_variants (boolean)
```

**Il venditore** apre il prodotto, spunta quali valori usa (Taglia: S, M, L — Colore: rosso, blu) e il
sistema propone la griglia delle 6 combinazioni. Per ognuna può mettere scorte e prezzo, oppure lasciarle
com'è e prendere quelle del prodotto padre.

**Il prezzo della variante è un delta, non un prezzo assoluto.** Il venditore digita "22" e il sistema
salva "+2" rispetto ai 20 del padre. Motivo: così l'**offerta della settimana continua a funzionare senza
toccare `ListingOffer`** — l'offerta abbassa il prezzo base, il delta della XL resta valido, e il conto
torna da solo. Con i prezzi assoluti avremmo dovuto vietare le offerte sui prodotti variabili, oppure
scrivere un secondo motore di prezzi accanto a quello esistente.

**Lo stock si sposta sulla variante.** `listings.stock_quantity` resta per i prodotti senza varianti (la
grande maggioranza) e non cambia significato: nessun backfill, nessun rischio sui prodotti già online.

**Nella pagina prodotto** compaiono i selettori; finché non hai scelto tutto, "Aggiungi al carrello" è
disabilitato. Le combinazioni esaurite si vedono barrate invece di sparire — chi cerca la M vuole sapere
che la M esiste ma è finita, non credere che tu non la faccia.

---

### Fase E — Checkout e ciclo di vita dell'ordine (1 giorno)

- **Pagina di riepilogo** prima di confermare: indirizzo di spedizione, spedizione calcolata **una volta per
  venditore** (non per prodotto: coerente con la regola di oggi, un pacco = una spedizione), mix KY/EUR per
  ogni gruppo, totale.
- **Stati dell'ordine**: `in attesa pagamento EUR` → `pagato` → `spedito` → `consegnato` (+ `annullato`).
  Oggi il venditore riceve una notifica e finisce lì; con il carrello serve un posto dove seguire l'ordine.
- **"I miei ordini"** per chi compra, **"Ordini ricevuti"** per chi vende (con il bottone "Segna come
  spedito" e il campo tracking).
- **La quota EUR si paga una volta per ordine**, non per prodotto: `PaymentController` passa da
  `transfer_id` a `order_id`. Gli ordini vecchi continuano a funzionare (il backfill della fase B gli ha già
  dato un `order_id`).
- **Notifiche**: si estende `NewMarketplaceOrderNotification` a elencare le righe dell'ordine invece del
  singolo prodotto.

---

## 5. I rischi veri, e come li disinneschiamo

| # | Rischio | Disinnesco |
|---|---|---|
| 1 | **`marketplace_order_payments.transfer_id` è `unique`** — con più prodotti dello stesso venditore in un ordine, il secondo `INSERT` esplode. | Si toglie il vincolo in **fase B**, da sola, prima che esista un carrello. SQL a blocchi, verificato con `SHOW CREATE TABLE` (l'utente phpMyAdmin di cPanel non legge `information_schema`). |
| 2 | **Gli importi sono in centesimi** (`price_ky` intero, `ky_format()` divide per 100). Un float nel calcolo del delta variante = il bug x100 del 24/07 che ritorna. | Delta variante `integer`, stessi cast del padre, mai `float`, mai `/100` fuori da `ky_format()`. Test dedicato con prezzo `17,00` e delta `2,50`. |
| 3 | **Deadlock alla cassa.** Due clienti comprano gli stessi due prodotti in ordine inverso: il lock incrociato blocca il database. Oggi non può succedere (un prodotto solo per acquisto). | I lock si prendono **sempre in ordine di id crescente**, e il carrello si ordina prima di iniziare. Test con due checkout concorrenti. |
| 4 | **Ordini a metà.** Il primo venditore incassa, il secondo fallisce. | Tutto dentro una `DB::transaction`, e il saldo si controlla sul totale **prima** di qualunque `book()`. |
| 5 | **Le pagine che leggono gli ordini vecchi** (Movimenti, "Ordini" admin, mail venditore) smettono di funzionare. | Il backfill della fase B porta tutto allo stesso formato: una sola strada di lettura invece di due. |
| 6 | **`Account::syncListingsKyPercentage()`** fa `UPDATE` diretta su `listings` quando un'azienda va in debito. Con le varianti c'è una tabella in più. | I delta variante sono **indipendenti dal mix KY/EUR** (il mix vive sul padre), quindi quell'hook resta com'è: non deve sapere che le varianti esistono. Verificato con un test apposta. |
| 7 | **Il deploy di kmoney.it è bloccato dal 30/06** per modifiche non committate sul server, e lì il checkout git *è* la cartella viva. | **Prerequisito, prima della fase A.** Si guarda cosa sono quelle modifiche, si decide se salvarle o buttarle, e si riporta il server a poter deployare. Altrimenti lavoriamo tre settimane su qualcosa che non possiamo pubblicare. |

---

## 6. Cosa NON si tocca (per iscritto, così è verificabile)

- `TransferBookingService` e tutto il ledger: **zero modifiche**.
- L'invariante `SUM(available_balance) = 0` e i tre controlli di `accounting:verify-integrity`.
- I limiti di credito, il MLM, il cashback, le commissioni.
- Il mix KY/EUR per prodotto e le regole sulle aziende in debito.
- OAuth "Accedi con KMoney" e il mandato di pagamento (fasi 1, 2a, 2b): restano in produzione, non entrano
  in questo lavoro e non vengono modificati.
- Il flusso "Compra ora" a prodotto singolo: resta selezionabile per sempre.

---

## 7. Regole di lavoro per tutte le fasi

1. **Prima l'SQL, poi il codice** (regola di fase 0b): le migrazioni in produzione si applicano a mano da
   phpMyAdmin, a blocchi ri-eseguibili, con la guardia sull'`INSERT` nella tabella `migrations`.
   Mai un segnaposto da sostituire a mano, mai incollare il file intero (le due trappole di fase 1).
2. **Ogni fase ha i suoi test, verificati con mutazioni deliberate.** Baseline nota: 876 test, 6 fallimenti
   pre-esistenti che non sono nostri.
3. **Un commit per fase**, in italiano, che dice cosa cambia per l'utente.
4. **Si deploya su kosmopay.it per primo** (deploy normale), si verifica, poi kmoney.it.

---

## 8. Tempi

| Fase | Durata | Cosa vede l'utente alla fine |
|---|---|---|
| Prerequisito | ~mezza giornata | niente (si sblocca il deploy di kmoney.it) |
| A · rete di sicurezza | 0,5 g | niente |
| B · l'ordine diventa entità | 1,5 g | niente (ma "Ordini" in admin diventa più ricca) |
| C · carrello | 1,5 g | **"Aggiungi al carrello" e la cassa** |
| D · varianti | 2 g | **Taglie e colori selezionabili** |
| E · checkout completo | 1 g | **Riepilogo, stati ordine, "I miei ordini"** |

**Totale ~7 giorni di lavoro effettivo**, in 5 consegne indipendenti.

---

## 9. Le due cose che servono da te prima di partire

1. **Sblocchiamo il deploy di kmoney.it?** (rischio #7) — è il vero prerequisito.
2. **Faccio il backfill dello storico ordini in fase B?** Io lo consiglio: costa un'ora e ci evita di
   trascinarci per sempre due formati di ordine.
