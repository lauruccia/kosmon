# Piano — separazione dello shop da kmoney-app

Stato: **proposta**, nessuna riga di codice ancora scritta.
Data: 23/08/2026 (rev. 2)

## Decisioni prese

1. **Dominio:** `kosmoshop.it` — dominio separato, non un sottodominio di
   kmoney.it. Conseguenza tecnica: niente cookie condivisi, l'SSO passa
   obbligatoriamente da OAuth con redirect (§4). È comunque la strada che
   avevamo scelto, quindi non cambia nulla nel piano.
2. **Repo:** nuovo repo dedicato per `kshop`, separato da kmoney-app.
3. **Chi può vendere:** solo chi è già registrato su KMoney. Nessun venditore
   esterno al circuito → l'identità è **sempre** quella della banca, e questo
   conferma l'SSO come unica scelta sensata.
4. **Carrello:** sì, previsto in fase 3. Questa decisione ha una conseguenza
   pesante sul pagamento in euro — vedi §2.
5. **Mandato:** solo limite **per transazione**, nessun plafond di periodo (§5).
6. **Un venditore per ordine.** Se il cliente aggiunge al carrello un prodotto
   di un'altra azienda, glielo si fa notare e gli si propone una via d'uscita
   che non butti via nulla — vedi §6.3.
7. **Resi: rimborso manuale del venditore**, sia KY che euro (§7).
8. **Rate limit del mandato: 10 addebiti in un'ora**, poi sospensione automatica
   e notifica (§5).
9. **Carrelli non completati: scadono dopo 30 giorni** (§6.3).
10. **Apertura di kshop a tutte le aziende insieme**, nessun gruppo pilota (§9).
11. **Cashback e commissioni non stornati sui rimborsi: si lascia com'è** per
    ora — comportamento già esistente, da rivedere separatamente (§7).

Obiettivo: kmoney-app resta **solo la banca del circuito**. Catalogo, vendita e
acquisto escono in una **nuova app Laravel separata** (di seguito `kshop`), che
paga in KY tramite API, con login unico e "un clic e paghi" via **mandato**.

---

## 1. Chi fa cosa dopo la separazione

| Resta in kmoney-app (la banca) | Passa a kshop (il negozio) |
|---|---|
| Identità: `User`, `Company`, KYC, contratto, 2FA, passkey | Catalogo prodotti, varianti, immagini |
| Conti, saldi, fido, massimale | Carrello, checkout, spedizioni |
| `Transfer`, `LedgerEntry`, `AuditLog` | Ordini, stati ordine, tracking |
| `TransferBookingService` (**unico** posto che muove KY) | Recensioni, ricerca, filtri, vetrine |
| Cashback, commissioni, MLM | Pagine venditore, gestione magazzino |
| Credenziali gateway del venditore (`PaymentGateway`) | Incasso della **quota EUR** (Stripe/PayPal/bonifico) |
| Notifiche sul **denaro** ("hai pagato 50 KY a X") | Notifiche sull'**ordine** ("nuovo ordine", "spedito") |

**Regola da non violare:** kshop non scrive mai su saldi o `transfers`. Chiede, la
banca decide. Tutti i lock pessimistici e la partita doppia restano dove sono.

---

## 2. Cosa esce fisicamente

Dal codebase attuale:

- **Modelli:** `Listing`, `ListingCategory`, `ListingOffer`
- **Controller:** `ListingController` (portale + admin), `AdminListingCategoryController`, `AdminListingOfferController`
- **Rotte:** 12 rotte catalogo `/shop/*` in `routes/web.php` (righe 461-484) e 14 rotte admin listings/categorie/offerte (righe 905-922)
- **Viste:** tutta l'area shop portale + admin listings/categorie/offerte

### E la quota in euro? (correzione alla rev. 1)

Nella prima stesura avevo lasciato in kmoney-app anche `PaymentController`,
`MarketplaceOrderPayment` e le 7 rotte `/shop/ordini/*`. **Era sbagliato**, e la
decisione sul carrello lo rende evidente.

