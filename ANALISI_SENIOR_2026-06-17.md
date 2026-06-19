# Analisi Senior — kmoney-app
**Data:** 2026-06-17 · **Revisore:** Claude (Cowork) · **Approccio:** revisione del codice attuale, non dei report precedenti

---

## Stato avanzamento — aggiornato 2026-06-18 (si continua domani)

Lavorato a coppie io+Laura in più sessioni. Riepilogo di cosa è chiuso e cosa resta.

| # | Intervento | Stato |
|---|---|---|
| 1 | **God controller** — split `AdminController` | ✅ **FATTO** — 2.773 → 854 righe (−69%); 10 controller in `Admin/*` (Contract, Branding, Webhook, Audit, Emission, Role, Account, CreditLimit, Company, User) + 2 trait (`AuthorizesBackoffice`, `HandlesMovementFilters`) |
| 4 | **Larastan** (analisi statica) | ✅ **FATTO** — `larastan ^3.0`, `phpstan.neon.dist` livello 5, baseline con **581 errori legacy congelati**, job CI `static-analysis` |
| 3a | **CI su MySQL** | ✅ **FATTO** — job `test-mysql` (MySQL 8 service) oltre a SQLite |
| 3b | **Unit test sul motore** | ✅ **FATTO** — 12 test: `TransactionFee::calculate` (8) + limiti giornaliero/mensile/per-movimento + invariante circuito chiuso (4) |
| min | **Catch vuoti** | ✅ **FATTO** — 4 `catch` silenziosi (EmailChange ×3, SendBroadcastMessageJob) ora con `Log::warning` |
| min | **Hardening `.env` / debug** | ✅ **FATTO** — warning in `.env.example` + check `/health` `config_debug` (segnala APP_DEBUG attivo in prod) |
| min | **Backup DB prod** | ✅ **FATTO** — cron cPanel notturno `mysqldump | gzip`, retention 30gg (ricordare apici singoli sulla password) |

### 5 bug reali corretti lungo il percorso
1. `ApiTokenNewIpNotification.php:33` — virgolette doppie interne non escapate → **fatal error** ad ogni notifica "token usato da nuovo IP" (trovato da Larastan).
2. `ReconcileBalances.php` — opzione `--verbose` in collisione con quella globale di Symfony → comando **non avviabile**, rinominata `--details` (trovato da Larastan).
3. Vincolo `NOT NULL` su `transaction_fees.min_fee` emerso dai test (insert con `null` falliva).
4. Rotte morte `/admin/support` → `supportMessages`/`resolveSupport`: metodi **inesistenti** in qualsiasi controller (pre-esistenti). **DA SISTEMARE**: implementare i 2 metodi o rimuovere le 2 rotte.

### Da fare DOMANI
- **#2 FormRequest** — pattern stabilito con `StoreSubAccountLimitRequest` (1 fatta); restano ~43 controller con `validate()` inline, da estrarre a piccoli lotti (un test per ogni form critico). Attenzione all'ordine auth/validazione: dove l'autorizzazione usa un parametro route-bound va in `authorize()` (ordine preservato); dove dipende dal contesto risolto nel corpo (es. `SendPaymentController::execute`) serve più cura.
- **Split `PortalController`** (1.848 righe) — stesso metodo del God controller admin.
- **Fix rotte morte `/admin/support`** (vedi sopra).
- **Ridurre la baseline Larastan** (581 → ...) un po' alla volta.

## Aggiornamento 2026-06-19 — #2 FormRequest, lotto 1 (CRUD admin/config)

