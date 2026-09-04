# Vetrina dell'agente su dominio proprio — analisi di fattibilità

**Data:** 02/09/2026
**Domanda di Laura:** dare a ogni agente un e-commerce non autonomo — una *vista*
dello shop KMoney con i propri dati — su un dominio o sottodominio suo, dove far
comprare i propri clienti senza mostrare i dati dello shop.

**Stato:** analisi. Nessuna riga di codice scritta, nessuna migrazione, nessun SQL.

---

## 0. Le quattro scelte già fatte

| Domanda | Risposta di Laura |
|---|---|
| Cosa nascondere al cliente | il **marchio KMoney** e l'**azienda venditrice** |
| Chi compra | **solo i clienti già assegnati a quell'agente** |
| Cosa guadagna l'agente sulle vendite | **da decidere** — vedi §6 |
| Domini | **sottodominio subito per tutti, dominio proprio a richiesta** |

Il resto del documento parte da qui.

---

## 1. La contraddizione da sciogliere per prima

Le prime due scelte tirano in direzioni opposte, e conviene dirlo subito.

Comprare su KMoney significa pagare in **KY dal proprio conto**. Chi è già
cliente assegnato a un agente **ha già un conto KMoney**: ha firmato il
contratto di adesione, ha fatto il KYC, ha una KY Card, riceve le email del
circuito, entra nel portale per vedere il saldo. **A quella persona il marchio
KMoney non si può nascondere, perché lo conosce già** — e ci torna ogni volta
che deve ricaricare.

Quindi, con la scelta "solo clienti già assegnati", la vetrina bianca **non
serve a nascondere il circuito: serve a dare all'agente una casa sua**. È uno
strumento di immagine e di fidelizzazione, non di occultamento. Ed è una cosa
legittima e utile — ma va chiamata col suo nome, altrimenti si spende per un
risultato che non arriverà.

Se invece l'obiettivo vero è **acquisire gente che KMoney non la conosce**,
allora la risposta alla seconda domanda va cambiata, e cambia il progetto (§9,
punto 1). Esiste una **via di mezzo che costa poco**:

