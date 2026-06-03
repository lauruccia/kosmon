# CLAUDE.md — kmoney-app

Piattaforma di circuito monetario locale (stile Sardex) basata su Laravel 12.
La valuta interna si chiama **KY** (o KMoney). Gli importi sono sempre **interi** (centesimi di KY).

---

## Stack

| Layer | Tecnologia |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| Frontend | Blade + Tailwind CSS v4 + Vite 7 |
| Database dev | SQLite (`database/database.sqlite`) |
| Database prod | MySQL |
| Queue/Cache/Session | Database (dev) → Redis (prod) |
| WebSocket | Laravel Reverb (self-hosted) + Laravel Echo + pusher-js |
| PDF | barryvdh/laravel-dompdf |
| Auth avanzata | WebAuthn/Passkey (`web-auth/webauthn-lib` v4.9), 2FA TOTP |
| Pagamenti esterni | Stripe (KY Card), Web Push (minishlink/web-push) |
| Error monitoring | Sentry (`sentry/sentry-laravel`) |
| QR code | simplesoftwareio/simple-qrcode |
| Test | PHPUnit 11 |

---

## Comandi essenziali

```bash
# Setup iniziale
composer run setup

# Sviluppo (server + queue + log + vite in parallelo)
composer run dev

# Test
composer run test
# oppure direttamente
php artisan test
php artisan config:clear && php artisan test

# Migrations
php artisan migrate
php artisan migrate:fresh --seed   # reset completo

# Asset frontend
npm run dev     # watch
npm run build   # produzione

# Queue worker (manuale)
php artisan queue:listen --tries=1 --timeout=0

# WebSocket Reverb
php artisan reverb:start

# Log real-time
php artisan pail --timeout=0

# Genera chiavi VAPID per Web Push
php artisan webpush:vapid

# Tinker REPL
php artisan tinker
```

---

## Architettura del progetto

```
app/
├── Console/Commands/      # Comandi Artisan (import dati, ricalcolo saldi...)
├── Events/                # PaymentRequestUpdated
├── Http/
│   ├── Controllers/       # Tutti i controller (portale, admin, broker, API v1)
│   │   ├── Admin/         # AdminMenuVisibilityController, AdminNfcCardController
│   │   └── Api/V1/        # AccountController, TransferController
│   └── Middleware/        # onboarding, twofactor, api.token, not.suspended, step.up, contract
├── Jobs/                  # Queue jobs (pagamenti programmati, alert, statement, webhook...)
├── Listeners/             # LogLoginActivity, SendWebPushAfterNotification
├── Mail/                  # Email transazionali (Mailable)
├── Models/                # Eloquent models (vedi sotto)
├── Notifications/         # Laravel Notifications (email + web push + database)
├── Providers/             # AppServiceProvider
├── Services/              # Logica di business (vedi sotto)
└── Support/               # Totp.php
```

### Middleware aliases (bootstrap/app.php)

| Alias | Classe |
|---|---|
| `onboarding` | EnsureOnboardingComplete |
| `twofactor` | TwoFactorChallenge |
| `api.token` | ApiTokenAuth |
| `not.suspended` | EnsureCompanyNotSuspended |
| `step.up` | RequireStepUp |
| `contract` | EnsureContractSigned |

### Stack middleware portale autenticato

```php
['auth', 'verified', 'twofactor', 'onboarding', 'contract']
```

---

## Modelli principali

| Model | Descrizione |
|---|---|
| `User` | Utente (può essere owner di account o gestore di sottoconto) |
| `Company` | Azienda/ente del circuito |
| `Account` | Conto KY (KYB = business, KYP = personal, KY = sistema) |
| `Transfer` | Movimento finanziario — cuore del sistema |
| `LedgerEntry` | Partita doppia: ogni Transfer genera 2 entry (debit + credit) |
| `AuditLog` | Log immutabile di ogni operazione |
| `CreditLimit` | Fido/scoperto concesso dall'admin |
| `PaymentRequest` | Richiesta di pagamento via QR/NFC/Sonic/codice |
| `TextPaymentRequest` | Richiesta di pagamento testuale (formale) |
| `PaymentPlan` | Piano rateale |
| `NettingProposal` | Compensazione crediti incrociati |
| `ScheduledPayment` | Pagamento programmato |
| `KyCard` | Scheda ricarica KY (Stripe/PayPal/Bonifico) |
| `NfcCard` | Carta NFC fisica |
| `ApiToken` | Token API custom (non Sanctum) |
| `Webhook` / `WebhookDelivery` | Integrazioni esterne |
| `KycDocument` | Documenti KYC per verifica identità |
| `ContractSignature` | Firma digitale del contratto di adesione |
| `WebAuthnCredential` | Credenziali Passkey |

