# Vetrina dell'agente — piano operativo

**Data:** 02/09/2026
**Nasce da:** `ANALISI_VETRINA_AGENTE_2026-09-02.md` (leggerla prima: qui ci
sono le mosse, là il perché).
**Stato:** piano. Nessuna riga scritta, nessuna migrazione, nessun SQL.

---

## 0. Le sei decisioni chiuse

| # | Decisione |
|---|---|
| 1 | Catalogo **pubblico in sola lettura** col marchio dell'agente; **acquisto riservato ai suoi clienti** |
| 2 | Marchio dell'agente in vetrina; ***"Venduto e spedito da X"*** in chiaro dal carrello in poi |
| 3 | Comprano **solo i clienti diretti** (`users.mlm_client_agent_id`), non la downline |
| 4 | **Nessuna provvigione sulle vendite**: le commissioni restano sulle ricariche |
| 5 | **In vetrina non si vedono i prezzi**: compaiono dopo il login |
| 6 | **L'agente sceglie le categorie**, non i singoli prodotti |

---

## 1. Il principio che regge tutto

**La vetrina non è un'applicazione nuova: è lo stesso portale, visto da un altro
dominio, con un altro vestito e un catalogo filtrato.**

Stesse rotte d'acquisto, stessi controller, stesso `TransferBookingService`,
stesso carrello, stessa cassa, stessi ordini. **Il motore che muove i soldi non
si tocca**: nessuna modifica a `book()`, al ledger, ai limiti, al cashback.
Cambia solo chi risponde alla domanda "che aspetto ho e cosa faccio vedere".

Se durante il lavoro una fase chiede di toccare `TransferBookingService`,
`LedgerEntry` o `Account`, ci si ferma: vuol dire che si è sbagliata strada.

---

## 2. Le due facce della vetrina

**Faccia pubblica** — chiunque, senza login, su `mario.kmoney.it`:
- la home è il **catalogo dell'agente** (le categorie che ha scelto)
- schede prodotto **senza prezzo**, **senza nome dell'azienda**, col marchio
  dell'agente
- un solo invito, ripetuto dove serve: *"Entra o registrati con Mario per vedere
  i prezzi e acquistare"*
- pagine legali e cookie policy, come su qualsiasi sito

**Faccia privata** — dopo il login, solo i clienti diretti di quell'agente:
- lo shop di sempre (catalogo con prezzi, carrello, cassa, ordini), vestito
  dell'agente
- *"Venduto e spedito da X"* in chiaro in carrello e in cassa
- tutto il resto del portale (conti, movimenti, wallet, MLM, ricariche) **vive
  solo sul dominio principale**

### Una nota onesta sul prezzo nascosto

Un catalogo senza prezzi converte meno di uno con i prezzi: è il prezzo da
pagare per non esporre il listino del circuito. **Si può però far lavorare la
mancanza a nostro favore**: il prezzo assente diventa il motivo per registrarsi,
e la registrazione avviene sul dominio dell'agente, quindi il cliente è suo per
costruzione. È il meccanismo che usano i cataloghi B2B. Va scritto bene il
messaggio: non *"prezzo riservato"* (respinge), ma *"i prezzi del circuito sono
riservati ai membri — entra con Mario, ci vogliono due minuti"*.

Da rivedere fra tre mesi con i numeri in mano: se la vetrina porta visite ma non
registrazioni, il primo esperimento è accendere i prezzi.

---

## 3. Fase 1 — Il motore (2-3 giorni)

Niente di visibile. Solo la macchina che collega un dominio a un agente.

**SQL (per primo, a blocchi ri-eseguibili da phpMyAdmin)**

```
agent_storefronts
  id, uuid
  user_id             FK users   (l'agente)          [UNIQUE: una vetrina per agente]
  hostname            varchar    [UNIQUE]            'mario.kmoney.it' | 'shopmario.it'
  status              enum       draft|active|suspended
  brand_name, tagline
  logo_path, primary_color, accent_color
  contact_email, contact_phone, footer_text
  custom_domain_verification_token, custom_domain_verified_at
  timestamps

agent_storefront_categories
  agent_storefront_id FK
  listing_category_id FK                              [UNIQUE insieme]
```

**Codice**
- Modello `AgentStorefront` (+ relazioni, scope `attive()`).
- Middleware `ResolveAgentStorefront`, in testa al gruppo web: legge
  `$request->getHost()`, cerca in tabella **con cache**, e lega il risultato al
  container. Host non trovato → non fa nulla, il portale funziona come oggi.
- **Cache anche del "non trovato"**, altrimenti ogni bot che bussa con un Host
  a caso costa una query.
- Invalidazione della cache al salvataggio della vetrina.

**Test**
- host della vetrina → risolve; host principale → non risolve niente
- host inventato → nessuna vetrina (**e non un errore**: il confronto è con una
  lista chiusa in tabella, un `Host:` falsificato non apre niente)
