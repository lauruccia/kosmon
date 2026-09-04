# Analisi shop KMoney — verso un e-commerce completo e un tema premium

**Data:** 02/09/2026 · **Ambito:** shop interno del portale (`/shop`, `/shop/carrello`, `/ordini`, `/vendite`)
**Metodo:** lettura del codice vivo (modelli, migrazioni, controller, servizi, viste, rotte) e dei sei documenti di progetto già in repo. Nessuna riga di codice toccata.

---

## 0. La cosa da sapere prima di tutte le altre

**Lo shop non è da costruire: è già costruito, e il motore è buono.** Il percorso completo esiste e ha i test: catalogo → scheda prodotto → carrello multi-venditore → cassa in tre passi → pagina "grazie" → ordini del compratore → vendite del venditore → cambio stato con tracking → annullamento → reso in due mani. Sotto ci sono partita doppia, lock sui conti, snapshot integrale su ogni riga d'ordine, mix KY/EUR per prodotto e quota euro fuori circuito con Stripe/PayPal/bonifico. Tredici file di test dedicati allo shop dentro una suite che sfiora i 1.700 test.

Ho anche riverificato di persona le cinque falle sui soldi denunciate dall'`AUDIT_ECOMMERCE_2026-08-26.md`: **sono tutte e cinque chiuse.** Il carrello ora si rilegge con `lockForUpdate()` e si ferma se non è più `active` (niente doppio addebito), `chiudiLaQuotaInEuro()` porta davvero l'ordine a `paid` quando entrano gli euro, `rimettiInMagazzino()` restituisce le scorte sui resi con la guardia `stock_restored_at` contro il doppio rientro, e la quota euro sotto soglia è bloccata a monte da `config('kmoney.shop.min_euro_quota')`. Il codice è andato avanti rispetto ai documenti, che si fermano al 26 agosto.

Quindi la domanda vera non è «come faccio uno shop», è: **quali pezzi di WooCommerce mancano davvero, e perché oggi non si riesce a metterci sopra un tema premium.** Rispondo a tutte e due.

---

## 1. Cosa c'è già (per non rifarlo)

**Catalogo.** Filtri per categoria, sotto-categoria, testo, azienda e percentuale KY. Fascia "in evidenza" a scorrimento. Paginazione a 15. Categorie ad albero gestite dall'admin (17 radici seminate). Indici di catalogo aggiunti il 27/08.

**Prodotto.** Fino a 6 immagini con tre misure generate a mano in GD (originale, `medium/` 1400px, `card/` 600px — scelta obbligata dal deploy cPanel senza SSH), lightbox con frecce e contatore, varianti con selettore a pillole e prezzo che si aggiorna in pagina, offerte a tempo (`listing_offers`), scorte per combinazione, tipo di consegna, costo di spedizione.

**Carrello.** Sul conto e non in sessione, quindi sopravvive al cambio di dispositivo. Multi-venditore, si spacca alla cassa in un ordine per venditore. Prezzo mai congelato: si rilegge alla cassa. Mini-carrello in basso a destra alimentato in `fetch()` che funziona **anche a JavaScript spento**.

**Cassa.** Tre passi, rubrica indirizzi fino a 10 tutti selezionabili, nota al venditore, spunta obbligatoria sulle condizioni, un solo bottone che dice la cifra e si spegne al primo clic.

**Ordini.** Ciclo `pending_payment → paid → preparing → shipped → delivered` più `cancelled` e `refunded`. Il venditore può solo andare avanti, l'admin si muove libero dentro gli stati di consegna, ogni passaggio finisce in `AuditLog` con il nome di chi l'ha fatto. Corriere e codice di tracciamento a mano. Reso chiesto dal compratore entro 14 giorni e accettato dal venditore. Sollecito automatico sulla quota euro non saldata.

**Fuori dal portale.** Server OAuth2 scritto in casa ("Accedi con KMoney"), mandato di pagamento un-clic con tetto per transazione e antifurto a 10 addebiti/ora, plugin WooCommerce v2.2.0 già consegnato per i negozi esterni.

Tutta questa roba **non va toccata**. Quello che segue si appoggia sopra.