Estratte **16 FormRequest** (oltre alla `StoreSubAccountLimitRequest` gia' presente) da **8 controller** a basso rischio. Rimosse **15** `validate()` inline (102 -> 87).

| Controller | FormRequest |
|---|---|
| CashbackRuleController | Store/UpdateCashbackRuleRequest |
| AdminSectorController | Store/UpdateSectorRequest |
| BeneficiaryController | StoreBeneficiaryRequest (solo `store`; `update`/`destroy` lasciate inline: auth dipende dall'account risolto nel corpo) |
| AnnouncementController | Store/UpdateAnnouncementRequest + AdminUpdateAnnouncementStatusRequest (`reply` lasciata inline: i pre-check tornano redirect, non 403) |
| Admin/RoleController | Store/UpdateRoleRequest |
| Admin/ContractController | UpdateContractText/UpdateContractSettingsRequest |
| Admin/AdminNfcCardController | Store/BulkStoreNfcCardRequest (`markShipped`/`revoke` lasciate inline: auth basata sullo stato della card + messaggi 403 custom) |
| Admin/AdminMenuVisibilityController | Store/DestroyMenuVisibilityRequest |

### Finding di sicurezza chiuso nello stesso lotto
Le rotte admin di **cashback, settori, card NFC e visibilita' menu** stavano nel gruppo portale `['auth','verified','twofactor','onboarding','contract']` **senza guardia di backoffice**, e quei 4 controller non avevano alcun check inline. In pratica un utente onboardato e con contratto firmato poteva — via richiesta diretta — creare/modificare regole cashback (payout in KY), gestire settori, cambiare la visibilita' menu altrui ed emettere/revocare carte NFC, oltre a leggere le relative pagine admin (GET).

**Fix:** nuovo middleware `EnsureCanAccessBackoffice` (alias `backoffice`), applicato ai 4 controller via `HasMiddleware` (copre GET + POST + metodi senza form). Difesa in profondita': anche le nuove FormRequest hanno `authorize() = canAccessBackoffice()`. Nuovo test `BackofficeAccessGuardTest` (403 per non-admin su GET/POST dei 4 controller + admin passa + regole di validazione attive).

> **Nota:** suite e Larastan NON eseguibili nell'ambiente Cowork (manca PHP). Verifica via `composer test` in locale e/o CI sul push.

### Bug preesistente stanato dai test (5°)
`Admin/UserController` usava `self::USERS_PER_PAGE` ma la costante era rimasta nel vecchio `AdminController` (non portata nello split) → `/admin/users` con filtro/per_page andava in **500 fatale** in produzione. Fix: aggiunta `private const USERS_PER_PAGE = 25;` in `UserController`. Coperto da `AdminBackofficeTest::test_superadmin_can_filter_users_by_role`.

### Bug preesistente (6°): view admin/user-show.blade.php rotta
Il refactor UX "form utente sub-card" (`4bb59cd`/`85dec28`) aveva lasciato `@if`/`@foreach` sbilanciati — il form "Modifica utente" era finito DENTRO il `@foreach` delle sessioni attive, sovrascrivendone il corpo e i `@endforeach`/`@endif` → `/admin/users/{id}` in **500 in produzione**. Inoltre **119 attributi con virgolette tipografiche** (`class="..."` → smart quotes) che rompevano l'HTML del form. Fix: ripristinato il blocco "sessioni attive" dalla versione sana, richiuse le direttive, convertite le smart quotes in ASCII. Nesting verificato (stack vuoto). Inoltre asserzione **stale** nel test `AdminBackofficeTest::...max_balance_from_user_page`: cercava "Saldo massimo conto principale", label rinominato in "Saldo massimo (KY)" nel commit `8199c7c` → aggiornata la stringa nel test (non la UX). Commit separato dal lotto FormRequest.

### Bug preesistente (7°): filteredChannels() inesistente in 3 notifiche
`RespectsNotificationPreferences` espone `resolveChannels($notifiable, $eventKey, $default, $allowed)`, ma 3 notifiche chiamavano ancora il vecchio nome `filteredChannels()` (rimosso): `KycStatusChangedNotification`, `PaymentRequestExpiredForCreditorNotification`, `PaymentRequestExpiringNotification` → fatal error all'invio. Conseguenza in produzione: **approva/rifiuta KYC va in 500** (e le notifiche di scadenza richiesta crashano quando parte `ExpirePaymentRequests`). Le altre 18 notifiche usavano già `resolveChannels`. Fix: convertite le 3 con eventKey dedicati (kyc_status / payment_request_expired / payment_request_expiring) + canali default invariati. Emerso dalla suite completa (`KycControllerTest`). Lezione: dopo un rename di metodo condiviso, fare grep dell'intero progetto, non fidarsi dei soli file toccati.

### Lotto 2 (form che muovono denaro) — parziale
Estratte 3 FormRequest: `StoreTextPaymentRequestRequest`, `StorePaymentPlanRequest`, `StoreNettingProposalRequest`. `authorize()` replica il solo `abort_if(canAccessBackoffice, 403)` di `resolveCurrentContext` (403 backoffice prima della validazione, ordine preservato); business check inline. validate() 87->84.
Aggiunte poi 3 FormRequest dai due controller piu' delicati: `SetPaymentPinRequest` (SendPayment::setPin), `IdentifyNfcCardRequest` e `CreateNfcCardPaymentRequest` (NfcCardPayment::identify/createRequest) — in tutti la validate era gia' la prima istruzione, estrazione a iso-comportamento.
**Lasciati inline (non estraibili senza cambiare comportamento):** Code/Sonic `store` (guardie pre-validate = redirect+flash, non 403); Scheduled `store` (ramo `is_recurring` -> regole diverse); **SendPayment::execute** (redirect backoffice + abort_unless su conto attivo/canSendFromAccount + logica PIN, tutto prima/intorno alla validate); **NfcCardPayment::authorize** (abort di stato sessione/card/PIN prima della validate). Totale lotto 2: 6 FormRequest. validate() inline complessivo 102 -> 81.
Microscostamento accettato su PaymentPlan/Netting: il 403 su `company_id` estraneo (in resolveCurrentContext) ora gira dopo la validazione (difesa in profondita', nessun test lo esercita).

### Restano per i prossimi lotti #2
Form che muovono denaro (SendPayment, CodePayment, Sonic, NfcCardPayment, ScheduledPayment, PaymentPlan, Netting, TextPaymentRequest), area auth (TwoFactor, Kyc, Onboarding, EmailChange, WebAuthn, StepUp), API e i restanti admin. ~87 `validate()` inline ancora da valutare.

> **Nota tecnica per le prossime sessioni:** sul mount NTFS (Cowork → Windows) gli strumenti Edit/Write troncano i file (anche piccoli) lasciando byte NUL in coda. Usare scritture via Python (binario), e dopo ogni modifica verificare: parentesi graffe bilanciate, file che termina con `}`/`});`, e 0 byte NUL.

---

## Premessa: il backlog precedente è quasi tutto chiuso

Verificato sul codice attuale. Risultano già risolti i punti aperti di `ANALISI_QUALITA.md` (2026-06-04) e `PROPOSTE_MIGLIORAMENTO_2026-06-12.md`:

| Item | Stato |
|---|---|
| 4 bug P0 finanziari (`bookFee`, `confirmRequest`, idempotency, rimborso-di-rimborso) | ✅ risolti |
| Anti-frode su tutti i metodi che spostano fondi | ✅ `assertNotAnomalousActivity()` presente |
| Header sicurezza (HSTS, Referrer-Policy, Permissions-Policy) | ✅ in `ContentSecurityPolicy.php` |
| `CheckBalanceAlerts` → `available_balance` | ✅ corretto |
| Ricerca destinatari (min 3 char, match esatto, no enumerazione) | ✅ `SendPaymentController::search` |
| Limite giornaliero/mensile per `from_account_id` (non per utente) | ✅ corretto |
| Fee + cashback fuori dalla transazione principale | ✅ in `DB::afterCommit()` |
| Job notturno quadratura contabile | ✅ `accounting:verify-integrity` @ 02:00 |
| Indici `(from_account_id, status, booked_at)`, `related/reversed_transfer_id` | ✅ migration 2026_06_12 |
| File `.sql`/`.zip` fuori da git | ✅ 0 file tracciati, `.gitignore` aggiornato |
| CI con `php artisan test` | ✅ `.github/workflows/ci.yml` |

Da qui in avanti: **solo ciò che è ancora aperto o nuovo.**

---

## Problemi strutturali (priorità alta)

### 1. God controller — rischio #1 di manutenibilità

- `AdminController` → **2.773 righe, 56 metodi pubblici**
- `PortalController` → **1.848 righe, 35 metodi pubblici**

Sono diventati il contenitore di tutto. Ogni modifica è ad alto rischio di regressione e di fatto non revisionabile. Su una piattaforma che muove denaro è un moltiplicatore di rischio.

**Azione:** spezzare per dominio in controller a responsabilità singola, es.
`Admin/KycController`, `Admin/CreditLimitController`, `Admin/EmissionController`, `Admin/CompanyController`,
`Portal/DashboardController`, `Portal/MovementsController`, `Portal/PaymentController`.
Refactor a iso-comportamento, una sezione alla volta, con i test esistenti come rete.

### 2. Zero FormRequest, validazione inline in 44 controller

Nessuna classe in `app/Http/Requests`; 44 controller con `$request->validate(...)` inline. Regole e messaggi duplicati, logica mischiata alla validazione.

**Azione:** estrarre le regole in classi `FormRequest` (centralizza i messaggi italiani, sposta `authorize()` nel posto giusto, alleggerisce i controller). Complemento naturale del punto 1.

### 3. CI testa solo SQLite in-memory, ma la produzione è MySQL

È la divergenza più pericolosa: il bug storico delle `ledger_entries NOT NULL` è proprio il tipo di errore che SQLite perdona e MySQL no. Inoltre: **50 test Feature ma 1 solo Unit test**.

**Azione:**
- aggiungere alla matrice CI un job con `services: mysql` per girare la suite anche su MySQL;
- test **unitari** mirati sul motore finanziario (`TransferBookingService`: calcolo fee, limiti giornalieri/mensili, invarianti di quadratura), non solo end-to-end.

### 4. Nessuna analisi statica

C'è Pint (solo formattazione), manca **Larastan/PHPStan**. A costo quasi zero intercetta gli errori che vi hanno già morso: campi fuori da `$fillable`, null-safety sui saldi, tipi di ritorno.

**Azione:** `composer require --dev larastan/larastan`, partire a livello 5-6, aggiungere step in CI.

---

## Robustezza e sicurezza (priorità media)

### 5. `catch (\Throwable) {}` vuoti — 4 occorrenze

`EmailChangeController.php` (righe 86, 101, 159) e `SendBroadcastMessageJob.php` (riga 47). Inghiottono le eccezioni in silenzio: i fallimenti di cambio email o broadcast non lasciano traccia.

**Azione:** almeno `Log::warning(...)` dentro ogni catch (con contesto: user id, operazione).

### 6. `APP_DEBUG=true` in `.env.example`

Se copiato in produzione espone stack trace e configurazione.

**Azione:** default `APP_ENV=production` / `APP_DEBUG=false` nell'example (o commento esplicito), e check in `/health` che segnali "debug attivo in produzione".

---

## Processo e deploy (priorità media)

### 7. Drift dello schema (deploy SQL a mano)

95 migration che in produzione non girano mai: lo schema reale è ricostruito a mano via phpMyAdmin → prima o poi una colonna esisterà in dev e non in prod.

**Azione:**
- comando artisan che **generi SQL idempotente** dalle migration pendenti (basta scriverlo a mano);
- endpoint/admin che confronti schema atteso vs reale e segnali le differenze;
- esporre `accounting:verify-integrity` anche come **pulsante in area admin** (in prod non c'è CLI).

### 8. Backup automatico DB produzione

Ancora aperto. Per un circuito monetario è non negoziabile.

**Azione:** dump giornaliero MySQL, retention 30 giorni, via cron nativo cPanel (non serve Artisan).

### 9. Lock sul conto sistema = collo di bottiglia globale

Già documentato nei commenti e consapevolmente rimandato: ogni fee/cashback locka la riga della Cassa. OK ai volumi attuali.

**Azione (quando i volumi crescono):** accodare fee e cashback in un job batch (es. ogni 10s) invece di scriverli in linea.

---

## Ordine d'intervento suggerito

1. **Settimana 1-2 (strutturale, massimo ROI):** spezzare i due God controller (1), introdurre FormRequest (2).
2. **Settimana 2-3 (qualità):** Larastan in CI (4), job MySQL in CI + unit test sul motore (3).
3. **Continuo:** log nei catch vuoti (5), `.env.example` hardening (6), backup prod (8), generatore SQL + verifica schema (7).
4. **Quando i volumi crescono:** batch fee/cashback (9).

> La fase "bug che fanno male ai saldi" è chiusa bene. Il prossimo investimento ad alto ritorno **non è funzionale ma strutturale**: God controller, FormRequest, Larastan, CI su MySQL. È ciò che rende sostenibile tutto il lavoro già fatto.