- vetrina `suspended` → si comporta come host sconosciuto
- il portale sul dominio principale non cambia di una riga (regressione)

---

## 4. Fase 2 — Il vestito (3-4 giorni)

- **`BrandingResolver`**: se c'è una vetrina restituisce il suo branding,
  altrimenti quello globale di `system_settings`. **Un solo punto di verità**,
  così email e PDF potranno agganciarsi dopo senza riscrivere niente.
- **`resources/views/layouts/storefront.blade.php`, nuovo**: logo dell'agente,
  navigazione per categoria, carrello, "i miei ordini", entra/esci. Non si tocca
  `layouts/portal.blade.php` (3.020 righe): si affianca.
- Le 10 view dello shop passano da `@extends('layouts.portal')` a
  `@extends($layout)`, condiviso da un view composer. Una riga per file.
- Login e registrazione vestiti anche loro, altrimenti l'illusione si rompe
  proprio nel punto in cui chiediamo di registrarsi.
- Pagina *"Questo negozio è riservato ai clienti di Mario"*, che serve in fase 4.

**Test:** sull'host principale non cambia niente (regressione); sull'host
vetrina il logo, i colori e il nome sono quelli dell'agente.

---

## 5. Fase 3 — La faccia pubblica (3-4 giorni)

Le rotte pubbliche nuove **non collidono con niente**: verificato, `/prodotto`,
`/vetrina` e `/catalogo` sono liberi. La home invece è già presa, quindi:

- `GET /` — se c'è una vetrina risolta, `HomeController` consegna il catalogo
  dell'agente invece della landing KMoney. Se non c'è, tutto come oggi.
- `GET /prodotto/{listing}` — scheda pubblica.
- Filtro: solo prodotti `Listing::scopeActive()` **nelle categorie scelte
  dall'agente**. Categoria padre scelta ⇒ dentro anche le sue sottocategorie.

**Le tre cose che NON devono finire nell'HTML** (e per ognuna un test che cerca
la stringa nel corpo della risposta, non che controlla il CSS):
1. **nessun prezzo**: né `price_ky`, né importo, né `ky_percentage`, né il mix.
   Il prezzo non deve *arrivare* alla pagina, non basta nasconderlo;
2. **nessun nome di azienda**: i cinque punti sono già mappati
   (`shop.blade.php:155` e `:232`, `shop-show.blade.php:21`, `:130`, `:313`),
   più il filtro `?company=` e la ricerca `?q=`, che oggi cerca **anche nel nome
   dell'azienda** (`ListingController.php:81-99`) e la rivelerebbe per rimbalzo;
3. **nessun marchio KMoney**: logo, nome circuito, link al portale.

**Altro in questa fase**
- `noindex` di default su tutte le vetrine, e `robots.txt` per host.
  Motivo: N vetrine con gli stessi prodotti sono contenuti duplicati, e senza
  prezzi Google non porterebbe comunque acquisti. È un interruttore: si potrà
  accendere quando avrà senso.
- L'invito a entrare, con il **ref implicito dal dominio**.
- Link a privacy, termini e cookie policy: le pagine esistono già.
- `views_count` **fuori dalla richiesta** (oggi è già un lavoro sincrono, e qui
  arriverebbe traffico anonimo).
- `throttle` sulle pagine pubbliche.

**Nota:** le immagini dei prodotti sono **già pubbliche** oggi
(`GET /storage/{path}`, `routes/web.php:145`), quindi non si apre niente di
nuovo. Ma restano gli originali fino a 3 MB senza miniature: su una vetrina
pubblica si vede subito. Vale la pena mettere in conto le miniature qui, o
accettare che la prima impressione sia una pagina lenta.

---

## 6. Fase 4 — La faccia privata (2-3 giorni)

- **`EnsureStorefrontClient`**: passa solo chi ha
  `mlm_client_agent_id === storefront.user_id`, più l'agente stesso. Chiunque
  altro vede la pagina cortese della fase 2, con il link al portale principale.
- **Regola non negoziabile: nessuno viene mai riassegnato.** Un cliente di un
  altro agente che entra da qui resta del suo agente. Il dominio assegna
  l'agente **solo a chi si registra ed è davvero nuovo**. Senza questa regola si
  è costruita una macchina per rubarsi i clienti, e la prima lite arriva subito.
- **Lista chiusa delle rotte ammesse** sull'host vetrina: catalogo, prodotto,
  carrello, cassa, grazie, i propri ordini, login, registrazione, legali.
  **Tutto il resto → 302 al dominio principale.** L'elenco sta in **un posto
  solo**, con un test che lo percorre riga per riga — la stessa forma di
  `EnsureRegistrationFeePaid`, che è già la convenzione di casa e nasce dallo
  stesso problema (la rotta aggiunta fra sei mesi che resta scoperta).
- ***"Venduto e spedito da X"*** in carrello e in cassa, in chiaro. È la
  contropartita della decisione 2 e non è negoziabile con la grafica.