---

## 2. Confronto onesto con WooCommerce

| Funzione | KMoney oggi | Note |
|---|---|---|
| Catalogo, categorie, ricerca | ✅ | ricerca con `LIKE %q%`, nessun indice full-text |
| Galleria immagini | ✅ | max 6, tre misure, lightbox |
| Varianti (taglia/colore) | ✅ | attributi definiti dall'admin, delta di prezzo intero |
| Magazzino | ✅ parziale | scorte per prodotto e per combinazione; **niente soglia scorta bassa, niente backorder** |
| Carrello e cassa | ✅ | multi-venditore, rubrica indirizzi |
| Ordini, stati, tracking | ✅ | corriere e codice inseriti a mano |
| Annullamenti e resi | ✅ | reso chiesto dal compratore, 14 giorni |
| Offerte a tempo sul prodotto | ✅ | `listing_offers` |
| Pagamento misto KY/EUR | ✅ | non esiste in WooCommerce: è nostro |
| **Ordinamento del catalogo** | ❌ | c'è solo `featured DESC, created_at DESC` fisso: **manca prezzo crescente/decrescente, più venduti, novità** |
| **Recensioni e stelle** | ❌ | nessuna tabella. Decisioni di prodotto già prese il 26/08 |
| **Coupon / codici sconto** | ❌ | esiste solo l'offerta sul singolo prodotto |
| **Wishlist / preferiti** | ❌ | |
| **Filtri per attributo e fascia di prezzo** | ❌ | gli attributi servono solo a generare le varianti, non filtrano |
| **Briciole di pane (breadcrumb)** | ❌ | |
| **Spedizione configurabile** | ❌ | oggi: costo fisso per prodotto, e per ordine si paga **il più caro del gruppo**. Niente zone, niente peso, **niente soglia "spedizione gratis sopra X"** |
| **Prodotti digitali con download** | ❌ | oggi si manda il link a mano nella nota |
| **Prodotti raggruppati / bundle** | ❌ | |
| **Cross-sell, upsell, visti di recente** | ❌ | i "correlati" esistono ma sono un elenco testuale |
| **Import prodotti da CSV** | ❌ | ogni prodotto si inserisce a mano |
| **Slug e SEO** | ❌ | URL `/shop/{id}`, nessun meta, nessun Open Graph |
| **Vetrina pubblica (senza login)** | ❌ | tutte le rotte `/shop/*` stanno dietro `auth`. Il catalogo non è né condivisibile né indicizzabile |
| **IVA e fattura** | ❌ | `companies.vat_number` esiste ma non entra mai nell'ordine |

**Tre di queste hanno un nodo che WooCommerce non ha e che va sciolto prima di scrivere codice.**