La tabella `marketplace_order_payments` ha `transfer_id` **`unique()`**
(migration `2026_07_24_130100`): esattamente un pagamento in euro per ogni
movimento KY, cioè per ogni singolo prodotto acquistato. Con un carrello, un
ordine ha più prodotti e magari più venditori: quel vincolo non regge più.
E soprattutto: **gli euro non entrano mai nel circuito.** Non toccano i saldi KY,
non generano partita doppia, non passano da `TransferBookingService`. Sono un
incasso diretto tra acquirente e venditore. È logica di negozio, non di banca.

Quindi la quota EUR **si sposta in kshop**, ridisegnata attorno all'ordine e non
al singolo prodotto:

| Componente | Dove va | Perché |
|---|---|---|
| `PaymentController` + rotte `/shop/ordini/*` | **kshop** | è checkout, non movimento di circuito |
| `MarketplaceOrderPayment` | **kshop**, come `order_payments` legata all'ordine (non al transfer) | il vincolo 1-a-1 col transfer non sopravvive al carrello |
| Credenziali `PaymentGateway` del venditore | **kmoney-app**, esposte via API | stanno con l'anagrafica azienda, e sono dati sensibili: restano dove c'è già la cifratura |
| `NewMarketplaceOrderNotification` | **kshop** | avvisa il venditore che ha un ordine da preparare: è un fatto commerciale |

Resta in kmoney-app solo il kind `portal_marketplace_order` in
`TransferBookingService` — quello sì, è il movimento KY vero e proprio.