---

## Numeri di conto KY

Il formato è `KYB` + 13 alfanumerici (business), `KYP` + 13 (privati), `KY` + 14 (sistema).
Sono univoci, generati automaticamente nel `booted()` di `Account`.

```php
Account::hasKyAccountNumber($uuid)   // valida il formato
Account::generateKyAccountNumber('company')  // genera nuovo numero
Account::systemAccount()             // Cassa Circuito KMoney (is_system_account = true)
```

---

## Servizi (app/Services/)

### `TransferBookingService` — motore finanziario

**Regola d'oro: tutti i movimenti passano da qui. Mai aggiornare `available_balance` direttamente.**

```php
$svc = app(TransferBookingService::class);

// Trasferimento immediato
$transfer = $svc->book([
    'initiated_by'    => $userId,
    'from_account_id' => $fromId,
    'to_account_id'   => $toId,
    'amount'          => 5000,          // 50,00 KY (centesimi)
    'kind'            => 'trade_payment',
    'description'     => '...',
    'idempotency_key' => Str::uuid(),   // SEMPRE fornire
    'ip_address'      => $request->ip(),
]);

// Richiesta di pagamento (pending, richiede conferma)
$transfer = $svc->requestPayment([...]);

// Conferma / rifiuto richiesta
$svc->confirmRequest($transfer, $userId, $ip);
$svc->rejectRequest($transfer, $userId, $ip);

// Rimborso (merchant → cliente)
$svc->refundMerchant($originalTransfer, $amount, $userId, $description, $ip);

// Nota di credito (libera, opz. legata a un movimento originale)
$svc->issueCreditNote($fromAccountId, $toAccountId, $amount, $userId, $description, $originalTransferId, $ip);
```

**Transfer kinds disponibili:**

| Kind | Descrizione |
|---|---|
| `trade_payment` | Pagamento nel circuito |
| `portal_payment` | Pagamento dal portale |
| `portal_collection_request` | Incasso richiesto |
| `portal_refund` | Rimborso |
| `portal_credit_note` | Nota di credito |
| `portal_fee` | Commissione di transazione |

**Status trasfer:** `pending` → `booked` (o `rejected`)

### Altri servizi

| Servizio | Responsabilità |
|---|---|
| `CashbackService` | Calcola e applica cashback dopo ogni transfer |
| `NettingService` | Gestione compensazioni incrociate |
| `PaymentPlanService` | Rateizzazione pagamenti |
| `ScheduledPaymentService` | Esecuzione pagamenti programmati |
| `SubAccountService` | Gestione sottoconti e gestori |
| `WebhookService` | Invio webhook ai clienti |
| `WebPushService` | Invio notifiche push browser |
| `MenuVisibilityService` | Visibilità voci menu per ruolo |

---

## Logica saldi (Account)

```php
// Saldo disponibile reale (include fido)
$account->saldoDisponibile();      // available_balance + massimale()

// Stato commerciale (stile Sardex)
$account->isInDebit();             // saldo < 0 → può vendere solo 100% KY
$account->isAtCeiling();           // saldo >= max_balance → non può vendere
$account->canSell();
$account->allowedKyPercentages();  // [0,25,50,75,100] | [100] | []

// Formattazione importi
ky_format(1234)   // → "12,34" (helper globale in app/helpers.php)
```

---

## Helper globale

```php
ky_format(int|float $amount): string
// Es: ky_format(6) → "6,00"  |  ky_format(1234567) → "12.345,67"
```

---

## Autenticazione e sicurezza

Il flusso auth è stratificato:

1. **Login** (email/password o Passkey WebAuthn)
2. **Email verification** (`verified` middleware)
3. **2FA challenge** (TOTP, `twofactor` middleware)
4. **Onboarding wizard** (`onboarding` middleware) — 3 step: profilo, KYC, attesa approvazione
5. **Firma contratto** (`contract` middleware) — firma con OTP
6. **Step-up authentication** (`step.up`) — per azioni sensibili (disattiva 2FA, crea API token)

Per le operazioni sensibili usa `->middleware('step.up')` sulla route.

---

## Routes e naming convention

Le route seguono il prefisso `portal.*` per il portale utente, `admin.*` per il pannello admin, `broker.*` per il pannello operatori.

