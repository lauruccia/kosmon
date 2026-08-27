# Audit area ecommerce — 26/08/2026

**Stato del codice:** HEAD `6dfca8e` (Fase A + A-bis fatte, da pushare)
**Metodo:** lettura del codice vivo sul PC. Le cinque voci del blocco 1 sono
state rilette a mano una per una prima di finire qui: sono confermate, non
sospetti.

Il motore finanziario (`TransferBookingService`, `OrderService::place`) resta
solido e **non va toccato**. Tutto quello che segue sta *attorno*.

---

## Blocco 1 — Cose che toccano i soldi. Prima di tutto il resto.

### 1.1 Doppio addebito con due richieste **contemporanee** alla cassa

`app/Services/CartService.php:174-234`

Il carrello viene letto **fuori** dalla transazione (`Cart::attivoPer()`, riga
174) e lo stato passa a `ordered` solo alla riga 234, in fondo. Dentro
`DB::transaction` non c'è `lockForUpdate()` sul carrello né una rilettura del
suo stato.

- Doppio **clic** (uno dopo l'altro): protetto, come sapevamo. Il secondo POST
  arriva a transazione chiusa, trova il carrello già `ordered` e muore.
- Due POST **davvero simultanei** (due schede, doppio tap su mobile lento,
  `throttle:payments` permette 15/min): **non protetto**. Entrambi leggono il
  carrello `active`, entrambi entrano in transazione. Il secondo aspetta il
  lock sui listing, poi rilegge prezzo e scorte — e se il prodotto ha scorta
  illimitata **non trova niente che lo fermi**: secondo giro di ordini,
  **secondo addebito completo**. Il `$cart->update()` finale diventa un no-op.
  L'utente non se ne accorge nemmeno: il carrello è sparito una volta sola.

La sola difesa oggi è JavaScript (`checkout.blade.php:239-243`), che non
protegge da due schede. E `OrderService::place()` genera un
`idempotency_key` nuovo a ogni chiamata (`OrderService.php:286`), quindi
l'idempotenza del motore **non copre** questo caso.

**Correzione, poche righe:** dentro la transazione ricaricare il carrello con
`lockForUpdate()` e abortire se `status !== active`.

### 1.2 `orders.status` non diventa mai `paid` quando entrano gli euro

`app/Services/OrderService.php:224` è **l'unico punto** di tutta l'applicazione
che scrive `orders.status`, e lo fa alla creazione. Quando il compratore salda
la quota in euro viene aggiornato solo `MarketplaceOrderPayment`
(`PaymentController.php:168`, `StripeCheckoutDriver.php:97`,
`PayPalOrdersDriver.php:140`). L'ordine resta `pending_payment` **per sempre**.

Oggi non si vede perché nessuno guarda gli ordini. Il giorno in cui esiste la
pagina ordini della Fase B, ogni ordine misto mostrerà "In attesa del pagamento
in euro" a euro incassati. **Va sistemato prima di costruire la Fase B, non
dopo.**

### 1.3 Il rimborso restituisce i soldi ma non le scorte, e non chiude l'ordine

`app/Services/TransferBookingService.php:425+`. `refundMerchant` accetta
esplicitamente `portal_marketplace_order` fra i tipi rimborsabili, ma nel suo
corpo non compaiono mai `Order`, `OrderItem`, `stock_quantity`. Il pezzo
scalato in `OrderService.php:293-298` **resta scalato**.

Conseguenza: ogni reso svuota il magazzino di un pezzo che è tornato indietro.
Dopo qualche reso il venditore risulta esaurito con la merce in mano. Il piano
parla di resi in Fase B ma **non nomina mai la restituzione delle scorte**.

### 1.4 Nessun importo minimo sulla quota in euro → ordine bloccato per sempre

Nessun controllo di minimo in `OrderService`, `PaymentController` o nei driver.
`StripeCheckoutDriver.php:41` passa l'importo così com'è, ma **Stripe rifiuta
sotto 50 centesimi**.

Scenario: prodotto a 1,00 KY con mix 75% → quota euro 25 centesimi. I KY escono
per primi (`OrderService.php:262`), poi il pagamento da 0,25 € viene rifiutato.
Se il venditore ha solo Stripe attivo, l'ordine resta `pending_payment` a tempo
indefinito **con i KY già usciti**.

### 1.5 Un prodotto da un centesimo al 25% fa fallire l'intero carrello

`app/Models/Listing.php:499-506`: `unit_ky = round(1 * 25/100) = 0`. Con
`totaleKy = 0`, `TransferBookingService::assertTransferPayload` (riga 621)
lancia. In un carrello multi-venditore l'eccezione risale da
`CartService.php:224` e **butta giù tutto il checkout** per una riga da un
centesimo, con un messaggio che nomina l'azienda ma non spiega niente.

---

## Blocco 2 — Sicurezza

### 2.1 `not.suspended` manca sul gruppo del portale

`routes/web.php:415`. Il gruppo che contiene shop, carrello e cassa monta
`auth, verified, twofactor, onboarding, agent.contract, contract` — **senza
`not.suspended`**. L'alias esiste (`bootstrap/app.php:28`) ma è usato solo sul
gruppo OAuth (riga 395). E `isSuspended()` non compare né in `OrderService`, né
in `CartService`, né in `TransferBookingService`.

Un'azienda sospesa dall'admin continua a comprare, vendere e incassare KY nello
shop. E anche dopo il suo logout basta che un utente non sospeso compri da lei.

> Nota: questo non è un problema *dello shop*, è di tutto il portale. Vale la
> pena decidere in modo esplicito se la sospensione deve fermare gli acquisti,
> e in caso metterlo dove sta il resto della catena.

### 2.2 Quantità accumulabile oltre il tetto, su rotte senza throttle

`CartController.php:73-76` valida `max:999999`, ma `CartService::aggiungi()`
(riga 88) somma `quantità esistente + nuova` **senza ricontrollare il tetto**
(il controllo scatta solo con scorta limitata). Le rotte carrello
(`web.php:535,537,538,541`) non hanno throttle. Su un prodotto a scorta
illimitata, qualche migliaio di POST porta `cart_items.quantity`
(`unsignedInteger`) all'overflow.

### 2.3 Immagini prodotto: il tetto di 6 vale solo per singolo salvataggio

`ListingController.php:861-862` valida `max:6`, ma `update()` (righe 491-493) fa
`array_merge($esistenti, $nuove)` senza ricontare. Sei immagini da 3 MB per ogni
salvataggio, all'infinito, sul disco pubblico.

### Controllato e a posto

IDOR carrello (`CartService::assertRigaDelConto`), pagina grazie (filtra su
`buyer_account_id`), `PaymentController::show` (`authorizeViewer`), rubrica
indirizzi (tetto 10 forzato con lock, `assertDelConto` su ogni scrittura,
`redirect_to` su whitelist), auto-acquisto bloccato su entrambe le strade,
prezzi mai dal client, varianti cancellate con snapshot + `nullOnDelete`,
nessun `{!!` nelle view dello shop, upload con mime/size/nome random.

---

## Blocco 3 — Il buco d'esperienza più grosso: **"Compra ora" è rimasto al 25 agosto**

`resources/views/portal/shop-show.blade.php:362,367` →
`app/Http/Controllers/ListingController.php:270-347`

La Fase A ha costruito una vera cassa **solo sulla strada del carrello**. La
strada diretta dalla scheda prodotto — quella che il codice stesso definisce
principale — è esattamente com'era prima:

| | carrello → cassa | "Compra ora" |
|---|---|---|
| conferma | pagina di cassa | `confirm()` grigio del browser |
| spunta condizioni/recesso | sì, obbligatoria | **no** |
| scelta indirizzo dalla rubrica | sì | **no**, solo il predefinito |
| nota al venditore | sì | **no** |
| dopo l'acquisto | pagina grazie col numero d'ordine | banner verde sulla scheda prodotto |

Il commento in `cart.blade.php:222-227` spiega perché il `confirm()` è stato
tolto: «non era brandizzato, non era accessibile e su mobile poteva essere
soppresso, trasformando un clic in un addebito senza conferma». Quel `confirm()`
è ancora lì, su metà del traffico d'acquisto — e con lui il buco sul consenso
alle condizioni.

In più, il `confirm()` sulla variante (riga 362) **non dice la cifra**, mentre
quello senza varianti (riga 367) sì: chi compra una taglia conferma un addebito
di cui non legge il totale.

**Questa è la cosa che consiglio di fare per prima dopo il blocco 1**, ed è
piccola: far puntare "Compra ora" alla cassa già esistente, con il prodotto
precaricato. Niente codice nuovo, solo un percorso in meno.

---

## Blocco 4 — Prestazioni

### 4.1 Immagini a piena risoluzione, nessun thumbnail

`ListingController.php:915-928` (`storeUploadedImages`) fa
`$file->store(...)` e basta: nessun ridimensionamento, con validazione fino a
**3 MB per file**. La griglia (`shop.blade.php:113,170`) mostra 15 prodotti + 4
in evidenza = **19 originali per pagina**. La scheda prodotto è peggio: la
strip di anteprime **72×72 px** (`shop-show.blade.php:61`) usa gli originali,
e né lei né l'immagine principale hanno `loading="lazy"`.

È il singolo problema di velocità più grosso, e da telefono si sente tutto.
**Correzione:** generare due derivate al salvataggio (thumb 300px, media 900px)
e servire quelle.

### 4.2 La query del catalogo non può usare gli indici

`ListingController.php:82-100`. Il `where(fn)` produce
`((status='active' AND (expires_at IS NULL OR expires_at>NOW())) OR company_id = X)`:
l'OR fra colonne diverse **annulla l'indice `(status, company_id)`**. In più
`ORDER BY featured DESC, created_at DESC` non ha indice → scansione completa +
filesort, **eseguita due volte** (SELECT + COUNT della paginazione).

`2026_05_26_110000_create_listings_table.php:29-31` ha solo `(status,company_id)`,
`(category,status)` e `featured` da solo (booleano, inutilizzabile). Mancano
`expires_at`, `ky_percentage`, `created_at`.

La ricerca (righe 94-96) usa `LIKE '%q%'` (wildcard iniziale = nessun indice) e
un `whereHas('company')` che diventa un `EXISTS` correlato **ripetuto anche nel
COUNT**.

### 4.3 Il resto, minore ma gratis da sistemare

- `saldoDisponibile()` costa 3 query e viene chiamato 2-4 volte per render
  della cassa (`CartController.php:181,345`) → **6-12 query dove ne bastano 3**.
  Memoizzare su `Account`, e mettere in cache `SystemSetting::userLimitDefaults()`.
- `Cart::perVenditore()` (`Cart.php:135-171`) rifà tutto il raggruppamento a
  ogni chiamata: 3 volte nel carrello, fino a 5 in cassa. Nessuna query in più,
  solo CPU sprecata. Memoizzare.
- `/shop/offerte` (`ListingController.php:200-206`) fa `->get()` **senza
  paginazione né limite**, e ordina in PHP dopo aver caricato tutto.
- `increment('views_count')` (riga 233) è un UPDATE sincrono su ogni pageview,
  dentro la request. Su un prodotto molto visto le richieste si serializzano
  sul lock di riga.
- Le categorie sono 3 query a ogni caricamento di `/shop`, dati quasi statici,
  mai cachati.
- `OrderService.php:128-133` blocca le varianti **una query per riga** dentro il
  foreach, mentre i listing sono già bloccati in blocco (righe 87-93).
  `whereIn(...)->lockForUpdate()->get()` una volta sola.

**Nessun N+1 trovato** in carrello, cassa, griglia catalogo e scheda prodotto:
gli eager load sono corretti e coprono tutto quello che le view toccano.
Indici a posto su `cart_items`, `order_items`, `listing_variants`,
`listing_offers`. Il badge del carrello usa `once()` + una sola query aggregata.

---

## Blocco 5 — Fluidità: gli attriti che si sentono a ogni acquisto

### Fastidiosi davvero

- **Aggiungere al carrello ricarica la pagina e ti sbatte in cima.**
  `CartController.php:96` fa `back()` con un flash renderizzato in testa
  (`layouts/portal.blade.php:2033`). Dalla scheda prodotto ricarica anche la
  galleria. Nessun drawer, nessun mini-carrello, nessuna chiamata AJAX.
- **Dal catalogo non si può aggiungere al carrello.** `shop.blade.php:144,215`
  ha un bottone che dice **"Acquista ora"** ma è solo un link alla scheda.
  L'utente lo preme aspettandosi di comprare e ottiene un'altra pagina.
- **Doppio banner sullo shop.** `shop.blade.php:5,8` ristampa la stessa
  `session()` che il layout ha già stampato: dopo ogni aggiunta si legge due
  volte lo stesso avviso.
- **Quantità: nessuno stepper −/+, e va premuto "Aggiorna".**
  `cart.blade.php:101-106`. È facilissimo cambiare il numero, andare in cassa e
  pagare la quantità vecchia. Il server accetta `min:0` per rimuovere
  (`CartController.php:105`) ma l'input ha `min="1"`: chi scrive 0 riceve il
  messaggio nativo del browser, in inglese sui browser non localizzati.
- **"Rimuovi" non chiede conferma, "Svuota il carrello" sì.** La protezione sta
  sull'azione sbagliata. E i due bottoni (`cart.blade.php:106,112`) sono alti
  ~20px, senza sfondo: col pollice si preme "Rimuovi" invece di "Aggiorna".
- **Gli errori compaiono solo in cima, uno alla volta.**
  `layouts/portal.blade.php:2035` mostra `$errors->first()` e basta. I campi
  indirizzo (`partials/shipping-address-fields.blade.php:14-31`) non hanno né
  `required`, né `@error`, né `autocomplete`: se manca il CAP l'utente legge
  «servono almeno nome, via, città e CAP» in cima a una pagina lunga e deve
  indovinare quale. Senza `autocomplete` il compilatore del telefono non parte.
- **Se il pagamento fallisce in cassa, si perde quello che si era scritto.**
  `CartController.php:254-255`: il `catch` del checkout fa `back()` **senza
  `withInput()`**, mentre il `catch` tredici righe sopra (241-243) ce l'ha.
  Nota al venditore e indirizzo nuovo spariti.
- **Il badge disponibilità non segue la variante scelta.**
  `shop-show.blade.php:236` mostra `stock_label` del prodotto padre mentre
  `$inStock` è calcolato sulle varianti (righe 135-137). Il JavaScript
  (600-640) aggiorna prezzo e avviso saldo, mai quel badge: si può leggere
  "Disponibile" su una taglia esaurita.
- **La barra dei filtri scompare fra 641 e ~900px.** `.shop-toolbar` è
  `nowrap` + `overflow-x:auto` e il media query raddrizza solo sotto i 640px:
  in mezzo, "Filtra" e le azioni a destra finiscono fuori schermo su una barra
  che non sembra scorrevole. E la categoria si auto-invia
  (`shop.blade.php:27`), sotto-categoria e filtro KY no.
- **L'indirizzo si salva in rubrica di default.** `checkout.blade.php:74`:
  `old('salva_indirizzo', '1')` è pre-spuntato. L'indirizzo di un regalo finisce
  in rubrica senza che l'utente l'abbia deciso.

### Cose che funzionano — da non toccare

I messaggi d'errore di `CartService` e `OrderService` sono in italiano piano e
concreto, con l'importo mancante calcolato: non c'è un solo messaggio tecnico.
Il bottone di cassa si disabilita davvero. Gli stati vuoti di carrello e
catalogo hanno testo e via d'uscita. Tutte le immagini prodotto hanno `alt`.
La scelta di lasciare in elenco le varianti esaurite (invece di nasconderle) è
quella giusta — è il contrasto di `.is-out` a essere troppo debole.

---

## Blocco 6 — Cosa manca ancora, oltre al piano

Già previsto e ancora da fare: **Fase B** (pagine ordini per compratore e
venditore, stati oltre `paid`, tracking, resi), **Fase C** (notifiche al
compratore — oggi `NewMarketplaceOrderNotification` è l'unica e va solo al
venditore — e ricevuta PDF), **Fase D** (recensioni, ordinamento catalogo),
**Fase E** (rifiniture).

Non previsto dal piano, emerso adesso:

- **La pagina "grazie" è irrecuperabile.** `CartController.php:301` la serve
  solo con `?ids=` in query string. Chiuso il tab, il numero d'ordine è perso
  e non esiste nessun'altra pagina che lo mostri. Finché non c'è la Fase B,
  varrebbe la pena almeno mandarlo per email.
- **"Più venduti" non è gratis.** Non esiste `orders_count` né alcun contatore
  vendite su `Listing` (`$fillable` ha solo `views_count`). L'ordinamento della
  Fase D richiede un'aggregazione su `order_items` o una colonna nuova.
- **Nessuna scadenza sulla quota in euro.** Nessun job per
  `MarketplaceOrderPayment`: un ordine mai saldato tiene le scorte scalate
  all'infinito e non viene mai chiuso.
- **Fatturazione e IVA: inesistenti.** `Company` ha `vat_number` ma non entra
  mai nell'ordine. La "ricevuta PDF" della Fase C non è una fattura, e per una
  vendita B2B nel circuito è un buco reale.
- **Nessun coupon o codice sconto a livello ordine** (esiste solo `ListingOffer`
  sul singolo prodotto).
- **Nessun supporto ai prodotti digitali:** nessun campo file o download; oggi
  si vende solo scrivendo il link a mano nella nota.
- **Spedizione: una tariffa sola, quella del prodotto più caro.**
  `OrderService.php:199-203` ordina le righe per `shipping_cost` decrescente e
  addebita **solo la prima**. È una scelta difendibile, ma non è documentata da
  nessuna parte e non è configurabile: nessuna soglia "spedizione gratis sopra X".
- **Prima di comprare mancano le tre cose che trattengono dal comprare:** tempi
  di consegna, politica di reso, recensioni. Su `shop-show.blade.php` non
  compare mai `reso`, `rimborso`, `garanzia` o `recesso`.
- **Il carrello non scade mai** (già nel piano come rifinitura, ma con le scorte
  che non si riservano è solo un problema di igiene, non di soldi).

---

## Se dovessi mettere in fila le prossime mosse

1. **Blocco 1 per intero** — il lock sul carrello (1.1), lo stato ordine
   (1.2), le scorte al rimborso (1.3). Sono tre correzioni piccole su cose che
   muovono soldi, e la 1.2 è un prerequisito della Fase B.
2. **"Compra ora" dentro la cassa** (blocco 3). Poco lavoro, chiude il buco sul
   consenso e rende coerenti le due strade.
3. **`not.suspended` sul portale** (2.1) — decisione da prendere, non solo
   codice.
4. **Thumbnail delle immagini + i tre indici** (4.1, 4.2). È qui che si sente
   la differenza di velocità.
5. **Fase B** con lo stato ordine già sistemato.
6. Gli attriti del blocco 5, a mazzetti: sono tanti, ognuno piccolo, e insieme
   fanno la differenza fra "funziona" e "fluido come Amazon".