- Registrazione col **ref implicito**: sul dominio dell'agente il codice non
  serve, lo dice il dominio. Risolve anche la fragilità di oggi, dove il `ref`
  viaggia come campo nascosto (`auth/register.blade.php:441`) e **si perde se
  l'utente naviga altrove prima di registrarsi**.

**Avvertenza da dare agli agenti prima, non dopo:** `EnsureRegistrationFeePaid`
è agganciato a tutto il gruppo web (`bootstrap/app.php:26`). Un cliente che non
ha saldato la quota **entra e guarda, ma non compra** — anche nella vetrina più
bella.

---

## 7. Fase 5 — Il pannello (2 giorni)

**L'agente** configura: nome del negozio, tagline, logo, due colori, contatti,
categorie, e vede un'anteprima.

**L'admin** e solo l'admin: crea la vetrina, **assegna l'hostname**, attiva e
sospende. L'hostname non lo tocca l'agente, altrimenti il primo sveglio si
prende `www.kmoney.it`.

Ogni modifica in `AuditLog`, come tutto il resto.

---

## 8. Fase 6 — I domini (1-2 giorni + hosting)

**Sottodominio `mario.kmoney.it`** — nessun deploy per agente:
1. in cPanel si crea il sottodominio con *document root* **la stessa cartella
   del sito** (`kosmon/public` su kosmopay; `public_html` su kmoney, dove
   l'`index.php` personalizzato punta a `../kosmon_git` e **non va mai
   sovrascritto**);
2. AutoSSL emette il certificato, di norma da solo;
3. si inserisce la riga in `agent_storefronts` dal backoffice.

**Dominio proprio `shopmario.it`** — stessa cosa più un Alias/Addon domain, ma:
- **verifica con record TXT prima di attivare**, altrimenti chiunque può puntare
  un dominio a noi e apparire come nostro;
- pagina di stato *"il tuo dominio non punta ancora qui, ecco il record da
  inserire"*, altrimenti ogni agente che sbaglia un DNS diventa una telefonata.

**Da verificare con l'hosting, prima della fase 6:**
- se AutoSSL può emettere un **wildcard `*.kmoney.it`** (su hosting condiviso di
  solito no: serve un certificato per sottodominio, emesso però in automatico);
- se il piano consente la **UAPI via token** (`SubDomain::addsubdomain`), che
  permetterebbe di creare i sottodomini dal backoffice invece che a mano, senza
  SSH.

---

## 9. Regole di lavoro (le solite)

- **Prima l'SQL**, a blocchi ri-eseguibili da phpMyAdmin (l'utente cPanel non
  legge `information_schema`), poi il codice.
- **Test + mutazioni deliberate a ogni fase**: una mutazione che sopravvive è un
  test che non c'è.
- **Un commit per fase**, messaggio in italiano.
- **Si deploya kosmopay.it per primo**, poi kmoney.it.
- Ogni fase lascia il portale sul dominio principale **identico a prima**: è
  l'invariante da testare a ogni giro.

---

## 10. Prima di cominciare

**Vanno chiusi i punti dell'audit del 26/08 che toccano i soldi**, perché la
vetrina moltiplica il traffico d'acquisto e quindi la probabilità che escano —
e dopo si sistemano con clienti veri di mezzo:

1. doppio addebito su POST simultanei (`CartService.php:174-234`);
2. `orders.status` che non diventa mai `paid` quando entrano gli euro;
3. scorte non restituite al rimborso;
4. quota EUR senza importo minimo né scadenza.

E il prerequisito che non dipende da noi: **il deploy di kmoney.it è fermo dal
30/06**. Su kosmopay.it si può partire comunque.

---

## 11. Riepilogo dei tempi

| Fase | Cosa | Giorni |
|---|---|---|
| 1 | Motore: tabelle, modello, risoluzione dominio, cache | 2-3 |
| 2 | Vestito: branding, layout nuovo, login e registrazione | 3-4 |
| 3 | Faccia pubblica: catalogo senza prezzi, senza venditore, noindex | 3-4 |
| 4 | Faccia privata: chi entra, lista chiusa delle rotte, "venduto da" | 2-3 |
| 5 | Pannello agente + backoffice | 2 |
| 6 | Domini: procedura, verifica TXT, runbook | 1-2 |
| | **Totale** | **13-18** |

Fuori stima: le **miniature delle immagini** (oggi non esistono) e la chiusura
dei quattro punti dell'audit.

---

## 12. Cosa NON entra in questa tranche

Per essere chiari su cosa non arriverà, e non scoprirlo a metà strada:

- **provvigioni sulle vendite** — decisione 4, si guadagna sulle ricariche;
- **fatturazione e IVA** — buco strutturale già noto, resta aperto;
- **la downline** — comprano solo i clienti diretti;
- corrieri, recensioni, coupon, prodotti digitali;
- email e PDF vestiti dell'agente: partono col marchio del circuito. Il
  `BrandingResolver` della fase 2 è già la porta per farlo dopo, ma dopo.