```php
// Esempi
route('portal.dashboard')
route('portal.pay.submit')
route('admin.kyc.approve', $company)
route('broker.clients.show', $company)
```

---

## API interna (routes/api.php)

Usa il middleware `api.token` (non Sanctum). Token generati dall'utente in `/api-tokens`.

```
POST /api/v1/accounts/{account}/transfer
GET  /api/v1/accounts/{account}
GET  /api/openapi.json   (spec pubblica, no auth)
```

---

## Jobs in coda

| Job | Frequenza consigliata |
|---|---|
| `ProcessScheduledPayments` | ogni minuto |
| `ProcessDueInstallments` | ogni ora |
| `CheckBalanceAlerts` | ogni ora |
| `ExpirePaymentRequests` | ogni 5 minuti |
| `SendMonthlyStatements` | 1° del mese |
| `SendWebhookJob` | on-demand (fire-and-forget) |
| `SendBroadcastMessageJob` | on-demand |

Vedi `routes/console.php` per la schedulazione Artisan.

---

## Notifiche

Le notifiche Eloquent rispettano le preferenze utente via il concern `RespectsNotificationPreferences`.
Canali supportati: `mail`, `database`, `broadcast` (Reverb), `webpush`.

---

## Configurazione ambiente

| Variabile | Dev | Prod |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `mysql` |
| `QUEUE_CONNECTION` | `database` | `redis` |
| `CACHE_STORE` | `database` | `redis` |
| `SESSION_DRIVER` | `database` | `redis` |
| `BROADCAST_CONNECTION` | `reverb` | `reverb` |
| `MAIL_MAILER` | `log` | `resend`/`mailgun`/`smtp` |

Variabili critiche da impostare in `.env`:
- `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` — genera con `php artisan webpush:vapid`
- `REVERB_APP_KEY`, `REVERB_APP_SECRET` — chiavi WebSocket
- `SENTRY_LARAVEL_DSN` — error monitoring (opzionale)
- `STRIPE_KEY`, `STRIPE_SECRET` — pagamenti KY Card

---

## Convenzioni del codebase

- **Importi sempre in centesimi (interi).** Non usare float per KY. `5000 = 50,00 KY`.
- **Idempotency key obbligatorio** su ogni transfer. Usare `Str::uuid()`.
- **`forceFill()` + `save()`** per aggiornare i saldi (non `update()`), per rispettare i lock pessimistici.
- **`DB::transaction()`** per ogni operazione che tocca più righe finanziarie.
- **`lockForUpdate()`** sugli account all'interno delle transazioni.
- **`AuditLog::create()`** per ogni evento rilevante — non saltare mai il log.
- **La valuta di default è `KY`.** Controllare sempre `currency_code` prima di operare.
- La **lingua dell'interfaccia è italiano** (locale `it`). Le stringhe di errore nel codice PHP sono in italiano.
- I nomi delle route sono in italiano (es. `portal.incasso-qr.form`, `portal.rate.index`).

---

## Struttura view Blade

```
resources/views/
├── layouts/
│   ├── portal.blade.php   # Layout principale portale (nav, sidebar)
│   └── legal.blade.php    # Layout pagine legali
├── admin/                 # Pannello admin
├── auth/                  # Login, register, 2FA, reset password
├── broker/                # Pannello operatori
├── emails/                # Template email transazionali
├── portal/                # (sotto-cartelle per ogni sezione)
└── home.blade.php         # Landing page pubblica
```

---

## File importanti

| File | Descrizione |
|---|---|
| `app/Services/TransferBookingService.php` | Motore pagamenti — leggere prima di toccare i saldi |
| `app/Models/Account.php` | Logica commerciale saldi KY |
| `app/Models/Transfer.php` | Struttura movimento finanziario |
| `routes/web.php` | Tutte le route (portale, admin, broker, auth) |
| `bootstrap/app.php` | Middleware, routing, Sentry |
| `app/helpers.php` | `ky_format()` |
| `vite.config.js` | Entry points: `app.css`, `app.js`, `ky-payment-request.js` |
| `.env.example` | Template configurazione completo con commenti |
| `DEPLOY.md` | Istruzioni deploy produzione |
| `REVERB_SETUP.md` | Setup WebSocket Reverb |
| `MYSQL_SETUP.md` | Migrazione SQLite → MySQL |

---

## Health check

```
GET /health
```
Verifica: database, cache, redis (se configurato), failed jobs recenti. Restituisce JSON con status `ok` o `degraded`.