**Divisione delle notifiche:** KMoney notifica il denaro ("50 KY pagati a
Rossi Srl"), kshop notifica l'ordine ("nuovo ordine", "spedito", "consegnato").
Due canali distinti perché sono due fatti distinti: l'addebito è avvenuto anche
se poi l'ordine viene annullato.

---

## 3. I tre punti di attacco reali (trovati nel codice)

Non sono ostacoli teorici: sono i tre fili da tagliare con cura.

### 3.1 `Transfer.listing_id` — la FK verso il catalogo

`TransferBookingService` salva `listing_id` sul movimento (righe 664 e 776), e le
pagine movimenti/ordini admin ci fanno join. Se `listings` esce, quella FK punta
nel vuoto e lo **storico ordini diventa illeggibile**.

**Soluzione:** sostituire la FK con uno *snapshot*. Nuove colonne su `transfers`:

```
external_order_uuid   -- id ordine in kshop
order_title           -- "Scarpe modello X — taglia 42, nero"
order_source          -- 'internal_shop' | 'kshop'
```

`listing_id` resta, nullable, solo per lo storico già esistente. Così una
ricevuta o un movimento del 2026 restano leggibili anche fra tre anni, senza
interrogare kshop.

**FATTA il 24/08** — migrazione
`2026_08_24_140000_add_order_snapshot_to_transfers_table`. Due scostamenti dalla
stesura qui sopra, entrambi voluti:

- **niente `order_quantity`:** su `transfers` esiste già `quantity` con
  esattamente quel significato (migrazione del 23/07). Aggiungerne una seconda
  avrebbe solo creato due fonti di verità sulla stessa cosa.
- **la FK viene sganciata subito**, non in fase 5. La colonna `listing_id` e il
  suo indice restano — gli ordini interni continuano a linkare al prodotto — ma
  cade il vincolo, così il giorno in cui `listings` sparirà non si dovrà toccare
  `transfers`, che è la tabella più delicata del sistema.

Lo storico già in tabella è stato riempito dal backfill dentro la migrazione. Il
titolo mostrato ovunque è ora `Transfer::order_label`: vince sempre lo snapshot,
il join su `listings` resta solo come rete di sicurezza per i movimenti
anteriori alla migrazione, e sparirà con lo shop interno.

### 3.2 `Account::syncListingsKyPercentage()` — la banca che scrive nel catalogo

`Account::booted()` (riga 166) intercetta ogni cambio di `available_balance` e
fa una `UPDATE` diretta sulla tabella `listings`: forza il mix a 100% KY quando
l'azienda va in debito, e ripristina `desired_ky_percentage` quando ne esce.

È l'accoppiamento più stretto di tutti, e sopravvive alla separazione solo se
diventa un **webhook**: `company.trading_status_changed` → kshop aggiorna i suoi
prodotti. Il calcolo (`isInDebit()`, `allowedKyPercentages()`) resta in banca;
kshop esegue soltanto.

### 3.3 Il controllo "il venditore può incassare?"

`ListingController::buy()` oggi verifica in-process: prodotto attivo, non è la
tua stessa azienda, il venditore ha un gateway EUR configurato, l'acquirente ha
un indirizzo di spedizione. Dopo la separazione questi controlli si dividono:
quelli **commerciali** (stock, prezzo, indirizzo) restano in kshop, quelli
**bancari** (azienda sospesa, mix KY ammesso, gateway EUR attivo) diventano una
chiamata `GET /api/v1/sellers/{account_number}/status`.

---

## 4. Identità: "Accedi con KMoney"

kmoney-app diventa **identity provider OAuth2** (Laravel Passport —
oggi non c'è né Passport né Sanctum, l'auth API è il custom `ApiToken`, che resta
per gli altri usi).

- Flusso `authorization_code` + PKCE.
- La schermata di consenso è dietro i middleware già esistenti: `verified`,
  `twofactor`, `not.suspended`, `contract`. Un'azienda sospesa o senza contratto
  firmato **non riesce nemmeno a entrare** in kshop: zero logica duplicata.
- Il token porta: uuid utente, uuid azienda, numero di conto, ruolo
  (compratore / venditore), stato commerciale.
- Scopes: `profile`, `account.read`, `orders.write`, `mandate`.

Niente registrazione su kshop, niente seconda password, nessuna anagrafica da
tenere sincronizzata.

---

## 5. Il mandato di pagamento ("un clic e paghi")

Nuova entità `PaymentMandate` in kmoney-app.

```
uuid
user_id, account_id
client_id                 -- l'app a cui è concesso (kshop)
max_per_transaction       -- UNICO limite: tetto per singolo acquisto, es. 5000 = 50,00 KY
authorized_sellers        -- aziende già approvate (cresce solo con conferma esplicita)
expires_at                -- scadenza di sicurezza, es. 12 mesi
suspended_at              -- sospensione automatica da rate limit (antifurto)
charges_count, last_used_at
revoked_at, last_used_at
created_ip
```

### Il tetto NON è un abbonamento

Chiarimento, perché la parola "mensile" della rev. 1 traeva in inganno: qui **non
si paga nulla a rate e non c'è nessun addebito ricorrente**. Si comprano
prodotti, si paga il prodotto, punto.

**Decisione presa: solo limite per transazione.** Nessun plafond di periodo,
nessun contatore. Il mandato dice una cosa sola: *"da questo negozio non può
uscire più di N KY in un colpo solo"*.

È la scelta più semplice da capire per l'utente, e nel caso di KMoney regge
meglio che altrove per un motivo strutturale: **i KY non escono dal circuito.**
A differenza di una carta, un addebito fraudolento finisce comunque sul conto di
un'azienda registrata, con KYC e contratto firmato — quindi è tracciabile,
reversibile e ha un responsabile con nome e cognome. Il denaro non sparisce.

Resta però un buco onesto da nominare: senza un totale, il tetto reale diventa
il **saldo disponibile più il fido**. Un mandato da 50 KY per acquisto non
impedisce, in teoria, 200 addebiti da 49 KY. Due protezioni a costo quasi zero
lo chiudono senza reintrodurre nessun plafond:

1. **Venditore nuovo = conferma esplicita** (già nel piano). Un addebito può
   andare solo ad aziende da cui hai già comprato confermando: la lista non
   cresce da sola.
2. **Rate limit sul mandato**, tecnico e invisibile: oltre **10 addebiti in
   un'ora** il mandato si sospende da solo (`suspended_at`) e parte una notifica
   *"Attività insolita su Kosmoshop: ho sospeso gli addebiti automatici"*. Non è
   un limite di spesa che l'utente deve capire o configurare — è un antifurto.
   Dieci acquisti in un'ora da un solo negozio non è un comportamento umano
   normale; l'utente può riattivare il mandato con step-up, oppure semplicemente
   continuare a comprare confermando ogni acquisto.

Più la scadenza del mandato (12 mesi) e la notifica push a ogni addebito con
revoca in un clic.

### Come si concede

Al **primo acquisto**, una schermata KMoney: *"Consenti a Kosmoshop di
addebitare fino a 50 KY per acquisto. Ogni addebito ti arriva come notifica e
puoi revocare quando vuoi."* Confermata con **step-up** — riusa il middleware
`step.up` che già protegge le azioni sensibili (2FA o passkey). Non è un
consenso nascosto in un checkbox.

### Come si usa

```
POST /api/v1/mandates/{uuid}/charge
  seller_account_number, amount, external_order_uuid,
  order_title, quantity, idempotency_key
```

Tre esiti possibili:

| Condizione | Risposta |
|---|---|
| Sotto il tetto, venditore già autorizzato, mandato valido | **200** — addebito immediato, nessun redirect. È il "un clic e paghi". |
| Sopra il tetto, venditore mai usato prima, mandato scaduto o sospeso | **402** + `payment_request_uuid` e URL di conferma → l'utente conferma in KMoney (e in quell'occasione il venditore entra fra quelli autorizzati), poi webhook `payment_request.paid` |
| Saldo insufficiente | **402** + link ricarica con ritorno automatico (meccanismo già esistente) |

