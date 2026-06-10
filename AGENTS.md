# AGENTS.md — Regole operative per agenti AI

Istruzioni per qualsiasi assistente AI (Claude, Copilot, Cursor, ecc.) che lavora su questo progetto.

## Prima di iniziare (obbligatorio)

1. Leggi `AI_CONTEXT.md` — contesto e flussi critici
2. Consulta `PROJECT_MAP.md` — dove si trova ogni cosa
3. Consulta `CLAUDE.md` — dettagli tecnici, comandi, convenzioni
4. **Non rianalizzare l'intero codebase**: leggi solo i file rilevanti per il task

## Regole d'oro

1. **Importi sempre in centesimi interi.** Mai float. Input utente → `ky_to_cents()`, visualizzazione → `ky_format()`, prepopolare input → `ky_input()`. L'API v1 riceve già centesimi (no conversione).
2. **Tutti i movimenti passano da `TransferBookingService`.** Mai aggiornare `available_balance` direttamente. `idempotency_key` (Str::uuid) sempre obbligatorio.
3. **Saldi:** `DB::transaction()` + `lockForUpdate()` + `forceFill()->save()` (mai `update()`).
4. **`AuditLog::create()`** per ogni evento rilevante — mai saltarlo.
5. **Non rinominare route esistenti** (nomi in italiano, es. `portal.incasso-qr.form`).
6. **Non rimuovere funzioni** senza verificare le dipendenze (grep prima di cancellare).
7. **Lingua:** interfaccia e messaggi di errore in italiano.
8. Non toccare file core di Laravel/vendor.

## Workflow dopo OGNI modifica

1. Test: `php artisan test` (o almeno i test dell'area toccata)
2. Fornire **commit message** e fare **push su GitHub**
3. Se ci sono migration: `php artisan migrate` in locale **e** fornire lo **script SQL equivalente per phpMyAdmin** (in produzione NON si usa `artisan migrate`)
4. Aggiornare `CHANGELOG_AI.md` (voce in cima) e, se cambia qualcosa di strutturale, `AI_CONTEXT.md` / `PROJECT_MAP.md`

## Ambiente

- Dev: Windows + Laragon, SQLite, `composer run dev`
- Prod: cPanel + Git Version Control, MySQL, Redis. Deploy = push su GitHub + pull da cPanel + SQL manuale su phpMyAdmin
- Vedi `DEPLOY.md`, `MYSQL_SETUP.md`, `REVERB_SETUP.md`

## Flussi critici da non rompere

Auth a strati (login → verified → 2FA → onboarding → contratto → step-up), motore pagamenti (Transfer + 2 LedgerEntry), cashback automatico (mai soggetto a fee), commissioni (`portal_fee` collegato via `related_transfer_id`), netting, piani rateali, pagamenti programmati, webhook, notifiche (rispettano le preferenze utente).
