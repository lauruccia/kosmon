# Piano — esperienza d'acquisto "stile Amazon"

**Data:** 26/08/2026 · **Stato:** analisi, niente ancora implementato
**Richiesta di Laura:** «mi piacerebbe fare tutto il processo di acquisto simile ad
Amazon, così fluido e funzionale — controlla cosa manca e scrivi prima di implementare»

---

## 0. Prima di tutto: quello che c'è oggi funziona?

**Sì.** Suite eseguita nel cloud sul codice vivo del PC (HEAD = `9f3bd65`):

| File | Test | Esito |
|---|---|---|
| `CartPhaseCTest` | 26 | verdi |
| `VariantsPhaseDTest` | 43 | verdi |
| `ShopPurchaseRegressionTest` | 19 | verdi |
| `ShopPurchaseGuardsTest` | 13 | verdi |
| `OrdersPhaseBTest` | 11 | verdi |
| `OrderSnapshotOnTransfersTest` | 10 | verdi |
| `ShopSellerFilterTest` | 8 | verdi |
| **Totale** | **156** | **0 falliti, 0 skippati** |

E il motore, letto riga per riga, è solido:

- `OrderService::place()` prende i lock **sempre in ordine di id crescente** →
  due clienti che comprano gli stessi due prodotti in ordine opposto non si
  bloccano a vicenda;
- prezzo, scorte e stato del prodotto sono **riletti dentro la transazione**,
  non ci si fida di quello che c'era nel carrello;
- il saldo si controlla sul **totale**, prima di pagare chiunque: se il terzo
  venditore fallisce, i primi due non hanno incassato niente;
- le scorte si scalano sulla **combinazione** (la M può finire mentre il
  prodotto resta pieno di magliette);
- il titolo e l'etichetta della variante sono **snapshot** sull'`OrderItem`;
- doppio clic su "Vai alla cassa": il secondo POST trova il carrello già
  `ordered` e muore con "Il carrello è vuoto" → **nessun doppio addebito**.

**Il motore finanziario non va toccato.** Tutto quello che segue sta *sopra*.

---

## 1. Il buco più grosso: la cassa non esiste

Oggi il percorso è:

```
carrello  →  [confirm() del browser]  →  ADDEBITO  →  flash "ordine completato"
```

Fra il carrello e i soldi che partono c'è **una finestrella grigia di sistema**.
Non c'è nessuna pagina di cassa. Questo produce quattro problemi in una volta:

1. **Non si può cambiare niente.** L'indirizzo è quello del profilo, preso così
   com'è (`OrderService` legge `buyerAccount->shipping_address`). Se è sbagliato
   bisogna uscire dal carrello, andare nel profilo, correggere, tornare.
2. **Non si sceglie la consegna.** `delivery_type` sul prodotto sa già dire
   spedizione / ritiro / servizio, ma il compratore non decide mai niente.
3. **Il `confirm()` è fragile.** Non è brandizzato, non è accessibile, alcuni
   browser mobile lo sopprimono dopo il primo, e se sparisce il clic diventa
   un addebito immediato senza conferma.
4. **Nessuna spunta sulle condizioni.** Per la vendita a privati in Italia il
   bottone finale deve dire esplicitamente che l'ordine comporta un obbligo di
   pagamento, e vanno accettate condizioni di vendita e informativa sul recesso.
   *(Non sono un avvocato: la forma esatta va confermata dal tuo legale — qui la
   segnalo perché è la parte che oggi manca del tutto.)*

Amazon, per confronto, mette fra carrello e addebito una pagina sola con:
indirizzo (modificabile lì), metodo di consegna, riepilogo per venditore,
totale finale, e **un solo bottone** che dice quanto stai per pagare.

---

## 2. Il secondo buco: dopo l'acquisto non c'è niente

Cerco `Order::` in tutto il codice: compare **solo dentro `OrderService`**.
Non c'è una rotta, una view o una voce di menu che mostri un ordine.

Conseguenze concrete:

- **Il compratore non ha "I miei ordini".** Ritrova l'acquisto solo come riga
  in *Movimenti*, insieme a bonifici e ricariche. Non vede lo stato, non vede
  l'indirizzo a cui è partito, non ha un numero d'ordine da citare.
- **Il venditore non ha "Ordini ricevuti".** Riceve una notifica
  (`NewMarketplaceOrderNotification`) e finisce lì. La pagina
  `/admin/listings/ordini` esiste ma è **backoffice**: la vede l'admin del
  circuito, non l'azienda che deve spedire.