Dietro, la charge chiama `TransferBookingService::book()` con kind
`portal_marketplace_order` e `idempotency_key` — quindi cashback, commissioni,
MLM e partita doppia continuano a funzionare **identici a oggi**, senza toccare
il motore finanziario.

### Garanzie di sicurezza

- Il mandato non dà mai accesso al saldo, né la facoltà di pagare beneficiari
  fuori dal circuito.
- Ogni charge → `AuditLog` + notifica push all'utente.
- Il secret vive **solo sul server** di kshop, mai nel browser.
- Pagina "App collegate" nel portale: tetto per acquisto, venditori autorizzati,
  storico addebiti, ultimo utilizzo, **revoca immediata** con un bottone.
- I KY non lasciano il circuito: anche nello scenario peggiore il denaro finisce
  su un conto tracciabile e recuperabile, non sparisce.

---

## 6. Le funzionalità nuove

### 6.1 Prodotti variabili

Il modello attuale è piatto: `Listing` ha un solo `price_ky`, un solo
`stock_quantity`, un array `images`. In kshop diventa:

```
products              id, titolo, descrizione, categoria, ky_percentage, delivery_type, ...
product_attributes    id, product_id, nome ("Taglia"), ordine
attribute_values      id, attribute_id, valore ("42")
product_variants      id, product_id, sku, price_ky, stock_quantity, image_id, attiva
variant_values        variant_id, attribute_value_id
```

Scelta consigliata: **il mix KY/EUR resta a livello di prodotto**, non di
variante. Altrimenti la regola "azienda in debito → 100% KY" andrebbe applicata
riga per riga su migliaia di varianti, e il badge in vetrina diventerebbe
ambiguo. Prezzo e magazzino invece sono per variante.

### 6.2 Immagini sempre adattate allo spazio

Serve `intervention/image` v3 (oggi non c'è nessuna libreria immagini nel
progetto). All'upload, un job in coda genera tre formati in **WebP**:

| Formato | Uso |
|---|---|
| `thumb` 400×400 | griglia catalogo |
| `card` 800×800 | pagina prodotto |
| `full` 1600×1600 | zoom |

Tutti su **canvas quadrato**: l'immagine viene ridimensionata *dentro* il
riquadro mantenendo le proporzioni (mai deformata, mai tagliata male), e lo
spazio residuo viene riempito col colore dominante estratto dalla foto stessa.
Risultato: una foto verticale e una orizzontale occupano lo stesso spazio nella
griglia, senza buchi bianchi né ritagli che decapitano il prodotto.

Un fallback `object-fit: contain` lato CSS copre le immagini vecchie non ancora
riprocessate.

### 6.3 Carrello: un venditore per ordine

**Decisione presa:** un ordine = un venditore. È la scelta giusta, perché ogni
ordine ha una spedizione sola, un solo interlocutore per i resi, e soprattutto
**un solo movimento KY** — mentre un carrello misto ne genererebbe uno per
venditore, e un addebito potrebbe riuscire e l'altro fallire per saldo
insufficiente, lasciando l'ordine a metà.

Il punto delicato è cosa succede quando il cliente aggiunge al carrello un
prodotto di un'altra azienda. La soluzione da **non** adottare è il classico
"il carrello verrà svuotato, confermi?": mette l'utente davanti a una scelta
distruttiva e gli fa perdere quello che aveva già scelto.