> **catalogo pubblico in sola lettura, acquisto riservato ai clienti dell'agente.**
> Il visitatore che arriva su `shopmario.it` vede i prodotti col marchio di
> Mario, si convince, e per comprare deve entrare — cioè registrarsi con Mario.
> La vetrina diventa lo strumento di acquisizione che oggi manca (l'unico è un
> link `?ref=` che si perde se l'utente naviga altrove).

Il prezzo di questa via di mezzo: oggi il catalogo **non è esposto a nessuno**
fuori dal login (§2). Renderlo pubblico significa mostrare prodotti e prezzi del
circuito al mondo, concorrenti inclusi. È una decisione commerciale, non tecnica.

---

## 2. Il punto di partenza reale

Ho verificato il codice, non i documenti. In sintesi:

**Lo shop oggi**
- Il venditore di un prodotto è **l'azienda** (`listings.company_id`), mai l'agente.
- Il catalogo è **globale**: `Listing::scopeActive()` (`app/Models/Listing.php:223`)
  filtra solo stato, scadenza e aziende non sospese. Nessuno scoping per circuito,
  per zona, per agente.
- **Zero superficie pubblica.** Tutte le rotte `/shop/*` stanno nel gruppo
  autenticato di `routes/web.php:416`. In `routes/api.php` **non esiste un solo
  endpoint sui listing**. L'unica cosa del catalogo già raggiungibile senza login
  sono le immagini (`GET /storage/{path}`, `routes/web.php:145`).
- Il pagamento: KY via `TransferBookingService::book()` con
  `kind = portal_marketplace_order`; la quota in euro **non passa mai dal
  circuito**, va sul conto Stripe/PayPal **dell'azienda venditrice**
  (`PaymentController.php:22`: *"Il denaro arriva SEMPRE sul conto proprio
  dell'azienda: Kosmopay non lo intermedia mai"*).
- Un ordine = **un solo venditore**; spedisce il venditore, a mano.

**L'agente oggi**
- È un `User` con `mlm_role = 'agente'`, di norma **un privato KYP senza azienda**
  (`MlmPortalController.php:229`), con contratto di *Incaricato di Vendita*
  (D.Lgs 114/98).
- Il cliente è agganciato con `users.mlm_client_agent_id` (`AuthController.php:107-110`).
- **Gli ordini dello shop non generano nessuna provvigione.** Verificato: zero
  occorrenze MLM in `OrderService`, `CartService`, `CartController`,
  `ListingController`. Le provvigioni maturano solo su **registrazioni** e
  **acquisto di KY Card**.
- Non esiste **nulla** di personalizzabile per agente: né logo, né pagina, né
  materiale. L'unico asset personale è il link `?ref=` e il codice `K#####`.

**Il vestito**
- Il branding è **globale e unico**: `system_settings` con `circuit_name`,
  `logo_path`, `primary_color`, `accent_color`, gestito dal solo backoffice.
- Le 10 view dello shop fanno tutte `@extends('layouts.portal')`, e
  `layouts/portal.blade.php` è **un file solo da 3.020 righe** con dentro tutta
  la barra laterale del portale (conti, movimenti, wallet, MLM, backoffice).
- Non esiste alcun layout pubblico riutilizzabile: l'unico alternativo è
  `layouts/legal.blade.php`, 64 righe.

**Conclusione del punto 2:** la vetrina per agente è **greenfield al 100%**, ma
quasi tutto il lavoro è *vestito e filtro*, non motore. Il motore che muove i
soldi non va toccato.

---

## 3. L'architettura proposta: non un'applicazione nuova

La tentazione è costruire "l'app dell'agente". Sarebbe l'errore più caro.

**La vetrina è lo stesso portale, visto da un altro dominio, con un altro
vestito e un catalogo filtrato.** Stesse rotte, stessi controller, stesso
`TransferBookingService`, stesso carrello, stessa cassa, stessi ordini. Cambia
chi risponde alla domanda "che aspetto ho e cosa faccio vedere".

Quattro pezzi, in ordine di dipendenza.

### 3.1 Risoluzione del dominio → agente

Tabella nuova, **una sola**:

```
agent_storefronts
  id, uuid
  user_id            -> l'agente (FK users)
  hostname           -> 'mario.kmoney.it' oppure 'shopmario.it'  [UNIQUE]
  status             -> draft | active | suspended
  brand_name, tagline, logo_path, primary_color, accent_color
  contact_email, contact_phone, footer_text
  catalog_mode       -> tutto | categorie | prodotti_scelti
  custom_domain_verified_at
  timestamps
```

Middleware `ResolveAgentStorefront`, in testa al gruppo web: legge
`$request->getHost()`, cerca in tabella (con cache), e se trova qualcosa lo
lega alla request. Se l'host è il dominio principale, non fa niente e il
portale funziona esattamente come oggi.

Tre dettagli tecnici che ho verificato e che rendono la cosa praticabile:

- `SESSION_DOMAIN=null` (`.env.example:49`) → **il cookie di sessione è per
  host**. Ogni dominio ha la sua sessione, senza interferenze. *Da verificare
  che sia null anche nei `.env` di produzione.*
- **Non c'è route cache** (in `bootstrap/cache/` ci sono solo `packages.php` e
  `services.php`) — coerente con un deploy cPanel che non lancia artisan. Quindi
  si può anche caricare rotte in modo condizionale, se servisse.
- L'host arriva dalla request e **non c'è `TrustHosts`**. Non è un buco, perché
  si confronta con una **lista chiusa in tabella**: un host falsificato non
  risolve nessuna vetrina. Ma va scritto nel test.

### 3.2 Il vestito

- Un **`BrandingResolver`**: se c'è una vetrina risolta restituisce il suo
  branding, altrimenti quello globale di `system_settings`. Un solo punto di
  verità, così le email e i PDF potranno agganciarsi dopo senza riscrivere nulla.
- Un **`layouts/storefront.blade.php` nuovo**: intestazione col logo dell'agente,
  navigazione del catalogo, carrello, "i miei ordini", uscita. **Non** si tocca
  il file da 3.020 righe: si affianca. Il layout del portale resta quello del
  portale.
- Le 10 view dello shop passano da `@extends('layouts.portal')` a
  `@extends($layout)`, con `$layout` condiviso da un view composer. Una riga per
  file, nessun rischio.

> **Perché non riusare il layout portale nascondendo le voci di menu:** perché
> lo si è già visto il 01/09 col Kit Merchant — *il pannello nasconde il link,
> non chiude la porta*. E soprattutto perché una vetrina che sembra un
> home banking non è una vetrina.

### 3.3 Il catalogo e il venditore

- **Filtro**: `catalog_mode` decide se l'agente mostra tutto il circuito, solo
  certe categorie, o un elenco scelto a mano (pivot `agent_storefront_listings`).
  Il filtro si aggiunge dove già si filtra, in `ListingController::index()`
  (`app/Http/Controllers/ListingController.php:81-99`).
- **Venditore**: in modalità vetrina si tolgono nome azienda, logo, link alla
  scheda azienda e il filtro `?company=`. I punti da toccare sono cinque e li ho
  già individuati: `shop.blade.php:155`, `shop.blade.php:232`,
  `shop-show.blade.php:21`, `:130`, `:313`.
  **Fin dove si può spingere questa cosa è il §5, e va letto.**

### 3.4 Chi entra

- Middleware `EnsureStorefrontClient` dopo `auth`: passa solo se
  `user.mlm_client_agent_id === storefront.user_id` (o è l'agente stesso).
  Da decidere se ammettere anche la downline: è un flag.
- **Registrazione col ref implicito**: sul dominio dell'agente il codice non
  serve, lo dice il dominio. Questo risolve anche una fragilità che esiste oggi —
  il `ref` viaggia solo come campo nascosto nel form
  (`resources/views/auth/register.blade.php:441`) e **si perde se l'utente
  naviga altrove prima di registrarsi**.
- **Regola non negoziabile:** un cliente già assegnato a un altro agente che
  entra dal dominio sbagliato **non viene mai riassegnato**. Il dominio assegna
  l'agente solo a chi è davvero nuovo. Altrimenti si è costruita una macchina
  per rubarsi i clienti fra agenti, e i primi due che se ne accorgono aprono
  una lite.

### 3.5 Cosa NON vive sul dominio dell'agente

Questo è il punto che tiene basso il costo. Sul dominio dell'agente si
raggiungono **solo**: catalogo, scheda prodotto, carrello, cassa, pagina grazie,
i propri ordini, login/registrazione, pagine legali. **Tutto il resto — dashboard,
movimenti, wallet, MLM, backoffice, profilo, KY Card — reindirizza al dominio
principale.**

Senza questa lista chiusa, "vestire la vetrina" diventa "rifare il portale", e
il progetto passa da settimane a mesi.

Conseguenza da accettare: **quando il cliente va a ricaricare KY, esce dalla
vetrina e vede KMoney.** È inevitabile finché si paga in KY.

---

## 4. Un avviso sul motore che sta sotto

`EnsureRegistrationFeePaid` è agganciato a **tutto il gruppo web**
(`bootstrap/app.php:26`), non al solo portale. Quindi anche la vetrina più bella
mostrerà la pagina della quota a chi non l'ha pagata. È giusto che sia così, ma
l'agente va avvisato prima, non dopo.

---

## 5. Fin dove il venditore si può nascondere davvero

Laura ha chiesto di nascondere l'azienda venditrice. **In catalogo si può. Dal
carrello in poi, no** — e non per pigrizia, ma perché il venditore *è* la
controparte del contratto. Ecco dove esce, in ordine di quando il cliente lo
incontra:

| Dove | Perché esce | Si può evitare? |
|---|---|---|
| Cassa in euro | Stripe/PayPal usano le credenziali **dell'azienda**: il cliente atterra su una pagina col nome commerciale del venditore | No, salvo cambiare chi incassa |
| Pacco e documento di trasporto | Spedisce il venditore, dal suo magazzino | No |
| Fattura / scontrino | La emette chi vende | No |
| Reso e garanzia | La richiesta va al venditore, che accetta e rimborsa | No |
| Pagina "i miei ordini" | L'ordine è per venditore | Solo riscrivendola |

E c'è il lato legale, che segnalo da non avvocato e che va passato al legale:
il Codice del Consumo (D.Lgs 206/2005, art. 49) impone di dare **identità e
indirizzo del professionista prima che il consumatore sia vincolato**; presentare
come proprio il prodotto di un terzo entra nel campo delle pratiche commerciali
ingannevoli (artt. 21-22). In più, il contratto che l'agente ha firmato è di
*Incaricato di Vendita* (D.Lgs 114/98): vende **in nome e per conto**, non in
proprio. Nascondere del tutto il venditore contraddice il contratto che l'agente
ha firmato.

**La proposta onesta, e quella che consiglio:**

> **Il marchio è dell'agente. Il venditore non si pubblicizza, ma non si nasconde.**
> Nel catalogo e nella scheda prodotto sparisce il nome dell'azienda, il logo e il
> link. In carrello e in cassa compare, in chiaro e non in piccolo,
> *"Venduto e spedito da <Azienda>"*. Il cliente vede il negozio di Mario; quando
> sta per firmare, sa da chi compra.

L'unica strada che nasconde il venditore per davvero è la **rivendita con
ricarico** (§6, opzione C), e ha un prezzo alto.

---

## 6. Cosa guadagna l'agente: le tre strade

Premessa che cambia il ragionamento: **oggi gli ordini dello shop non generano
provvigioni**. Le provvigioni nascono da registrazioni e da **acquisto di KY
Card** (`KyCardController.php:707-720`), con base "Prov K" = importo × margine
KNM (30% di default, `MlmCommissionEngine.php:32-42`).

### A — Nessuna provvigione sulle vendite: la vetrina serve ad acquisire

**Pro**
- Zero lavoro sul motore delle provvigioni, zero rischio sui soldi, zero
  problemi fiscali.
- Coerente col modello attuale e col contratto già firmato.
- **E soprattutto: non è vero che l'agente non guadagna.** Per comprare servono
  KY; i KY si comprano con le KY Card; **le KY Card sono già provvigionate**.
  Una vetrina che fa spendere KY fa comprare più ricariche, e su quelle l'agente
  prende già. Il cerchio si chiude da solo, senza scrivere una riga sul motore.

**Contro**
- L'agente potrebbe non percepirlo come guadagno diretto e spingere poco.
- Il legame vendita → ricarica → provvigione è indiretto: va spiegato bene,
  altrimenti sembra lavorare gratis.

### B — Provvigione sull'ordine

Prima domanda, e non è tecnica: **chi la paga?**

- **B1, la paga il venditore** (fee di canale sull'ordine). Oggi il circuito non
  trattiene **nulla** sulle vendite. Serve che le aziende accettino di pagare un
  canale di vendita: è una trattativa commerciale, non un'implementazione.
- **B2, la paga il circuito** dal margine KNM. Ma sugli ordini shop il circuito
  **non incassa margine**: si pagherebbe con altre entrate. Senza numeri alla
  mano, insostenibile.
- **B3, maggiorazione sul prezzo in vetrina.** Lo stesso prodotto costa di più a
  seconda del dominio da cui si entra. Prima contestazione garantita, e mina la
  fiducia nel circuito.

**Pro:** motivazione forte e leggibile per l'agente.

**Contro**
- Tocca i soldi: transfer, fee, cashback, idempotenza.
- Va deciso il **fatto generatore**. Se matura all'ordine, con i resi a 14 giorni
  si pagano provvigioni su merce restituita — e oggi **cashback e commissioni non
  vengono stornati sui rimborsi** (scelta consapevole, ma qui diventerebbe un
  buco vero). Realisticamente: consegna + 14 giorni.
- Serve un nuovo `type` di `MlmCommission` con una base diversa dalla "Prov K".

### C — L'agente rivende con ricarico proprio

**Pro:** è l'unica che giustifica davvero il venditore nascosto, perché il
venditore diventa l'agente.

**Contro** (sono dirimenti, oggi)
- L'agente diventa venditore: partita IVA, fattura al cliente, fattura ricevuta
  dall'azienda, IVA, garanzia, recesso, responsabilità del prodotto.
- Oggi l'agente è **un privato KYP senza azienda**, e il contratto firmato dice
  l'opposto (vende in nome e per conto).
- **Il sistema non ha fatturazione né IVA**: `Company.vat_number` non entra mai
  nell'ordine — buco già segnalato nell'audit del 26/08.
- Servirebbero due movimenti KY per ordine (cliente→agente, agente→azienda) e la
  gestione del rischio di credito dell'agente.

### Raccomandazione

**A adesso, B dopo, C non prima che esista la fatturazione.**

Si parte con la vetrina come strumento di immagine e acquisizione, si guarda per
un trimestre quanto vende davvero, e **solo allora** si decide se vale la pena
toccare il motore delle provvigioni. Costruire B su un canale che ancora non ha
un numero di vendite è pagare in anticipo per un problema che potrebbe non
esistere.

---

## 7. I domini: cosa serve davvero

La buona notizia è che **non serve deployare niente per ogni agente**.

**Sottodominio `mario.kmoney.it`**
1. In cPanel si crea il sottodominio e gli si imposta come *document root*
   **la stessa cartella del sito principale** (`kosmon/public` su kosmopay;
   `public_html` su kmoney, dove l'`index.php` personalizzato punta a
   `../kosmon_git` — e **non va mai sovrascritto**, come già scritto nel
   `.cpanel.yml`).
2. AutoSSL emette il certificato per il nuovo sottodominio, normalmente da solo.
3. Si inserisce la riga in `agent_storefronts` dal backoffice.

Nessun file copiato, nessun deploy. Sono clic in cPanel più una riga in tabella.
*Da verificare con l'hosting:* se AutoSSL può emettere un **wildcard `*.kmoney.it`**
— nella maggior parte degli hosting condivisi **no**, serve un certificato per
sottodominio, che però viene emesso in automatico alla creazione. Se si volesse
automatizzare il passo 1 senza SSH si può usare la **UAPI di cPanel via token**
(`SubDomain::addsubdomain`): da verificare che il piano la consenta.

**Dominio proprio `shopmario.it`**
Stessa cosa, ma l'agente deve puntare il DNS al nostro server e noi creiamo un
*Alias/Addon domain* con lo stesso document root. Il certificato arriva quando
il DNS risolve. **Il costo vero qui non è tecnico ma di assistenza:** ogni agente
che sbaglia un record DNS diventa una telefonata. Va previsto un controllo
automatico ("il tuo dominio non punta ancora qui, ecco il record da inserire")
e una verifica con record TXT prima di attivare, altrimenti chiunque può puntare
un dominio a noi.

**Due installazioni, non una.** kmoney.it e kosmopay.it sono due database
separati: un agente esiste su uno solo, e le vetrine si configurano due volte.
Si comincia da **kosmopay.it**, come da regola già in casa, anche perché il
**deploy di kmoney.it è fermo dal 30/06** (§9).

---

## 8. Fasi e costo indicativo

Stime grezze in giornate di lavoro, con la regola di casa: prima l'SQL a blocchi
ri-eseguibili da phpMyAdmin, poi il codice; test e mutazioni deliberate a ogni
fase; un commit per fase; kosmopay.it per primo.

| Fase | Cosa | Giorni |
|---|---|---|
| 0 | **Decisioni di Laura** (§10) | — |
| 1 | Motore: tabella `agent_storefronts`, modello, middleware di risoluzione, cache, CRUD in backoffice. **Niente di visibile** | 2-3 |
| 2 | Vestito: `BrandingResolver`, `layouts/storefront.blade.php`, aggancio delle 10 view, login e registrazione vestiti | 3-4 |
| 3 | Regole: filtro catalogo, venditore attenuato, lista chiusa delle rotte, `EnsureStorefrontClient`, ref implicito | 2-3 |
| 4 | Pannello dell'agente: logo, colori, nome, scelta prodotti; attivazione e sospensione da admin | 2 |
| 5 | Domini: procedura sottodominio, verifica TXT per il dominio proprio, pagina di stato, istruzioni per l'agente | 1-2 |
| — | **Totale per la vetrina funzionante** | **10-14** |
| 6 | *(solo se si sceglie B)* provvigioni sulle vendite | +3-5 |

Fuori stima, ma da mettere in conto: le **immagini**. Oggi non esiste nessuna
miniatura, si servono gli originali fino a 3 MB, senza `loading="lazy"`
(audit del 26/08, blocco 4). In un portale interno è un fastidio; in una vetrina
commerciale è la prima cosa che si vede.

---

## 9. Rischi e prerequisiti, in ordine di gravità

1. **La contraddizione del §1.** Se l'obiettivo è acquisire clienti nuovi, la
   scelta "solo clienti già assegnati" va rifatta *prima* della fase 1, non dopo.
2. **Il venditore non è nascondibile oltre il carrello** (§5). Se questa cosa non
   è accettabile, il progetto non è una vetrina ma una rivendita, e cambia tutto.
3. **Il deploy di kmoney.it è fermo dal 30/06** per modifiche non committate
   direttamente sul server, dove il checkout git *è* la cartella viva. È lo
   stesso nodo del cron rotto scoperto il 01/09. Finché non si scioglie, su
   kmoney.it non arriva niente.
4. **I punti aperti dell'audit del 26/08.** La vetrina moltiplica il traffico
   d'acquisto e quindi la probabilità che escano: doppio addebito su POST
   simultanei, `orders.status` che non diventa mai `paid`, scorte non restituite
   al rimborso, quota EUR senza importo minimo né scadenza. **Sistemarli prima
   costa meno che dopo**, perché dopo si sistemano con clienti veri di mezzo.
5. **Se kshop riparte**, questa roba è debito di migrazione — ma poco: una
   tabella, un middleware, un layout. Il motore non viene toccato, e i piani
   esistenti restano validi.
6. **Sicurezza multi-dominio.** Verificare `SESSION_DOMAIN` nei `.env` di
   produzione (deve restare vuoto), il comportamento di `TrustProxies` con
   `X-Forwarded-Host`, e scrivere il test che un host non in tabella non risolve
   nessuna vetrina.
7. **Le liti fra agenti.** Vetrine diverse, stessi prodotti, stessi clienti:
   servono regole scritte prima (chi può mostrare cosa, cosa succede se un
   cliente compra dalla vetrina di un altro) — altrimenti le scrive il primo
   reclamo.

---

## 10. Cosa resta da decidere

1. **Obiettivo vero della vetrina**: immagine per i clienti che ho, o
   acquisizione di clienti nuovi? Se il secondo, si valuta il catalogo pubblico
   in sola lettura del §1.
2. **Venditore**: accetti la proposta del §5 (marchio dell'agente in catalogo,
   *"Venduto e spedito da"* dal carrello in poi)?
3. **Guadagno dell'agente**: si parte con A e si rivede dopo un trimestre, o
   vuoi B fin da subito? Se B, chi la paga fra B1/B2/B3.
4. **Chi può comprare**: solo i clienti diretti (`mlm_client_agent_id`) o tutta
   la downline dell'agente?
5. **Catalogo**: tutti i prodotti del circuito, o l'agente sceglie?
6. **Chi paga il dominio proprio** e chi dà assistenza sul DNS.
7. **Su quale installazione si parte** (proposta: kosmopay.it).

Rispondi a 1, 2 e 4 e la fase 1 può partire: le altre servono più avanti.

---

## 11. Decisioni prese (02/09/2026, Laura)

1. **Catalogo pubblico in sola lettura col marchio dell'agente; acquisto
   riservato ai suoi clienti.**
2. **Marchio dell'agente in vetrina; *"Venduto e spedito da X"* in chiaro dal
   carrello in poi.**
3. **Comprano solo i clienti diretti** (`users.mlm_client_agent_id`), non la
   downline. Ogni agente ha la propria vetrina.
4. **Nessuna provvigione sulle vendite** (opzione A): le commissioni restano
   sulle ricariche.
5. **In vetrina non si vedono i prezzi**: il prezzo compare dopo il login.
6. **L'agente sceglie le categorie**, non i singoli prodotti: il catalogo si
   riempie da solo anche coi prodotti nuovi.

Il piano operativo che ne discende sta in **`PIANO_VETRINA_AGENTE.md`**.