- **L'ordine non ha un ciclo di vita.** `Order::STATUSES` ha due voci:
  `pending_payment` e `paid`. Non esiste "in preparazione", "spedito",
  "consegnato", "annullato". Senza questi, nessuno può sapere se il pacco è
  partito — né il compratore né il venditore.
- **Il compratore non riceve nessuna email.** Viene avvisato solo il venditore.
  Amazon manda tre messaggi (confermato / spedito / consegnato); noi zero.
- **Nessuna ricevuta.** `barryvdh/laravel-dompdf` è già in casa e già usato per
  la carta: manca solo il template.
- **Nessun annullamento, nessun reso.** Il rimborso esiste
  (`TransferBookingService::refundMerchant`) ma il venditore lo lancia dai
  *Movimenti*, non dall'ordine, e il compratore non ha modo di chiederlo.

---

## 3. Il terzo buco: la scoperta del prodotto

Il catalogo filtra bene (testo, categoria, sottocategoria, % KY, venditore) ma:

| Manca | Perché pesa |
|---|---|
| **Ordinamento** | Non si può ordinare per prezzo, novità o popolarità. Oggi l'ordine è fisso: `featured` poi `created_at`. È la cosa che si nota per prima. |
| **Recensioni e stelle** | Zero modelli, zero tabelle. In un circuito chiuso la fiducia fra aziende è tutto: è il pezzo che fa più differenza dopo la cassa. |
| **Salva per dopo / lista desideri** | Il carrello è l'unico posto dove parcheggiare un prodotto, e alla cassa parte tutto insieme. |
| **Consegna stimata** | La scheda non dice mai "ti arriva entro il…". |
| **Visti di recente / correlati** | Non si torna indietro a un prodotto guardato ieri. |
| **Conteggio risultati sui filtri** | Non si sa quanti prodotti ci sono dietro una categoria prima di cliccarla. |

---

## 4. Il quarto buco: gli attriti piccoli

Questi non rompono niente, ma sono esattamente ciò che rende Amazon "fluido":

- **"Aggiungi al carrello" ricarica la pagina** (`back()` + flash). Amazon apre
  un pannello laterale e ti lascia dov'eri.
- **La quantità nel carrello richiede un clic su "Aggiorna".** Servono i
  pulsanti − / + che aggiornano da soli.
- **Nessuna pagina "grazie"** con il numero d'ordine: si torna allo shop con un
  messaggio verde che sparisce.
- **Nessun "ricompra"** da un ordine passato.
- **Il carrello non scade mai.** Nessun rischio sui soldi (il prezzo è sempre
  quello vivo, `CartItem` non salva nessuno snapshot), ma resta lì per sempre.
- **Nessun avviso se il prezzo è cambiato** da quando avevi messo il prodotto
  nel carrello: paghi il nuovo senza che nessuno te lo dica.

---

## 5. Piano proposto — 5 fasi

Ordinate per **quanto male fa non averle**, non per quanto sono divertenti.

### Fase A — La cassa vera *(la più importante)*

Una pagina `GET /shop/carrello/cassa` fra il carrello e l'addebito:

