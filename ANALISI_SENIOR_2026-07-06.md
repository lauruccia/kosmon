# Analisi Senior KMoney — 06/07/2026

Audit di sicurezza, coerenza e qualità del codebase. Verifica anche lo stato delle criticità aperte dall'analisi del 23/06.

---

## Stato criticità del 23/06

| Criticità | Stato |
|---|---|
| `trustProxies('*')` | ✅ Risolto — `trusted_proxies()` con default a range privati + `TRUSTED_PROXIES` in .env (ma vedi P1-3) |
| ApiTokenAuth write-per-request | ✅ Risolto — `last_used_at` aggiornato solo se più vecchio di 60s |
| Alert su invariante saldi | ✅ Risolto — `verify-integrity --quick` orario, check completo notturno, dead-man's switch in /health, monitor contesa lock ogni 15 min |
| phpstan baseline | 🟡 In calo: 383 → 190 messaggi |
| CSP `unsafe-inline` su script-src | ❌ Ancora aperto |
| Split PortalController (1848 righe) | ❌ Non fatto — ora 1883 righe (cresciuto). AdminController ridotto 2773 → 1246 ma non completato |

---

## P1 — Da correggere presto

### 1. Deadlock potenziale nel motore pagamenti
`TransferBookingService` acquisisce i lock sempre nell'ordine `from` → `to` (righe 51-52, 102-103, 159-160, 329-330). Due transfer concorrenti incrociati (A→B e B→A) possono andare in deadlock MySQL (errore 1213). Le 7 `DB::transaction()` non hanno parametro `attempts` né gestione retry: in caso di deadlock l'utente vede un errore 500 e il pagamento fallisce.

**Fix**: lockare gli account in ordine deterministico per `id` (prima il minore), e/o `DB::transaction(fn, 3)` per il retry automatico. Su SQLite dev il problema non si manifesta — solo in prod MySQL sotto carico.

### 2. `trusted_proxies()` legge `env()` a runtime
Con `php artisan config:cache` in produzione Laravel non carica `.env`, quindi `env('TRUSTED_PROXIES')` restituisce `null` e scatta silenziosamente il fallback ai soli range privati. Se il sito sta dietro Cloudflare/proxy con IP pubblici: IP sbagliati negli audit log e nei log di login, e rate limiting per-IP che colpisce tutti gli utenti dietro lo stesso proxy.

**Fix**: spostare il valore in `config/app.php` (o file dedicato) e leggerlo con `config()`. Verificare se in prod il config è attualmente cachato.

### 3. CSP `script-src 'unsafe-inline'` (aperto dal 23/06)
Annulla gran parte della protezione XSS della CSP. Le view Blade sono piene di script inline, quindi il fix è un refactor progressivo: nonce generato per request + `nonce="{{ $cspNonce }}"` sugli script. Da pianificare, non urgente in un giorno.

---

## P2 — Da pianificare

### 4. HTML contratto renderizzato senza sanitizzazione
`contract_html_snapshot` e `$contractHtml` sono stampati con `{!! !!}` in 5 view (firma contratto, contratto agente MLM, snapshot admin). L'input è solo admin (contract-settings), ma non c'è alcun purifier nel progetto: un account admin compromesso ottiene stored XSS su ogni utente che firma. Suggerito `mews/purifier` o whitelist di tag al salvataggio.

### 5. Nessuna Policy Laravel
L'autorizzazione è inline (`abort_unless(...->company_id === ..., 403)`) sparsa nei controller. Dove ho verificato (Webhook, TextPaymentRequest, ScheduledPayment) è fatta correttamente, ma con 60+ controller il rischio è dimenticare il check nel prossimo endpoint. Centralizzare almeno Transfer/Account/Webhook in Policies + `authorize()`.

### 6. Residui `@php(...)` shorthand in 3 view
`broker/dashboard`, `onboarding/step1`, `portal/profile-edit`. Oggi nessuna delle tre contiene `@endphp`, quindi il bug Blade che ha causato il 500 in prod il 03/07 non è attivo — ma basta che qualcuno aggiunga un blocco `@php ... @endphp` in quei file per riattivarlo. Convertirli ora costa 5 minuti.

### 7. `SESSION_SECURE_COOKIE` non impostata
Default `null` → il cookie di sessione viaggia anche su HTTP. La checklist in DEPLOY.md copre `APP_DEBUG` ma non questa. Aggiungere `SESSION_SECURE_COOKIE=true` al checklist prod.

### 8. God controller
PortalController 1883, AdminController 1246, WebAuthnController 619, KyCardController 581 righe. Lo split di PortalController era già nel piano del 23/06; il pattern usato per AdminController (10 controller + trait) è replicabile.

---

## P3 — Igiene e ottimizzazioni

### 9. Igiene del repository
7 file `.docx`/`.xlsx` di analisi tracciati in git e ~20 script SQL one-off nella root (untracked ma clutter). Spostare in `docs/` e `database/scripts/`, aggiungere pattern a `.gitignore`. Rimuovere `vendor-fix.zip`.

### 10. Rete di sicurezza N+1
L'eager loading è usato bene (38 `with()` solo in PortalController), ma manca `Model::shouldBeStrict(!app()->isProduction())` in `AppServiceProvider`: intercetterebbe lazy loading e attributi mancanti in dev prima che arrivino in prod.

### 11. MLM senza test PHPUnit
60 file di test totali, zero su `MlmCommissionEngine` e `MlmTreeService::moveAgent()` — codice che calcola denaro reale nei payout agenti. Priorità: commissioni livello 5 = 8%, ricollegamento closure table senza cicli.

### 12. phpstan baseline
190 messaggi residui. Continuare l'erosione a lotti come fatto finora.

---

## Punti di forza verificati

Rate limiting granulare per categoria (`payments`, `financial_ops`, `incasso`), `step.up` su 15 azioni sensibili, HMAC SHA-256 sui webhook in uscita, verifica firma Stripe con `constructEvent`, idempotency key sui transfer, whitelisting di sort/dir nelle query raw (nessuna SQL injection trovata), 24 FormRequest, `backoffice` middleware su tutte le rotte admin, nessun model con `$guarded = []`, `.env` non tracciato, integrità contabile schedulata con heartbeat. L'impianto di sicurezza applicativa è sopra la media per un progetto di queste dimensioni.

---

*Analisi eseguita il 06/07/2026 su branch corrente. Le righe citate si riferiscono allo stato attuale dei file.*