**Proposta: carrelli paralleli, uno per venditore.** Nulla viene mai buttato via.

- Il cliente aggiunge un prodotto di *Rossi Srl* avendo già in carrello roba di
  *Bianchi Snc*. Non si blocca niente: il prodotto entra in un **secondo
  carrello**, e compare un avviso chiaro:
  > *"Gli ordini si completano un venditore alla volta, perché ogni venditore
  > spedisce per conto suo. Ho messo questo prodotto in un carrello separato per
  > Rossi Srl — lo trovi quando hai finito con Bianchi Snc."*
- L'icona carrello mostra i carrelli aperti con il totale di ciascuno, e si
  passa dall'uno all'altro con un clic.
- Al checkout si paga un carrello per volta. Terminato il primo, kshop propone
  subito: *"Vuoi completare anche l'ordine da Rossi Srl?"*
- I carrelli non completati restano lì (salvati sull'utente, non nella sessione)
  e scadono dopo **30 giorni**. Alla scadenza non spariscono in silenzio: il
  prezzo e la disponibilità vanno comunque **rivalidati al checkout**, perché in
  un mese un prodotto può essere esaurito, rincarato o finito in offerta.

Costo di sviluppo minimo rispetto al carrello singolo — è una chiave in più
sulla tabella `carts` (`seller_company_id`) — e l'utente non perde mai niente.

---

## 7. Resi e rimborsi

Il reso è il punto in cui la separazione si sente di più, perché un singolo
ordine ha pagato con **due strumenti diversi**: i KY sono passati dal circuito,
gli euro no. Restituire tutto significa toccare due sistemi.

**Decisione presa: il rimborso lo fa il venditore, a mano, per entrambe le
quote.** È una scelta ragionevole — il venditore è l'unico che sa se la merce è
tornata, in che stato, e se il reso va accettato per intero o in parte. Nessun
automatismo può decidere al posto suo.

Concretamente, cosa fa il venditore:

| Quota | Come rimborsa | Strumento |
|---|---|---|
| **KY** | Dal portale KMoney, sul movimento dell'ordine | `TransferBookingService::refundMerchant()`, che **esiste già** e gestisce anche i rimborsi parziali (§ verificato: accetta `portal_marketplace_order` fra i tipi rimborsabili) |
| **Euro** | Dal suo pannello Stripe/PayPal, o con un bonifico | fuori da entrambi i sistemi |

### Il rischio del "tutto manuale", e come coprirlo a costo zero

Se il reso è *solo* manuale, nessuno dei due sistemi sa che è successo: kshop
mostra l'ordine come "consegnato" per sempre, il cliente non ha una prova che il
rimborso sia stato fatto, e se il venditore se ne dimentica non se ne accorge
nessuno.

Non serve automatizzare il rimborso per risolverlo — basta **tracciare la
richiesta**:

1. Il cliente apre una **richiesta di reso** da kshop (motivo, foto, quantità).
2. Il venditore la accetta o rifiuta in kshop.
3. Se accetta, kshop gli mostra due bottoni: *"Rimborsa i KY"* (link diretto
   alla pagina di rimborso KMoney, con importo già precompilato) e *"Ho
   rimborsato gli euro"* (spunta manuale, con campo per il riferimento).
4. Quando il rimborso KY viene contabilizzato, un webhook `transfer.refunded`
   torna a kshop e chiude la pratica da solo.

Il venditore resta padrone di ogni decisione, ma resta una traccia da entrambe
le parti e il cliente vede a che punto è.

### Un buco che esiste già oggi

Verificando `refundMerchant()` ho trovato una cosa che **non riguarda la
separazione ma è bene sapere**: il rimborso restituisce i KY, ma **non storna né
il cashback né le commissioni** generate dall'acquisto originale. Il cashback
erogato all'acquirente e la fee incassata restano dove sono anche se l'ordine
viene annullato per intero.

È un comportamento preesistente, non introdotto da questo piano, e sui numeri di
oggi vale pochissimo. **Decisione: si lascia com'è per ora.** Va però tenuto
d'occhio quando i resi diventeranno un flusso vero e non un caso raro — a quel
punto ogni reso regala un cashback non dovuto.

---

## 8. Migrazione dei dati

Hai scelto: **migrare tutto e spegnere lo shop interno.**

1. Comando `php artisan kshop:export` in kmoney-app → JSON di `listings`,
   `listing_categories`, `listing_offers` + copia dei file da
   `storage/app/public/listings/`.
2. Comando `php artisan kshop:import` in kshop: ogni `Listing` diventa un
   `product` con **una variante di default** (così i prodotti esistenti
   funzionano subito, e le varianti si aggiungono dopo, quando serve).
3. Reprocessing di tutte le immagini nei tre formati.
4. Le rotte `/shop/*` di kmoney-app restano registrate ma diventano **redirect
   301** verso kshop, così i link già in giro (email, notifiche, QR) non si
   rompono.
5. Lo storico ordini resta **dove è**, in KMoney: i movimenti non si migrano mai.

---

## 9. Roadmap a fasi

Ogni fase è rilasciabile da sola: in nessun momento il sito resta rotto.

| Fase | Cosa | Dove | Peso |
|---|---|---|---|
| **0a** | ~~Test di regressione sull'acquisto attuale~~ — **FATTA** (23/08): `tests/Feature/ShopPurchaseRegressionTest.php`, 19 test | kmoney-app | piccola |
| **0b** | ~~Snapshot ordini sui `transfers`, allentare la FK `listing_id`~~ — **FATTA** (24/08): migrazione + backfill + `Transfer::order_label`, 10 test | kmoney-app | piccola |
| **1** | ~~SSO "Accedi con KMoney" + pagina consenso~~ — **FATTA** (24/08): server OAuth2 **fatto in casa** invece di Passport (vedi `FASE1_MOTORE_OAUTH.md`), 2 tabelle, `users.uuid`, `/userinfo`, 58 test | kmoney-app | media |
| **2a** | ~~`PaymentMandate`, addebito immediato, pagina "App collegate", antifurto~~ — **FATTA** (25/08): 2 tabelle, tetto 50 KY di default, revoca in un clic, 54 test | kmoney-app | media |
| **2b** | Ramo "serve conferma" (402 → `payment_request` + webhook `payment_request.paid`) e webhook `company.trading_status_changed` | kmoney-app | media |
| **3** | Nuova app kshop: catalogo, varianti, immagini, **carrelli per venditore**, checkout, quota EUR, resi | kshop | **grande** |
| **4** | Export/import dati + doppio binario (shop interno in sola lettura) | entrambe | media |
| **5** | Spegnimento shop interno, redirect 301, rimozione codice | kmoney-app | piccola |

Le fasi 0-2 sono lavoro sulla banca e si possono fare **subito**, in produzione,
senza che nessuno se ne accorga: sono additive. La fase 3 è il grosso.

**Apertura a tutte le aziende insieme** (decisione presa), senza gruppo pilota.
Funziona, ma a una condizione: la **fase 4 non va saltata né accorciata**. È lì
che sta la rete di sicurezza — con lo shop interno ancora in piedi in sola
lettura, se kshop dà problemi il giorno dell'apertura nessuno resta senza
catalogo, e si può rimandare lo spegnimento di una settimana senza fretta.
Senza gruppo pilota, il doppio binario diventa l'unica protezione che hai.

---

## 10. Rischi da tenere d'occhio

- **Doppia verità sull'azienda.** Risolto dall'SSO (l'anagrafica è una sola) +
  verifica del venditore a ogni charge. Da non aggirare mai con una copia locale
  "per velocità".
- **La percentuale KY forzata in debito** (§3.2): finché il webhook non è
  pronto, non spegnere lo shop interno, o le aziende in debito venderebbero al
  mix sbagliato.
- **Idempotenza fra due app.** Ogni ordine kshop deve portare *sempre* lo stesso
  `idempotency_key`: un retry di rete non deve mai generare due addebiti.
- **Sessione scaduta a metà checkout.** Il carrello vive in kshop, il pagamento
  in KMoney: prevedere il ritorno al carrello intatto dopo un re-login.
- ~~**Nessuna rete di sicurezza sui test.**~~ **Risolto il 23/08** (fase 0a).
  `ListingController::buy()` — il punto in cui lo shop muove davvero i KY — non
  aveva alcun test: gli unici file shop (`ListingAnnouncementControllerTest`,
  `CompanyListingsOperatorRoleTest`) coprivano permessi e annunci, non
  l'acquisto. Ora c'è `tests/Feature/ShopPurchaseRegressionTest.php` con 19 test
  che fissano il comportamento attuale (saldi, mix KY/EUR, quantità e
  spedizione, offerte, stock, blocchi commerciali, audit). È il metro contro cui
  va confrontato kshop.

---

### Nota alla fase 1: perché non Passport (24/08)

Il piano diceva "Laravel Passport". La scelta è stata cambiata dopo aver
guardato **come si deploya davvero** questa produzione: `vendor/` non è nel
repo e `.cpanel.yml` non esegue mai `composer install`, quindi ogni libreria
nuova va caricata a mano su due server, e su kmoney.it non c'è né SSH né
Terminale. Per Passport servivano **9 pacchetti nuovi**, 5 tabelle e le chiavi
RSA; e siccome lì il checkout git *è* la cartella viva, un deploy fuori ordine
avrebbe buttato giù **il sito intero**, non solo lo shop.

Il motore scritto in casa fa una cosa sola — `authorization_code` con PKCE,
più il rinnovo — e tiene il deploy com'è oggi: solo codice. La scelta resta
**reversibile**: gli endpoint hanno i nomi e le risposte dello standard, quindi
kshop userà una libreria client normale e non saprà mai cosa c'è dietro.
Il confronto completo, con i numeri, è in `FASE1_MOTORE_OAUTH.md`.

Cosa esiste ora in kmoney-app, pronto per kshop:

| | |
|---|---|
| `GET /oauth/authorize` | schermata di consenso, dietro tutta la catena del portale |
| `POST /api/oauth/token` | scambio codice→token e rinnovo (PKCE S256 obbligatorio) |
| `POST /api/oauth/token/revoke` | revoca: spegne l'intera catena di token |
| `GET /api/v1/userinfo` | chi è l'utente: uuid, azienda, numero di conto, stato commerciale. **Mai il saldo.** |

Scope: `profile`, `account.read`, `orders.write`, `mandate`.
Configurazione: `config/oauth.php` + quattro variabili nel `.env` di ciascun
server. Finché `OAUTH_KSHOP_CLIENT_ID` è vuoto, non esiste nessun client e non
può collegarsi nessuno — ed è anche l'interruttore per spegnere tutto.

---

## 11. Stato del piano

**Tutte le decisioni di progetto sono chiuse** (le 11 in cima al documento).
Non restano domande bloccanti.

**Le fasi 0 e 1 sono chiuse.** 0a (test di regressione sull'acquisto, 23/08) e 0b
(snapshot ordini sui movimenti, 24/08) sono fatte: la banca ha ora sia la rete
di sicurezza per accorgersi se qualcosa si rompe, sia uno storico ordini che
sopravvive alla sparizione del catalogo. Nessuna delle due ha cambiato il
comportamento visibile del sito.

Anche la **fase 1** è fatta (24/08) ed è in produzione su entrambi i server:
l'identità c'è, e con essa il client a cui concedere il mandato.

**La fase 2 è stata spezzata in due**, e la 2a è fatta (25/08): il mandato
esiste, l'addebito immediato funziona, l'utente ha una pagina da cui vedere e
revocare, e l'antifurto è al suo posto. Il motore finanziario non è stato
toccato: l'addebito passa da `TransferBookingService::book()` come ogni altro
movimento, quindi cashback, commissioni, MLM e partita doppia restano identici.

Resta la **fase 2b**: il ramo "serve conferma". Oggi quando l'addebito
automatico non si può fare — sopra il tetto, venditore nuovo, mandato sospeso —
l'API risponde `402` con il motivo, ma non c'è ancora la pagina su cui mandare
l'utente a confermare. È lì che il venditore nuovo entra fra quelli autorizzati,
ed è quello che serve prima di poter aprire kshop davvero. Nella stessa fase va
il webhook `company.trading_status_changed` (§3.2), senza il quale non si può
spegnere lo shop interno.

Da qui in poi non si decide più architettura, ma quando partire.