- riepilogo per venditore, con spedizione e totale finale;
- **indirizzo di spedizione modificabile lì**, senza uscire (salva sull'Account);
- scelta consegna dove il prodotto la consente (spedizione / ritiro);
- campo note per il venditore (nuova colonna su `orders`);
- spunta condizioni di vendita + recesso;
- un solo bottone che dice la cifra, e che si disabilita al clic;
- il `confirm()` del browser sparisce.

Il POST `/shop/carrello/cassa` resta identico: cambia solo chi lo chiama.
**`CartService::checkout()` e `OrderService::place()` non si toccano.**

### Fase B — Gli ordini, da entrambe le parti

- `orders.status` cresce: `pending_payment` → `paid` → `preparing` → `shipped`
  → `delivered`, più `cancelled`. Migrazione additiva, i vecchi ordini restano
  `paid`.
- Colonne nuove: `tracking_code`, `carrier`, `shipped_at`, `delivered_at`,
  `buyer_note`, `cancelled_at`, `cancel_reason`.
- **`/ordini`** per il compratore: elenco, dettaglio, stato, indirizzo, ricompra.
- **`/azienda/ordini`** per il venditore: elenco, dettaglio, "segna spedito"
  (con tracking), "segna consegnato".
- Pagina "grazie" con numero d'ordine dopo la cassa.
- Voci di menu nuove sotto *Circuito*, con la loro chiave `MenuVisibility`.

### Fase C — Le notifiche al compratore

- `OrderPlacedNotification` (mail + database + webpush) al compratore.
- `OrderShippedNotification` quando il venditore segna spedito.
- `OrderDeliveredNotification`.
- Tutte via `RespectsNotificationPreferences`, come le altre.
- Ricevuta PDF dell'ordine con dompdf, scaricabile dal dettaglio.

### Fase D — Fiducia e scoperta

- **Recensioni**: `order_reviews` (1..5 stelle + testo), scrivibili **solo da
  chi ha un ordine `delivered`** per quel prodotto. Media sulla scheda e sulla
  card. È il pezzo che cambia di più la percezione dello shop.
- **Ordinamento** nel catalogo: rilevanza / prezzo ↑ / prezzo ↓ / novità /
  più venduti (`orders_count`).
- **Salva per dopo** dal carrello e **lista desideri** dalla scheda.

### Fase E — Le rifiniture

- Pannello laterale "aggiunto al carrello" senza ricaricare.
- Stepper − / + con aggiornamento automatico.
- "Visti di recente" e prodotti correlati dello stesso venditore.
- Avviso "il prezzo è cambiato" nel carrello.
- Conteggio risultati sui filtri.

---

## 6. Rischi da tenere d'occhio

1. **Non toccare `TransferBookingService` né `OrderService::place()`.** Tutto il
   piano vive sopra il motore: nuove pagine, nuove colonne, nuove notifiche.
   L'unica riga che entra in `place()` è il salvataggio della nota del
   compratore, e solo se decidiamo di metterla lì.
2. **Il cambio di stato dell'ordine non deve muovere soldi.** "Spedito" e
   "consegnato" sono etichette; l'addebito è già avvenuto alla cassa.
3. **L'annullamento invece muove soldi** e va trattato come un rimborso vero,
   passando da `refundMerchant`, con le sue regole di saldo del venditore.
   Va progettato a parte, dentro la Fase B, e testato come tale.
4. **La migrazione sugli stati dev'essere additiva.** In produzione ci sono già
   ordini `paid`: nessun backfill che li sposti.
5. **Le recensioni sono dati di persone.** Vanno legate a un ordine consegnato,
   non lasciate libere, o diventano un canale di spam nel circuito.
6. **Ogni fase ha i suoi test + le mutazioni deliberate**, come le fasi
   precedenti (1, 2a, 2b): un test che non cade quando rompo apposta la riga
   che dovrebbe proteggere, non è un test.

---

## 7. Le decisioni di Laura (26/08/2026)

1. **Si parte dalla Fase A.**
2. **Lo stato dell'ordine lo cambiano il venditore E l'admin del circuito.**
3. **Il reso lo richiede il compratore**: apre una richiesta, il venditore
   l'accetta. Non resta un'azione del solo venditore.
4. **Le recensioni si pubblicano dopo l'acquisto, come su Amazon.**
   L'admin puo' moderarle: nasconderle o eliminarle.

Queste decisioni cambiano le fasi B e D rispetto a come erano scritte sopra:

- **Fase B** — gli stati dell'ordine sono scrivibili da due parti (venditore
  dal portale azienda, admin dal backoffice), e ogni cambio finisce in
  `AuditLog` con l'autore: senza, non si saprebbe mai chi ha segnato "spedito".
  Il reso diventa un oggetto suo (`order_return_requests`): la richiesta del
  compratore, l'accettazione del venditore, e solo allora il rimborso vero via
  `refundMerchant`.
- **Fase D** — le recensioni nascono **pubblicate** (non in attesa di
  approvazione: bloccarle tutte in coda le ucciderebbe), ma hanno uno stato
  che l'admin puo' portare a `hidden` o cancellare del tutto. Scrivibili solo
  da chi ha un ordine `delivered` per quel prodotto.

---

## 8. Fase A — FATTA (26/08/2026)

**Il motore finanziario non e' stato toccato.** `TransferBookingService` non ha
una riga di differenza; `OrderService::place()` e `CartService::checkout()`
hanno solo un parametro in piu' (`buyerNote`), con default `null`, quindi ogni
chiamante esistente si comporta esattamente come prima.

### Che cosa e' cambiato per chi compra

Prima: carrello -> finestrella grigia del browser -> soldi via.
Adesso: carrello -> **pagina di cassa** -> soldi via -> **pagina "grazie"**.

La cassa (`/shop/carrello/cassa`) ha tre passi:

1. **Indirizzo di spedizione**, precompilato e correggibile li' — si salva sul
   conto e vale anche per i prossimi acquisti. Compare solo se nel carrello
   c'e' qualcosa da spedire.
2. **Il tuo ordine**, per venditore, con come arriva ogni riga e la spedizione
   separata, piu' il campo **nota per il venditore** (500 caratteri).