1. **I coupon e il mix KY/EUR.** Uno sconto del 10% su un prodotto 75% KY: sconta i KY, gli euro, o tutti e due in proporzione? Se sconta solo i KY, il venditore incassa gli stessi euro e ci rimette solo circuito — ed è probabilmente quello che vuoi, ma è una scelta di modello economico, non tecnica.
2. **La soglia "spedizione gratis sopra X".** Sopra X di che cosa? Del totale KY, del totale in euro, o della somma? E vale per venditore (l'ordine è per venditore) o per carrello?
3. **L'IVA e la fattura.** La quota euro non passa dal circuito: va sullo Stripe o sul PayPal dell'azienda venditrice. La fattura di quella parte è sua, non nostra. KMoney può al massimo produrre un **documento di riepilogo dell'ordine** con la P.IVA delle due parti e l'imponibile diviso per quota — utile e onesto — ma chiamarlo fattura sarebbe sbagliato. Questa è la voce da passare al commercialista prima che allo sviluppatore.

---

## 3. Perché oggi **non si può** mettere su un tema premium

Questo è il punto che conta più di tutta la lista sopra, e il motivo per cui non partirei dalle funzioni.

**Non esiste un sistema di componenti.** La cartella `resources/views/components/` non c'è. La card prodotto — l'elemento più ripetuto dell'intero negozio — è **copiata e incollata quattro volte** (catalogo, i-miei-prodotti, offerte, striscia in evidenza). Ogni pagina si porta dentro il proprio blocco `<style>`: 3.795 righe di Blade per il solo percorso d'acquisto, con circa 200 righe di CSS duplicate a pagina. Un restyle oggi si fa da quattro a dodici volte a mano, e ogni volta si sbaglia in un posto solo.

**La modalità scura è rotta su 8 viste su 12.** Solo `shop`, `shop-mine` e `shop-offers` usano i token del layout. Scheda prodotto, carrello, cassa, grazie, ordini, dettaglio ordine e vendite scrivono colori a mano (`#10263d`, `#334155`, e altri 190 esadecimali cablati): a tema scuro attivo si legge **testo scuro su fondo scuro**.

**Non c'è un carattere tipografico.** `app.css` è lungo undici righe e dichiara `Instrument Sans`, che poi **non viene mai caricato**: niente Google Fonts, niente `@font-face`. Il portale gira sui font di sistema, e su Mac e Linux "Aptos" non esiste nemmeno. Un tema premium si riconosce dal carattere prima ancora che dal colore.

**La tavolozza è bancaria, non commerciale.** L'intestazione dei token lo dichiara: *"Hybrid Fineco (professional) + Revolut (modern)"*. È bella e coerente per un conto corrente, ma **manca il colore dell'acquisto**: oggi "Aggiungi al carrello" ha lo stesso blu di "Filtra" e "Modifica". Nessuna gerarchia fra l'azione che porta soldi e l'azione di servizio.

**Tailwind è installato e non usato.** Classi utility in 5 file Blade su 261. Il progetto è al 100% CSS scritto a mano. Non è un difetto in sé, ma significa che nessuna libreria di componenti pronta si può calare dentro: il tema va costruito, non comprato.

**La scheda prodotto è il pezzo più lontano da un tema Woo.** Nessuna briciola di pane, correlati come elenco di righe invece che carosello di card, nessuna scheda a linguette (descrizione / caratteristiche / spedizione / resi), nessun blocco recensioni, nessun ingrandimento al passaggio del mouse, nessuna barra "aggiungi al carrello" appiccicata in basso su mobile. Manca anche `loading="lazy"` sulla galleria, `srcset`, e l'`aspect-ratio` sui tag immagine: la pagina sfarfalla mentre carica.

**La conclusione operativa è secca: il collo di bottiglia non sono le funzioni mancanti, è l'assenza di una base condivisa.** Fatta quella, ogni funzione nuova costa poco. Saltata, ogni funzione nuova va scritta e rivestita da quattro a dodici volte.

---

## 4. Il piano

Sette fasi. L'ordine non è negoziabile fino alla 1: la 0 è la condizione di tutto il resto. Le stime sono giornate di lavoro pieno, con test e mutazioni deliberate come nelle fasi precedenti.

### Fase 0 — La base del tema *(3-4 giorni) — da fare per prima*
Estrarre i componenti Blade veri: `<x-shop.product-card>`, `<x-shop.price>`, `<x-shop.badge-mix>`, `<x-shop.stock>`, `<x-shop.empty-state>`, `<x-shop.breadcrumb>`. Un solo foglio `shop.css` al posto dei dodici blocchi `<style>`. Tutti i 190 esadecimali sostituiti dai token, **modalità scura riparata su tutte e dodici le viste**. Un carattere vero caricato in locale (niente CDN: la CSP è già stretta e il deploy è cPanel). Un colore dell'acquisto aggiunto ai token, con la gerarchia dei bottoni rifatta di conseguenza. `srcset` + `aspect-ratio` + `lazy` ovunque.
**Nessuna funzione nuova. Nessun cambiamento visibile all'utente se non un negozio più coerente e più veloce.** È l'investimento che rende tutto il resto rapido.

### Fase 1 — Il tema premium vero *(4-5 giorni)*
Catalogo: **ordinamento** (rilevanza, prezzo ↑↓, novità, più venduti — quest'ultimo richiede un contatore vendite su `listings`, oggi assente), filtri per attributo e per fascia di prezzo, filtri che si applicano senza ricaricare la pagina, briciole di pane, scheletri di caricamento. Scheda prodotto rifatta: breadcrumb, linguette descrizione/caratteristiche/spedizione e resi, correlati come carosello di card, ingrandimento al passaggio del mouse, barra d'acquisto appiccicata in basso su mobile, e i tre blocchi che oggi mancano e che trattengono dal comprare — **tempi di consegna, politica di reso, valutazioni**. Cassa con indicatore di passo grafico e sigilli di fiducia.

### Fase 2 — Fiducia: le recensioni *(2-3 giorni)*
Le decisioni sono già prese il 26/08 e le confermo: si pubblicano subito (nascono `published`, non in coda), l'admin può nasconderle o cancellarle, e **le scrive solo chi ha un ordine `delivered` per quel prodotto** — altrimenti diventa un canale di spam. Stelle in card, media sul prodotto, filtro "solo 4+ stelle".

### Fase 3 — Le leve commerciali *(4-5 giorni)*
Coupon a livello ordine (**dopo aver deciso il nodo 1 del capitolo 2**), soglia di spedizione gratuita (**dopo il nodo 2**), preferiti, visti di recente, cross-sell e upsell configurabili dal venditore, conto alla rovescia sulle offerte, contatore vendite.

### Fase 4 — Il retro del negozio *(4-5 giorni)*
Spedizione configurabile davvero: tariffe per venditore, per zona, per soglia, con la scelta fra spedizione e ritiro **sullo stesso prodotto** (oggi `delivery_type` è uno solo per prodotto, ed è il motivo per cui in cassa non c'è niente da scegliere). Soglia di scorta bassa con avviso al venditore. Import prodotti da CSV. Prodotti digitali con file e link di download a scadenza.

### Fase 5 — Documenti e IVA *(3-4 giorni, ma solo dopo il commercialista)*
Aliquota IVA su prodotto, imponibile e imposta separati su `order_items`, documento di riepilogo dell'ordine in PDF con le due P.IVA e la divisione fra quota KY e quota euro. **Non chiamarlo fattura finché non lo dice il commercialista.**

### Fase 6 — Vetrina pubblica e SEO *(3-4 giorni)*
Slug sui prodotti, meta e Open Graph, catalogo leggibile senza login. È già analizzato e pianificato in `PIANO_VETRINA_AGENTE.md` e `ANALISI_VETRINA_AGENTE_2026-09-02.md`, e si incastra qui: la vetrina dell'agente e il catalogo pubblico sono lo stesso motore con due vestiti.

**Totale indicativo: 23-30 giornate.** Le fasi 0 e 1 da sole (7-9 giornate) portano già il negozio a somigliare a un tema premium; il resto aggiunge funzioni.

---

## 5. Cinque cose che devo sapere da te

1. **Coupon: sconto sui KY, sugli euro, o su tutti e due in proporzione?** (blocca la fase 3)
2. **Spedizione gratis sopra una soglia: soglia su cosa, e per venditore o per carrello?** (blocca la fase 3)
3. **Documento fiscale: vuoi che ne parli il commercialista prima che io scriva qualcosa?** Io lo consiglio: la quota euro non passa dal circuito e la fattura di quella parte è del venditore. (blocca la fase 5)
4. **La vetrina pubblica: la vuoi dentro questo lavoro o resta un cantiere a parte?** Cambia l'ordine, non il contenuto.
5. **Il deploy di kmoney.it: è sbloccato?** Era il rischio n. 7 del piano del 25/08 ed è il prerequisito di qualunque rilascio. Se è ancora fermo, si lavora e si rilascia su kosmopay.it per primo, come sempre.

---

## 6. La mia raccomandazione

Parti dalla **fase 0**, anche se è quella che sembra non produrre niente. Tre giorni in cui l'utente non vede nulla di nuovo, e dopo i quali tutto il resto costa un terzo. Se invece si comincia dalle funzioni — recensioni, coupon, wishlist — ognuna va scritta contro dodici fogli di stile diversi, e fra un mese il negozio avrà più funzioni di WooCommerce e sembrerà comunque un gestionale.
