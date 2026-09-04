# Analisi pre-lancio — kmoney-app

_03/09/2026. Analisi a fondo richiesta da Laura in vista del lancio "entro qualche giorno" di **tutto insieme**: portale live, shop con il nuovo tema, vetrina agente._
_Nessuna riga di codice toccata, nessun commit, nessun SQL eseguito. Solo lettura._

---

## In una riga

Il motore dei soldi è la parte più solida dell'applicazione e regge il lancio. **Quello che può far male il primo giorno non è il codice: è il commit.** Ci sono cinque cartelle di codice vivo che git non traccia; un `git commit -am` normale le lascia fuori e il portale va in 500 su ogni pagina, login compreso.

---

## 0. La suite: l'ho fatta girare davvero

Non potevo lanciarla dalla shell del sandbox (lì non c'è PHP), così ho portato il sorgente **con dentro il lavoro non committato** in un ambiente PHP 8.4 e l'ho eseguita per intero.

```
Tests: 5 failed, 1755 passed (5701 assertions)  —  110s
```

**I 5 rossi non sono regressioni funzionali.** Li ho aperti uno per uno:

| Test | Perché è rosso | Gravità |
|---|---|---|
| `MiniCarrelloTest` ×3 (taglie / esaurito / roba propria) | `resources/views/layouts/portal.blade.php:126` — il commento CSS del nuovo tema contiene la frase `"Aggiungi al carrello"` fra virgolette. È dentro un `<style>` inline, quindi finisce nell'HTML di **ogni** pagina, e i tre `assertStringNotContainsString('Aggiungi al carrello', $html)` scattano. Il bottone vero è guardato correttamente da `$siPuoAggiungere()`, identico a prima del refactor. | cosmetica |
| `UltimiAttritiBlocco5Test` ×2 (barra filtri) | Le regole `.shop-toolbar { … flex-wrap: wrap … }` e `.shop-toolbar-actions { margin-left: auto; … }` **esistono ancora** — verificate in `public/assets/css/shop.css:38` e `:42`. Sono solo uscite dall'HTML inline per andare nel foglio esterno, e i test cercano la regola dentro la pagina. | test da riscrivere |

**Fix da 10 minuti in tutto:** togliere le virgolette dal commento a `portal.blade.php:126` (basta scrivere *"il bottone d'acquisto"*), e far cercare ai due test di `UltimiAttritiBlocco5Test` il `<link>` a `shop.css` invece della regola inline.

> Il refactor delle quote e il nuovo tema shop **non hanno rotto niente di funzionale**. È il risultato più importante di questa analisi e ora è misurato, non supposto.

---

## 1. BLOCCANTE — Il commit che va fatto bene, o niente parte

`git status` mostra 21 file **modificati** e 9 percorsi **non tracciati**. Fra i non tracciati c'è codice che i file modificati richiedono a runtime:

| Percorso non tracciato | Chi lo pretende |
|---|---|
| `app/Services/Fees/` | `RegistrationFeeService.php:57` e `AgentCodeFeeService.php:50` fanno `extends AbstractFeeService` |
| `app/Models/Contracts/` | `RegistrationFeePayment` e `AgentCodeFeePayment` fanno `implements FeePayment` |
| `resources/views/components/` | 11 viste shop usano `<x-shop.product-card>`, `<x-shop.styles>`, `<x-shop.price>`… |
| `public/assets/css/shop.css` | agganciato da `components/shop/styles.blade.php:24` |
| `public/fonts/` | `layouts/portal.blade.php:21,47,57` (Inter servito in proprio, non da CDN) |

`git commit -am` e `git add -u` prendono **solo i file modificati**. Se il push parte così:

- l'autoloader cerca `App\Services\Fees\AbstractFeeService` → *Class not found*;
- `EnsureRegistrationFeePaid` sta nella catena middleware del web, quindi il 500 **non è confinato alle quote**: cade ogni pagina autenticata, e il layout condiviso chiama `RegistrationFeeService::isDueFor()` comunque;
- lo shop, anche riuscendo a caricare, resta senza foglio di stile e senza card.

**Buona notizia verificata:** nessuno di questi percorsi è escluso dal `.gitignore` (`git check-ignore` non restituisce niente su tutti e cinque). Un `git add` esplicito basta.

```bash
git add app/Services/Fees app/Models/Contracts resources/views/components \
        public/assets/css public/fonts
git status --short   # non deve restare nessun ?? che serva a runtime
```

---

## 2. BLOCCANTE — Gli SQL di produzione non sono nel repo

`.gitignore:25` contiene `*.sql`. Risultato:

- `sql_produzione/` **è completamente vuota**;
- gli script veri stanno in due posti diversi e per lo più fuori da git: 21 file `migrazione_prod_*.sql` nella root, 40 in `database/sql/`, di cui **solo 7 tracciati** (aggiunti a suo tempo con `-f`);
- `database/sql/2026_09_03_verifica_rifirma.sql` — cioè l'ultimo, di oggi — non è tracciato.

In produzione le migration si applicano a mano su phpMyAdmin. Chi le applica sta lavorando da file che esistono solo su un PC, senza un elenco di cosa è già passato su quale server. Un file dimenticato = tabella mancante = 500 su quella funzione.

**Nota che rassicura:** le 4 migration più recenti hanno tutte il loro SQL pronto (`mlm_unique_source`, `registration_fee`, `agent_code_fee`, `rifirma_contratto`). Il problema non è che manchino: è che non sono nel repo e non c'è un registro.

Due dettagli: `2026_08_31_mlm_unique_source.sql` è l'unico che **non inserisce la riga nella tabella `migrations`** (gli altri hanno il blocco apposta) — innocuo perché la migration è idempotente, ma disallinea il conteggio. E `verifica_migrazioni_2026-08-24.sql` confronta **164** migration mentre nel codice oggi ce ne sono **185**: lo strumento di verifica è cieco proprio sulle ultime 21, cioè su shop, ordini, mandato e quote.

---

## 3. BLOCCANTE — Sospendere un'azienda oggi non ferma niente

`Admin/CompanyController.php:211` · `TransferBookingService.php:949` · `routes/web.php:398`

`suspendCompany()` scrive **solo** `companies.suspended_at`. Ma:

- il middleware `not.suspended` è agganciato a **un solo gruppo di rotte**, `/oauth/*` (riga 398). Il gruppo principale del portale (riga 416, ~380 rotte) non ce l'ha;
- il motore controlla `$fromAccount->company?->status !== 'active'` — e `status` la sospensione **non lo tocca**;
- gli `accounts` restano `active`.

L'admin sospende l'azienda per frode, l'utente non viene disconnesso, apre `/paga` e svuota il conto nel circuito. L'unica porta che si chiude è `/oauth/authorize`. `app/Models/Company.php:183` lo ammette già in un commento.

**Aggravante:** `CompanyController.php:230` e `:250` fanno `redirect()->route('admin.company.show')`, ma la rotta si chiama `admin.companies.show`. L'admin che sospende vede un **500 dopo che l'update è già passato**, e non sa se l'operazione è andata a buon fine.

**Correzione minima (30 min):** aggiungere `not.suspended` al gruppo di riga 416; in `suspendCompany()` scrivere anche `Account::where('company_id', …)->update(['status' => 'suspended'])`; correggere il nome della rotta nei due redirect.

---

## 4. BLOCCANTE — Login senza blocco dell'account, e remember-me forzato

`AuthController.php:190` · `routes/web.php:257` · `TwoFactorChallenge.php:37`

Tre cose che da sole passerebbero, insieme no:

1. `POST /login` ha solo `throttle:10,1`, che Laravel chiave **per IP**. Non c'è nessun `CredentialAttempts` sul login — c'è sul 2FA e sullo step-up, ma non qui. **Nessun account si blocca mai**, comunque lo si martelli.
2. `Auth::attempt($credentials, true)` — il secondo argomento è il flag *remember*, **cablato a `true`**. Ogni login emette un cookie di lunga durata che l'utente non ha chiesto.
3. `TwoFactorChallenge` lascia passare chi non ha né TOTP né passkey (riga 37), nonostante il commento dica "obbligatorio"; e chi ha **solo una passkey** passa anche entrando con la sola password, perché il middleware presume il login WebAuthn senza verificarlo.

Password spraying distribuito su 20 IP = 200 tentativi/minuto per account, all'infinito. Su un utente senza TOTP la password è l'unica barriera, e presa una volta il remember-me la rende persistente.

**Correzione minima:** `CredentialAttempts::hit()` in `login()` chiavato su `strtolower($email)`; `$request->boolean('remember')` al posto di `true`.

---

## 5. BLOCCANTE — Il primo giorno pieno partono 6.000 email in un colpo

`RemindRegistrationFees.php:60-106` · `routes/console.php:148`

Il freno `mail_max_per_hour` esiste in **un solo punto di tutta `app/`**: dentro `SendMonthlyStatements.php:58`. `quote:solleciti-iscrizione` gira ogni giorno alle 09:15, fa `->get()` su tutti i privati con quota non pagata e li notifica in ciclo. La notifica è `ShouldQueue` con `via() = ['mail','database']`: **2 job per utente, tutti disponibili nello stesso istante, zero `delay()`**.

Con ~3.000 privati sono **6.000 job alle 09:15**. Dopo un minuto e mezzo il server di posta comincia a rifiutare, `--tries=3` moltiplica per tre, e finisce come le 1.068 righe di `failed_jobs` di luglio: nessuno riceve il sollecito e il dominio si prende i rimbalzi. La guardia "una volta sola" fa sì che sia proprio la **prima** esecuzione dopo il lancio a scrivere a tutti insieme.

**Correzione minima — una riga, quella già scritta in `SendMonthlyStatements`:** nel `foreach`, `$notifica->delay(now()->addSeconds(floor($i * 3600 / config('kmoney.mail_max_per_hour'))))`.

> **Il resoconto mensile del 1° ottobre invece è a posto**: freno orario, `chunkById(200)`, ritardo crescente calcolato sulle email accodate, partenza a mezzanotte. Con ~3.000 destinatari a 150/ora l'ultimo riceve verso le 20:00. Il problema del 1° luglio si è spostato sui *solleciti*, che quel freno non l'hanno mai preso.

---

## 6. BLOCCANTE — `/invia` carica in RAM tutto lo storico del conto

`PortalController.php:1001` (pagina invio) e `:962` (hub pagamenti)

```php
$recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
    ->where('status','booked')->with('toAccount')->orderByDesc('booked_at')
    ->get()                       // ← TUTTO lo storico
    ->pluck('toAccount')->filter(...)->unique('id')->take(6);
```

Il `take(6)` avviene **in PHP, dopo** aver idratato ogni movimento uscente mai fatto dal conto. Non c'è `limit()` in SQL. A ~8 KB per modello: 2.000 movimenti = 16 MB; **10.000 movimenti ≈ 80 MB**, cioè il `memory_limit` 128 MB tipico di cPanel con sopra il resto della richiesta.

Si rompe con 500 su `/invia`, la pagina più usata del circuito, e si rompe **prima sui conti più attivi** — gli esercenti. Un negozio che fa 30 pagamenti al giorno ci arriva in un anno; un conto importato dal vecchio DB può esserci già oggi.

**Correzione minima:** `->orderByDesc('booked_at')->limit(50)->get()` prima del `pluck`. Cinquanta movimenti bastano largamente per trovare 6 destinatari distinti.

---

## 7. BLOCCANTE — Il worker si moltiplica quando c'è arretrato

`routes/console.php:99`

```php
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()->withoutOverlapping(2)
```

Il mutex scade dopo **2 minuti**. In regime normale `--stop-when-empty` esce in un secondo e va benissimo. Ma con arretrato — i 599 job di luglio, o i 6.000 del punto 5 — un worker gira per decine di minuti, al minuto 2 il mutex cade, e **da lì parte un worker nuovo ogni minuto**. Dopo venti minuti ci sono ~19 processi PHP concorrenti; il limite `nproc` di un cPanel condiviso sta fra 20 e 40. Superato, l'hosting risponde **508 su tutto il sito**, non solo sulla coda.

**Correzione minima:** `--max-time=50` sul comando e `withoutOverlapping(10)`.

---

## 8. Prima di riaccendere il cron: contare l'arretrato

Due job non hanno finestra temporale inferiore:

- `RunScheduledPayments.php:20` → `where('scheduled_at','<=',now())`, ogni minuto: dopo un fermo di N giorni **tutti** i pagamenti programmati arretrati si eseguono in un colpo solo, KY spostati davvero, più una notifica a testa;
- `ProcessDueInstallments.php:38` → `whereDate('due_date','<=',now())`, stesso schema per le rate.

**Da fare prima di riattivare lo scheduler**, non dopo:

```sql
SELECT COUNT(*) FROM scheduled_payments WHERE status='pending' AND scheduled_at <= NOW();
SELECT COUNT(*) FROM payment_plan_installments WHERE status='pending' AND due_date <= CURDATE();
```

Non a rischio: `RemindPaymentRequests` (finestre `whereBetween`), `CheckBalanceAlerts` (guardia `is_in_alert`), i solleciti quota (una volta sola per record).

---

## 9. Nessun backup del database

Nessun comando, nessuno script, nessun dump nel `.cpanel.yml`. `CHECKLIST_POST_LANCIO.md:12` lo dice già: *"oggi assente"*. Il giorno del lancio si applicano SQL a mano su phpMyAdmin sul database di un circuito monetario **senza rete sotto**.

**Minimo sindacale:** export completo da phpMyAdmin prima di ogni blocco SQL, scaricato in locale. Poi un cron cPanel con `mysqldump` giornaliero.

---

## 10. Soldi — cosa ho trovato

Il motore è disciplinato (vedi §14). Restano quattro cose vere.

### 10.1 [SERIO] La commissione diretta MLM ha la chiave senza l'agente

`MlmCommissionEngine.php:258`

```php
$idempotencyKey = "mlm_commission_direct_{$run->id}_{$clientId}";   // manca $agent->id
```

La chiave **indiretta**, riga 341, ce l'ha: `_{$run->id}_{$agent->id}_{$clientId}`. L'asimmetria conta perché `MlmTreeService.php:566` riassegna i clienti da un agente a un altro, e le righe di `mlm_commission_base_ledger` conservano lo snapshot `direct_agent_id` del momento del deposito.

Cliente C ricarica 500 € il 3 (riga con agente A), l'admin lo riassegna a B il 10, C ricarica 1.000 € il 20 (riga con agente B). Al run del mese il `foreach` scorre in ordine di `users.id`: il primo dei due crea la commissione, il secondo trova la chiave e fa `continue` — **salta del tutto**. Chi vince lo decide l'ordine degli id, non chi ha lavorato. Il run va in `completed` e non ripassa: la commissione è persa in silenzio, senza log e senza riga.

**Fix:** aggiungere `{$agent->id}` alla chiave. Una riga.

### 10.2 [SERIO] Rimborsare una ricarica non storna punti e base commissionabile

`KyCardController.php:554` (`awardMlmDepositPoints`) non ha nessuna controparte di storno. `mlm_commission_base_ledger` è scritto solo da `MlmPointsService::createCommissionBaseEntry:193`, e **nessuno cancella mai una riga**. Lo stato `refunded` di `KyCardPurchase` esiste ed è già usato come guardia nel webhook.

Cliente ricarica 1.000 €, apre una disputa, Stripe rimborsa, l'admin porta l'acquisto a `refunded`. Il 1° del mese il motore trova la riga base ancora valida e paga diretta e indiretta a tutta l'upline: **commissioni in KY veri su euro restituiti**, più la qualifica dell'agente gonfiata dai punti di un deposito annullato — che a sua volta alza la percentuale su *tutti* gli altri suoi clienti.

**Fix:** al passaggio a `refunded`, `valid_until` a ieri sulle righe con quel `source_transfer_id`; se il run del mese è già passato, stornare con `MlmWalletService::reverseBonusPayout` come già si fa per i bonus.

### 10.3 [SERIO] I sottoconti incassano su di sé ma spendono dal conto madre

`TransferBookingService.php:641` vs `:650`

In uscita il ramo `isSubAccount()` addebita correttamente il **conto madre**. In entrata non c'è nessuna guardia simmetrica: il credito e la riga di ledger finiscono sul **sottoconto** (riga 650, `$toAccount->available_balance + $amount`, senza redirezione al padre).

Un gestore di sottoconto con `payments.receive` apre una richiesta di incasso: `PortalController.php:1255` passa il **sottoconto** come `to_account_id`. Il cliente paga 800 KY, atterrano lì. Quando lo stesso gestore prova a spenderli, il motore addebita il madre — che magari è a zero e va sotto fido. **Denaro reale immobilizzato**, invisibile e inutilizzabile, mentre il conto principale va in scoperto. Tocca ogni azienda che usa i sottoconti per incassare in negozio.

Gli invarianti contabili non se ne accorgono: la somma globale resta zero e ogni saldo quadra col proprio ledger.

**Fix:** in `book()`, dopo `lockAccountPair`, se `$toAccount->isSubAccount()` accreditare il padre e mettere il sottoconto in `meta.sub_account_id` — simmetrico a quel che si fa già in uscita. Oppure, se la scelta è deliberata, **rifiutare** l'incasso su sottoconto.

### 10.4 [SERIO] `accounting:verify-integrity` non guarda su quale conto è finita la scrittura

`VerifyAccountingIntegrity.php:41-93`. I tre controlli sono: partita bilanciata per transfer, saldo = somma del ledger per conto, somma globale = 0. **Nessuno verifica che `ledger_entries.account_id` sia uno dei due conti del movimento.** Una scrittura sul conto sbagliato passa tutti e tre — ed è esattamente la forma del §10.3, che infatti non viene segnalato.

In più, il controllo 1 filtra con `t.reference NOT LIKE 'KM-MIG-%'`: in SQL a tre valori un `reference` NULL fa fallire il `NOT LIKE`, quindi **ogni transfer con reference nullo è escluso dal controllo più importante**.

**Fix:** un quarto controllo su `le.account_id NOT IN (t.from_account_id, t.to_account_id)`, e `COALESCE(t.reference,'')` nel filtro.

### 10.5 [DOPO IL LANCIO] Tre code minori

- **Rimborso senza finestra né controllo fido** (`TransferBookingService.php:425`): un esercente rimborsa un pagamento di due anni fa a saldo zero e va sotto il proprio massimale — `assertTransferWithinLimits()` non viene chiamato.
- **`adminRetryCredit` sulle ricariche** (`KyCardController.php:526`): è l'unico dei quattro bottoni di ripescaggio che accredita KY **senza riverificare l'incasso** presso Stripe/PayPal. Gli altri tre raccolgono la prova. Serve un admin, quindi non è sfruttabile dall'esterno.
- **Collisione di idempotency key** (`TransferBookingService.php:46`): l'indice UNIQUE fa il suo mestiere e non si duplica un centesimo, ma la `QueryException` viene rilanciata come `RuntimeException` e l'utente si vede a schermo `SQLSTATE[23000]: Duplicate entry … for key 'transfers_idempotency_key_unique'`.

---

## 11. Sicurezza — il resto

Oltre a §3 e §4:

| | Cosa | Dove |
|---|---|---|
| SERIO | **`/forgot-password` senza throttle** risponde *"Nessun account trovato con questa email"*: da fuori si ricava l'elenco completo dei membri del circuito, che alimenta §4 e il phishing mirato. | `routes/web.php:263` |
| SERIO | **Il PIN di pagamento si azzera senza conoscerlo**: `setPin()`/`removePin()` non chiedono il PIN attuale né lo step-up, e non notificano. Il PIN in sé è fatto bene (bcrypt, 5 tentativi/15 min): è aggirabile senza attaccarlo. | `SendPaymentController.php:426`, `routes/web.php:669` |
| SERIO | **L'IBAN aziendale si riscrive senza step-up** dal portale: chi ha la sessione di un venditore dirotta tutti i bonifici degli ordini successivi. Il commento in `EnsureCanAccessBackoffice.php:56` spiega esattamente questo rischio — per la versione admin, che infatti è protetta. | `PaymentGatewayController.php:51`, `routes/web.php:601` |
| SERIO | **`/invia/destinatario/{id}` enumera la rubrica**: id sequenziale, nessun rate limit (la gemella `search()` ce l'ha), risponde con nome, numero di conto e tipo. Un ciclo da 1 a 50.000 restituisce tutti i conti attivi del circuito, privati compresi. | `SendPaymentController.php:95` |
| SERIO | **Confermato: la visibilità del menu è cosmetica.** `MenuVisibilityService::isVisible()` ha **un solo punto di consumo in tutto il codice**, il layout Blade. Nessun middleware la interroga: nascosta la voce, l'URL resta 200. Non c'è perdita di dati altrui (i controller sono scopati sul conto proprio), ma chi usa quel pannello per ragioni di conformità sta contando su niente. | `MenuVisibilityService.php`, `portal.blade.php:1528` |
| DOPO | Manca `X-Content-Type-Options: nosniff`, e `Admin/BrandingController.php:31` accetta **SVG** sul disco pubblico: con `'unsafe-inline'` ancora in `script-src`, un SVG con `<script>` esegue nell'origine. Scrivibile solo da `backoffice.full`, quindi impatto basso — ma togliere `svg` dai mime costa nulla. | `ContentSecurityPolicy.php:100` |
| DOPO | Il `.env` di sviluppo ha `APP_DEBUG=true` **insieme a chiavi Stripe live** (`pk_live_`, `rk_live_`). Il file è correttamente fuori da git; è la macchina di sviluppo ad avere più superficie del necessario. | `.env` |

---

## 12. Carico — cosa cede e a che numero

Oltre a §6 e §7:

- **`transfers` non ha nessun indice che cominci per `status` o `booked_at`.** Tutti i sei indici presenti sono prefissati da un id di conto, quindi ogni query di circuito — cioè quelle senza conto — è una scansione completa: `AdminController.php:639,648,656,665,697` (volume mese, mese precedente, media, oggi, grafico 6 mesi) sono ~12 scansioni per caricamento della dashboard admin, non cachata. **Indice che serve: `transfers(status, booked_at)`.** Servono anche `transfers(kind, status)` per il filtro tipo movimento, `failed_jobs(failed_at)` per `/health`, e `jobs(queue, reserved_at, available_at)` per il pop della coda.
  **Da verificare sul server prima del lancio:** non esiste alcun `.sql` di produzione per `2026_05_26_100000_add_mysql_performance_indexes` né per `2026_06_12_200000_add_performance_indexes_to_transfers`. Se un `SHOW CREATE TABLE transfers` su phpMyAdmin non mostra quei sei indici, questo punto sale a bloccante.
- **`/aziende` esegue la query completa due volte e ordina con `RAND()`** (`PortalController.php:1981` e `:1985`): la prima riga carica **ogni** azienda che passa i filtri con 4 subquery correlate solo per calcolare quattro numeri di riepilogo; la seconda rifà tutto paginato con un filesort completo non cachabile. Con ~1.068 aziende sono ~2.100 righe idratate per caricamento, su una pagina linkata dal menu principale.
- **Il checkout aspetta 2 handshake SMTP per venditore, dentro la richiesta HTTP.** `OrderPlacedNotification` e `NewMarketplaceOrderNotification` non sono `ShouldQueue` (31 notifiche su 74 sono sincrone). Carrello con 3 venditori = 6 invii sincroni = **3-18 secondi** di rotella dopo che i soldi si sono già mossi, e l'utente riclicca. Stessa cosa su `CashbackReceivedNotification`, `VerifyEmailNotification`, `ResetPasswordNotification`. **Fix: `implements ShouldQueue`, una riga per classe.**
- **`/health` non si accorge che la coda è ferma**: guarda solo `failed_jobs` degli ultimi 5 minuti, mai la profondità di `jobs` né l'età del più vecchio. I 599 job accumulati da luglio avrebbero dato `status: ok` per tre mesi — se il worker è fermo i job non falliscono, restano lì. Tre righe per aggiungere il conteggio.
- **Produzione gira senza `config:cache` e senza `route:cache`**: `.cpanel.yml:76` li cancella e non può rigenerarli (il deploy non lancia artisan). **634 rotte** ricostruite e 16 file di config riletti a ogni singola richiesta: 30-60 ms e 4-6 MB di picco prima ancora di toccare il database. Una riga nel cron di cPanel accanto a `schedule:run` lo risolve.
- **Nessuna pulizia programmata di niente**: zero `model:prune`, zero `queue:prune-failed`. Ogni acquisto shop scrive ~15-18 righe permanenti fra `transfers`, `ledger_entries`, `audit_logs` e `notifications`. A 500 acquisti al giorno sono **~3,3 milioni di righe l'anno** su MySQL condiviso senza partizionamento. Prima del lancio basta una voce: `queue:prune-failed --hours=336` settimanale, per non trascinarsi le 1.068 righe di luglio.
- **Sei `COUNT` scritti dentro il Blade del layout** (`portal.blade.php:1601,1607,1615,1632,1998,2177`), eseguiti a ogni render per gli utenti che vedono quelle voci, nessuno cachato. Sono badge numerici: `Cache::remember(…, 60, …)` e non se ne accorge nessuno.
- `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` su `database` costano 3-4 query e 2 scritture per richiesta autenticata. `AGENTS.md:33` dice che Redis c'è: sono due righe di `.env`. Non è un bloccante, è il modo più economico di guadagnare margine.

**Query per pagina, contate sul codice:** dashboard ~40, `/movimenti` ~28, `/shop` ~22, `/ordini` ~22, `/aziende` ~28 (ma con due passate complete), `/admin` ~25 di cui ~12 scansioni complete. **Le liste non hanno N+1 sulle righe**: l'eager loading è fatto bene quasi ovunque, e passare da 15 a 50 elementi per pagina non cambia il numero di query.

---

## 13. Deploy — due cose da sapere prima di toccare il pulsante

- **`DEPLOY.md` descrive un'infrastruttura che non esiste.** Tutte le 354 righe parlano di Forge, Envoyer, Redis, Supervisor e nginx; la checklist go-live a `:294-313` chiede `php artisan migrate --force`, `storage:link`, `config:cache`, `supervisorctl status`. Il deploy reale è cPanel condiviso, senza php né composer nei task, con le migration a mano su phpMyAdmin. **Chi segue quella checklist il giorno del lancio esegue comandi che non esistono e conclude che il deploy è fatto quando non lo è.** Vanno messe sei righe in testa al file con la procedura vera.
- **Non c'è modalità manutenzione né sola-lettura.** `resources/views/errors/` contiene solo `429.blade.php`: `php artisan down` mostrerebbe la pagina grezza di Laravel. E su kmoney.it il file servito è `public_html/index.php`, personalizzato e mai sovrascritto dal deploy: **non è nel repo, quindi non è verificabile da qui se contenga il controllo di manutenzione**. Se non ce l'ha, `artisan down` non ha alcun effetto. Da leggere sul server. Se il giorno del lancio i saldi fanno qualcosa di strano, oggi non c'è modo di fermare i pagamenti lasciando il portale consultabile: o è tutto su, o è tutto giù.

**Il build Vite non è un problema.** È fermo al 31/07 con 96 blade cambiate dopo, ma il CSS compilato è di **3.961 byte** e contiene solo il preflight Tailwind più `.visible` e `.relative`: Tailwind di fatto non è usato, lo stile vero sta nel `<style>` inline del layout e in `shop.css`. Il JS è allineato. Un `npm run build` prima del push è comunque igiene, non un'emergenza.

---

## 14. Cosa ho verificato ed è solido

Serve saperlo quanto il resto:

- **Il motore dei pagamenti.** `lockForUpdate` sulle coppie di conti sempre in ordine crescente di id (difesa corretta contro il deadlock A→B / B→A), 3 retry sul deadlock, `DB::transaction`, idempotency key obbligatoria, partita doppia sempre bilanciata. Importi in `bigInteger` ovunque: **nessun float in nessun punto del percorso del denaro**, e `ky_to_cents` arrotonda invece di troncare. Importo ≤ 0 rifiutato, self-transfer rifiutato due volte.
- **Il refactor delle quote è corretto.** Ho confrontato l'insieme dei metodi pubblici di `git show HEAD:` con quelli attuali più quelli ereditati da `AbstractFeeService`: **zero metodi mancanti** su entrambi i servizi, nessun chiamante che punta nel vuoto. Le due differenze vere (KY emessi in euro solo per i 30, `restoreAfterAgentFeeCancelled` dopo l'annullo dei 480) sono correttamente isolate in `emitsKyInEuro`, `settleEuroPayment()` e `afterCancelled()`.
- **Webhook Stripe.** Firma verificata con `Webhook::constructEvent`, 400 su firma invalida. I quattro incassi che condividono l'endpoint controllano ciascuno lo stato della propria riga **e** riverificano presso Stripe importo e destinazione con `sessionMatches()` prima di creare moneta. La corsa fra webhook e pagina di ritorno è gestita in tutti e tre i posti che contano: il bug del 31/08 non è tornato.
- **Punti MLM e base commissionabile: tripla difesa** contro il doppione (guardia sulla sorgente, `ignoringDuplicateSource`, indice UNIQUE dal 31/08). Le commissioni **indirette** hanno la chiave completa.
- **Cashback: non può coniare moneta.** Merchant di sistema escluso, merchant privato escluso, self escluso, importo tagliato al valore del pagamento e alla capienza del venditore entro fido, ricorsione bloccata.
- **Rimborsi parziali:** il residuo è ricalcolato sotto `lockForUpdate` sommando i rimborsi già `booked` — richieste concorrenti non possono superare l'importo originale. Solo chi ha incassato può rimborsare.
- **Rotte admin: 233 su 233 hanno il middleware `backoffice`**, zero eccezioni. La preoccupazione della sessione del 01/09 sul backoffice **non è più attuale**. `EnsureCanAccessBackoffice` è default-deny per gli operatori ristretti, con le rotte dei gateway escluse di proposito e con la motivazione scritta.
- **Nessun IDOR trovato**, e non per mancanza di ricerca: ordini, vendite, resi, indirizzi, carrello, prodotti, varianti, sottoconti, piani rateali, compensazioni, richieste testuali, webhook, token API, alert saldo, card NFC, mandati, pagamenti programmati, documenti KYC — tutti scopati su conto/azienda/utente. `?company_id=` non è manipolabile.
- **Zero mass assignment** (nessun `$guarded = []` in 84 modelli, nessun `$request->all()` verso `create`/`fill`), **zero SQL injection** (ogni `whereRaw` con valori bindati, `$sortField` su whitelist), **zero XSS** (ogni `{!! !!}` passa da `sanitize_html()`, un sanitizer DOM ad allow-list vero).
- **OAuth2 scritto in casa ma fatto bene:** PKCE S256 obbligatorio, codici e token salvati solo come SHA-256, `hash_equals` ovunque, `chain_uuid` che revoca la catena al riuso.
- **`payments:run-scheduled` gira come comando diretto e non come job in coda**: i pagamenti programmati sono l'unica cosa che continua a funzionare anche a worker fermo. Scelta giusta.
- **Gli asset statici**: 652 KB in tutta `public/`, nessuna immagine da ottimizzare.

---

## 15. L'ordine in cui le farei

**Giorno 1 — quello che senza non si parte** (~mezza giornata)

1. `git add` esplicito dei 5 percorsi non tracciati, `git status` pulito, poi push (§1).
2. Le due correzioni da 10 minuti che riportano la suite a 1760/1760 (§0).
3. `not.suspended` sul gruppo del portale + `status` degli account nella sospensione + i due redirect sbagliati (§3).
4. Dump completo del database da phpMyAdmin, scaricato in locale (§9).

**Giorno 2 — quello che rompe il primo giorno vero** (~mezza giornata)

5. Il `delay()` sui solleciti quota (§5) e `--max-time=50` + `withoutOverlapping(10)` sul worker (§7).
6. Il `limit(50)` su `/invia` (§6).
7. `CredentialAttempts` sul login + `remember` non forzato (§4).
8. `SHOW CREATE TABLE transfers` sul server: se mancano gli indici, applicarli ora (§12).

**Giorno 3 — verifica sul server, non nel codice**

9. `.env` di produzione a occhio: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_LEVEL=error`, `LOG_STACK=daily`, `HEALTH_CHECK_TOKEN` valorizzato. Nota: `config/session.php:172` non ha default sul flag secure — chiave assente significa cookie di sessione anche in chiaro.
10. Leggere `public_html/index.php` sul server e confermare che contenga il controllo di manutenzione; aggiungere `errors/503.blade.php` (§13).
11. Rigenerare `verifica_migrazioni` sulle 185 migration attuali e farlo girare su **entrambi** i server; `git add -f` degli SQL di agosto/settembre con una nota "applicato dove e quando" (§2).
12. Contare l'arretrato di pagamenti programmati e rate **prima** di riattivare il cron; confermare la riga `schedule:run` su cPanel e puntarci un monitor esterno su `/health` con il bearer token (§8).

**Subito dopo il lancio, in ordine di rischio**

13. La chiave MLM diretta (§10.1) — una riga, ma il primo run del mese la usa.
14. Lo storno dei punti sulle ricariche rimborsate (§10.2).
15. I sottoconti in entrata (§10.3) e il quarto controllo di integrità che lo avrebbe visto (§10.4).
16. Step-up su PIN e IBAN, throttle su `/forgot-password`, rate limit su `/invia/destinatario` (§11).
17. `ShouldQueue` sulle cinque notifiche del checkout (§12).

---

## 16. Come è stata fatta questa analisi

- Quattro letture indipendenti del codice (deploy/config, motore soldi, sicurezza, carico), ognuna con l'obbligo di citare `file:riga` e di costruire lo scenario concreto invece di segnalare il rischio teorico.
- **La suite eseguita davvero** su una copia del sorgente con dentro il lavoro non committato: 1755 verdi, 5 rossi, tutti e cinque aperti e spiegati.
- Verifica diretta dei punti più pesanti: `git check-ignore` sui percorsi non tracciati, il `manifest.json` e il peso del CSS compilato, il ramo sottoconto in `TransferBookingService`, le due chiavi di idempotenza MLM a confronto, la registrazione di `not.suspended`, il commento CSS che fa cadere i tre test.
- Restano **fuori dalla portata di questa analisi** tre cose che si vedono solo sul server e che ho elencato nel giorno 3: il contenuto del `.env` di produzione, `public_html/index.php`, e quali indici e quali SQL siano davvero già applicati sui due database.