3. **La spunta sulle condizioni**, che dice a chiare lettere che l'ordine
   comporta un obbligo di pagamento e di quanto.

Il bottone e' uno solo, dice la cifra, e si spegne al primo clic.

La pagina "grazie" (`/shop/carrello/grazie?ids=...`) mostra il numero
d'ordine, il dettaglio, l'indirizzo, la nota, e — se resta una quota in euro —
il pulsante per saldarla, **una per ogni venditore**. Prima, con due quote in
euro, si tornava allo shop e bisognava cercarsele nei movimenti.

### File toccati

| File | Che cosa |
|---|---|
| `database/migrations/2026_08_26_120000_add_buyer_note_to_orders_table.php` | **nuovo** — colonna `orders.buyer_note`, additiva, nessun backfill |
| `app/Models/Order.php` | `buyer_note` nei `$fillable` |
| `app/Services/OrderService.php` | `place(..., ?string $buyerNote = null)` e salvataggio della nota |
| `app/Services/CartService.php` | `checkout(..., ?string $buyerNote = null)`, passata a `place()` |
| `app/Http/Controllers/CartController.php` | `checkoutForm()`, `thanks()`, `checkout()` con validazione, 2 helper privati |
| `routes/web.php` | `GET /shop/carrello/cassa`, `GET /shop/carrello/grazie` |
| `resources/views/portal/checkout.blade.php` | **nuova** — la cassa |
| `resources/views/portal/checkout-thanks.blade.php` | **nuova** — il "grazie" |
| `resources/views/portal/cart.blade.php` | il bottone diventa un link alla cassa; via il `confirm()` |
| `tests/Feature/CheckoutPhaseATest.php` | **nuovo** — 23 test |
| `tests/Feature/CartPhaseCTest.php`, `VariantsPhaseDTest.php` | i 14 POST alla cassa ora passano la spunta |

### Verifica

**23 test nuovi**, tutti verdi. Suite shop: **153 passati** (130 di prima + 23).
Suite intera: **1169 passati, 5 falliti** — gli stessi 5 gia' noti dal 25/08
(`AdminBackofficeTest`, `CashbackRuleControllerTest`, `CompanyKyAcceptanceTest`
x3), nessuno dei quali tocca carrello, cassa o ordini.

**10 mutazioni deliberate**, tutte uccise:

| Rompo apposta | Cade |
|---|---|
| `accetto_condizioni` da `accepted` a `nullable` | 2 test |
| tolgo la guardia sul carrello vuoto | 1 |
| tolgo la guardia sul saldo | 1 |
| tolgo la guardia sull'indirizzo | 1 |
| tolgo la guardia sulla disponibilita' | 1 |
| salvo un indirizzo parziale | 1 |
| tolgo `buyer_note` dai `fillable` | 1 |
| tolgo `where('buyer_account_id')` dalla pagina grazie | 1 |
| `max:500` diventa `max:5000` sulla nota | 1 |
| il carrello punta altrove | 1 |

### Due bug trovati mentre scrivevo i test

1. **Il carrello vuoto apriva la cassa.** Le tre guardie (disponibilita',
   indirizzo, saldo) passano tutte con un carrello vuoto — zero indisponibili,
   nessun indirizzo richiesto, saldo 0 >= totale 0. Mancava il controllo su
   `isVuoto()`.
2. **Blade non compilava `KY@if(...)`.** Una direttiva attaccata a una parola
   resta testo letterale e il suo `@endif` finisce orfano: la pagina moriva con
   "unexpected token endif". Sostituita con un ternario dentro `{{ }}`.

### Da fare prima di vedere la pagina

```bash
php artisan migrate      # senza la colonna buyer_note la cassa va in errore
```

### Rimasto fuori di proposito

- **La scelta della consegna.** Nel piano c'era "spedizione o ritiro", ma oggi
  il modello non la prevede: `delivery_type` sta **sul prodotto** ed e' il
  venditore a deciderlo, uno solo per prodotto. Non c'e' niente da scegliere.
  Per darla davvero serve permettere al venditore di offrire **entrambe** le
  modalita' sullo stesso prodotto — un cambio al form prodotto e alla tabella
  `listings`. In cassa, intanto, e' scritto per ogni riga come arriva.
- **Le condizioni di vendita dello shop.** La spunta oggi punta a
  `/termini` e `/privacy`, che esistono gia'. Una pagina dedicata alle
  condizioni di vendita e al diritto di recesso va scritta dal tuo legale: il
  link e' pronto, basta cambiargli destinazione.
