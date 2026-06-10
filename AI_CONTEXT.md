# Contesto progetto

Questo progetto è già stato analizzato. **Non rianalizzare tutto il codice: leggi questo file, `PROJECT_MAP.md`, `AGENTS.md` e `CLAUDE.md` prima di iniziare.**

## Cos'è
**KMoney** — piattaforma di circuito monetario locale (stile Sardex). Valuta interna **KY** (KMoney). Gli importi sono **sempre interi in centesimi di KY** (`5000` = 50,00 KY).

## Stack
- Framework: Laravel 12
- Linguaggio: PHP 8.2+
- Database: SQLite in dev (`database/database.sqlite`), MySQL in produzione
- Frontend: Blade + Tailwind CSS v4 + Vite 7 (entry: `app.css`, `app.js`, `ky-payment-request.js`)
- Backend: Laravel monolitico, queue/cache/session su database (dev) → Redis (prod), WebSocket Laravel Reverb + Echo
- Extra: dompdf (PDF), Stripe (KY Card), Web Push, WebAuthn/Passkey, 2FA TOTP, Sentry, QR code, PHPUnit 11

## Struttura principale
- Rotte: `routes/web.php` (~396 route: `portal.*`, `admin.*`, `broker.*`, auth), `routes/api.php` (API v1, middleware `api.token`), `routes/console.php` (scheduler)
- Controller: `app/Http/Controllers/` (~55 controller piatti + `Admin/` + `Api/V1/`) — vedi PROJECT_MAP.md
- Viste: `resources/views/` → `portal/`, `admin/`, `broker/`, `auth/`, `emails/`, layout in `layouts/portal.blade.php`
- Componenti principali: `app/Services/TransferBookingService.php` (motore finanziario — TUTTI i movimenti passano da qui), `app/Models/Account.php` (logica saldi), `app/Models/Transfer.php`, `app/helpers.php` (`ky_format`, `ky_to_cents`, `ky_input`)
- Middleware: `onboarding`, `twofactor`, `api.token`, `not.suspended`, `step.up`, `contract` (alias in `bootstrap/app.php`). Stack portale: `['auth','verified','twofactor','onboarding','contract']`
- Modelli principali: User, Company, Account (KYB/KYP/KY), Transfer, LedgerEntry (partita doppia), AuditLog, CreditLimit, PaymentRequest, PaymentPlan, NettingProposal, ScheduledPayment, KyCard, NfcCard, ApiToken, Webhook, KycDocument, ContractSignature

## Flussi da non rompere
- Login: email/password o Passkey WebAuthn → verifica email → 2FA TOTP → onboarding (3 step) → firma contratto con OTP. Step-up auth per azioni sensibili.
- Registrazione: wizard onboarding (profilo, KYC, attesa approvazione admin)
- Pagamenti: SEMPRE via `TransferBookingService::book()` con `idempotency_key` (Str::uuid). Mai aggiornare `available_balance` direttamente. Ogni Transfer genera 2 LedgerEntry. Kinds: trade_payment, portal_payment, portal_fee, portal_cashback, portal_installment, portal_netting, ecc.
- Dashboard: `portal.dashboard` — saldi via `saldoDisponibile()`, stato commerciale (`isInDebit`, `isAtCeiling`, `allowedKyPercentages`)
- Invio notifiche: Laravel Notifications con concern `RespectsNotificationPreferences`; canali mail, database, broadcast (Reverb), webpush
- API: `routes/api.php`, token custom (NON Sanctum), middleware `api.token`. Gli importi API sono già in centesimi (NO conversione ×100)

## Convenzioni
- Non modificare file core se non necessario.
- Non cambiare nomi di rotte esistenti (sono in italiano, es. `portal.incasso-qr.form`).
- Non rimuovere funzioni senza verificare dipendenze.
- Quando modifichi qualcosa, aggiorna questo file e `CHANGELOG_AI.md`.
- Importi sempre in centesimi interi; input utente → `ky_to_cents()`, output → `ky_format()`, prepopolare input → `ky_input()`.
- `DB::transaction()` + `lockForUpdate()` + `forceFill()->save()` per i saldi; `AuditLog::create()` per ogni evento rilevante.
- Lingua interfaccia: italiano (locale `it`).
- Deploy: Laragon in locale, push su GitHub, cPanel con Git Version Control in prod. **Niente `artisan migrate` in prod**: modifiche DB via SQL su phpMyAdmin. Dopo ogni modifica fornire: commit message, push, migrate locale, SQL per produzione.

## Ultima analisi
Data: 2026-06-10
Cosa è stato analizzato: intero codebase (controller, modelli, servizi, rotte, viste, job, middleware) — sintetizzato qui e in PROJECT_MAP.md
Decisioni prese: creati AI_CONTEXT.md, PROJECT_MAP.md, CHANGELOG_AI.md, AGENTS.md come fonte di verità per le sessioni AI; nessuna modifica al codice
File importanti: `app/Services/TransferBookingService.php`, `app/Models/Account.php`, `app/Models/Transfer.php`, `routes/web.php`, `bootstrap/app.php`, `app/helpers.php`, `CLAUDE.md`
