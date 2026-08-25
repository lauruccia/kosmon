# Rapporto di esplorazione del repository KMoney

**Data:** 24 agosto 2026  
**Modalità:** analisi rigorosamente read-only del repository. La sola modifica successiva all'analisi è la creazione di questo rapporto. Non sono stati eseguiti test, installazioni, migrazioni, commit o push.

## Sintesi

KMoney è una piattaforma di circuito monetario locale basata su un monolite Laravel. La valuta interna KY è rappresentata sempre in centesimi interi. Il dominio finanziario usa trasferimenti in partita doppia, con un servizio centrale incaricato di prenotare tutti i movimenti.

La documentazione introduttiva è utile per comprendere le regole del dominio, ma `AI_CONTEXT.md`, `PROJECT_MAP.md` e varie sezioni di `CLAUDE.md` non riflettono più le dimensioni e tutte le funzionalità presenti nel codice ad agosto 2026.

## Stack e architettura

- Backend: PHP `^8.2`, Laravel `^12.0`.
- Architettura: monolite Laravel con controller, servizi applicativi, modelli Eloquent, job in coda e viste Blade.
- Frontend: Blade, Tailwind CSS 4, Vite 7.
- Runtime frontend: Node.js `^22.12.0`, npm `>=10`.
- Database: SQLite in sviluppo; MySQL in produzione.
- Queue, cache e sessioni: database in sviluppo, Redis in produzione.
- Real-time: Laravel Reverb, Laravel Echo e `pusher-js`.
- Integrazioni: Stripe, Web Push, WebAuthn/Passkey, TOTP 2FA, Sentry, QR code e dompdf.
- Test: PHPUnit 11; test end-to-end mobile con Playwright-style spec JavaScript.
- API: API v1 con token custom, non Laravel Sanctum.

Le dipendenze e versioni principali sono definite in `composer.json` e `package.json`.

## Entry point

- `bootstrap/app.php`: bootstrap Laravel, caricamento route, middleware e gestione eccezioni/Sentry.
- `routes/web.php`: portale, autenticazione, onboarding, pagamenti, NFC, marketplace, admin, broker, backoffice e MLM.
- `routes/api.php`: API v1 autenticata e pairing pubblico per plugin e-commerce.
- `routes/console.php`: scheduler applicativo, contabilità, coda, pagamenti e job MLM.
- `routes/channels.php`: autorizzazione dei canali broadcast.
- `vite.config.js`: entry frontend:
  - `resources/css/app.css`
  - `resources/js/app.js`
  - `resources/js/ky-payment-request.js`
- Health check Laravel configurato in `bootstrap/app.php`: `GET /up`.
- Health check applicativo esteso in `routes/web.php`: `GET /health`.

Gli alias middleware attuali comprendono:

- `onboarding`
- `twofactor`
- `api.token`
- `not.suspended`
- `step.up`
- `contract`
- `agent.contract`
- `backoffice`
- `mlm.enabled`

Lo stack portale effettivo include `agent.contract` prima di `contract`.

## Dimensioni rilevate

Conteggi di file rilevati durante l'esplorazione:

| Area | File |
|---|---:|
| `app/Http/Controllers` | 100 |
| `app/Models` | 68 |
| `app/Services` | 28 |
| `app/Jobs` | 8 |
| `app/Http/Middleware` | 10 |
| `app/Console/Commands` | 21 |
| `database/migrations` | 165 |
| `tests/Feature` | 95 |
| `tests/Unit` | 2 |

In `routes/web.php` sono state rilevate 543 occorrenze di `Route::`. Questo dato indica l'ampiezza del file, ma non equivale necessariamente al numero esatto delle route registrate.

## Componenti principali

### Dominio finanziario

- `app/Services/TransferBookingService.php`: motore centrale di tutti i movimenti finanziari.
- `app/Models/Account.php`: conti, saldi, fido e logica commerciale.
- `app/Models/Transfer.php`: movimento finanziario.
- `app/Models/LedgerEntry.php`: scritture in partita doppia.
- `app/Models/AuditLog.php`: tracciamento degli eventi rilevanti.
- `app/helpers.php`: conversione e formattazione degli importi KY.

### Servizi applicativi

Oltre al booking finanziario sono presenti servizi per cashback, netting, rate, pagamenti programmati, sottoconti, webhook, notifiche push, menu, referral, e-commerce, marketplace e un'estesa area MLM.

### Aree applicative

- Portale utente e dashboard.
- Autenticazione, Passkey, 2FA e step-up authentication.
- Onboarding, KYC e firma contratti.
- Pagamenti, incassi, QR, NFC, codici e richieste testuali.
- Rate, netting e pagamenti programmati.
- Carte KY e gateway di pagamento esterni.
- Marketplace, offerte e ordini.
- API token, webhook e pairing e-commerce.
- Backoffice admin e broker.
- Referral, agenti e sistema MLM con contratti, punti, bonus, commissioni e payout.

## Flussi critici e regole operative

1. Gli importi KY devono essere sempre centesimi interi; non usare float per i saldi.
2. Input utente: `ky_to_cents()`; output: `ky_format()`; precompilazione input: `ky_input()`.
3. Tutti i movimenti devono passare da `TransferBookingService`.
4. Ogni operazione deve avere una `idempotency_key`.
5. Ogni trasferimento contabilizzato genera due `LedgerEntry`, una per lato della partita doppia.
6. Gli aggiornamenti dei saldi richiedono `DB::transaction()`, `lockForUpdate()` e `forceFill()->save()`.
7. Gli eventi rilevanti devono produrre un `AuditLog`.
8. Il cashback non deve essere soggetto a commissioni.
9. Le commissioni sono collegate al trasferimento principale tramite `related_transfer_id`.
10. Gli importi ricevuti dall'API v1 sono già in centesimi e non devono essere moltiplicati per 100.
11. Il flusso auth è stratificato: login, verifica email, 2FA, onboarding, eventuale contratto agente, contratto generale e step-up per azioni sensibili.
12. Non rinominare route esistenti e non rimuovere funzioni senza controllarne prima le dipendenze.

## Scheduler

Lo scheduler effettivo comprende, tra gli altri:

- rate scadute ogni giorno alle 06:00;
- scadenza richieste di pagamento ogni minuto;
- promemoria richieste ogni cinque minuti;
- pagamenti programmati ogni minuto tramite comando Artisan;
- estratti conto mensili;
- alert saldo ogni ora;
- worker della coda ogni minuto per hosting condiviso;
- verifica contabile completa giornaliera e rapida oraria;
- monitoraggio della contesa sul conto di sistema;
- ricalcolo punti, commissioni e bonus MLM, condizionati dalla configurazione `MLM_ENABLED`.

## Test e comandi utili

`phpunit.xml` configura i test con:

- SQLite in memoria;
- queue sincrona;
- cache e sessioni in memoria;
- mailer array;
- broadcasting disabilitato.

Comandi documentati e presenti nella configurazione:

```bash
composer run setup
composer run dev
composer run test
composer run analyse
php artisan test
npm run dev
npm run build
php artisan queue:listen --tries=1 --timeout=0
php artisan reverb:start
php artisan schedule:work
```

Sono presenti 95 test Feature, 2 test Unit e `tests/e2e/mobile-smoke.spec.js`. I test coprono, fra le altre aree, booking, limiti, API, auth, 2FA, onboarding, KYC, NFC, pagamenti programmati, rate, netting, cashback, marketplace, e-commerce, backoffice e MLM.

Nessun test è stato eseguito durante questa esplorazione, perché non sono state apportate modifiche al codice e il mandato richiedeva un'analisi read-only.

## Stato Git rilevato

Al momento dell'esplorazione:

```text
main...origin/main [ahead 1]
```

File non tracciati:

```text
Analisi_Tecnica_KMoney_2026-08-14.docx
KMoney_Modello_Economico.xlsx
KMoney_Strategia_Completa.pdf
STRATEGIA_LANCIO_RC_2026-08-14.md
_vstage_test.tgz
app/Console/Commands/InspectAccountConversion.php
```

Ultimo commit locale rilevato:

```text
367256c Annota che l'utente phpMyAdmin di cPanel non legge information_schema
```

Lo stato Git non era quindi pulito già prima della creazione di questo rapporto. I file non tracciati appartengono all'utente e devono essere preservati.

## Incongruenze e rischi

1. **Documentazione strutturale obsoleta.** `AI_CONTEXT.md` e `PROJECT_MAP.md` risultano aggiornati al 10 giugno 2026. Dichiarano circa 55-58 controller, 44 modelli, 9 servizi, 7 middleware, 12 comandi e 88 migration; il codice attuale ne contiene rispettivamente 100, 68, 28, 10, 21 e 165.

2. **Aree recenti scarsamente documentate.** Le mappe omettono o descrivono solo parzialmente MLM, e-commerce, gateway, abbonamenti, backoffice e controlli di integrità contabile.

3. **API documentata con endpoint non più attuali.** `CLAUDE.md` cita endpoint basati su `/api/v1/accounts/{account}`, mentre `routes/api.php` espone attualmente `/api/v1/me`, `/balance`, `/transfers`, `/payment-plans`, `/payment-requests` e gli endpoint pubblici di pairing e-commerce.

4. **Problema API raw ID ormai risolto ma ancora elencato.** `formatTransfer()` espone `account_number`, non gli ID interni. `AI_CONTEXT.md` presenta il problema sia come confermato sia come completato nello Sprint 2.

5. **Query Broker corretta ma segnalazione non aggiornata.** `BrokerController` raggruppa oggi correttamente `whereIn` e `orWhereIn` dentro una closure prima di applicare lo stato `booked`. Il vecchio problema resta elencato nella documentazione.

6. **HMAC NFC descritto in modo parziale.** Le nuove carte usano la firma SHA-256 completa. `verifyHmac()` accetta ancora firme legacy da 16 caratteri solo per retrocompatibilità e genera un warning. La documentazione lo descrive ancora genericamente come HMAC troncato.

7. **Route NFC pubbliche: documentazione errata e controllo da approfondire.** `AI_CONTEXT.md` afferma che la POST di autorizzazione NFC si trova nel gruppo autenticato. In `routes/web.php`, sia GET sia POST `/nfc/card/authorize/{nonce}` sono dichiarate prima dei gruppi auth e risultano pubbliche a livello route. Occorre verificare attentamente i controlli applicativi nel controller prima di qualsiasi intervento su questo flusso.

8. **Frequenze scheduler non aggiornate.** La documentazione indica rate ogni ora e scadenza richieste ogni cinque minuti; il codice attuale usa rispettivamente esecuzione giornaliera alle 06:00 ed esecuzione ogni minuto. I pagamenti programmati passano inoltre dal comando `payments:run-scheduled`, non dal job documentato.

9. **Doppio health endpoint.** Esistono `/up`, configurato dal framework, e `/health`, implementato dall'applicazione. La documentazione cita soltanto `/health`.

10. **Dipendenze Composer non strettamente vincolate.** `sentry/sentry-laravel` e `stripe/stripe-php` usano `*`. Il lock file stabilizza l'installazione corrente, ma aggiornamenti futuri richiedono cautela.

11. **Worktree già non pulito e branch avanti di un commit.** Prima di commit o push futuri è necessario distinguere chiaramente le modifiche dell'agente dai file utente e comprendere il commit locale non ancora presente su `origin/main`.

12. **Directory e artefatti temporanei.** Sono presenti directory come `_to_delete`, `_to_delete_stage3`, `_vstage` e un archivio `_vstage_test.tgz`. Non devono essere eliminati o inclusi in commit senza autorizzazione e verifica esplicita.

13. **Produzione senza migrazioni Artisan.** Il progetto usa SQL manuale tramite phpMyAdmin in produzione. Ogni futura migration deve essere accompagnata dallo SQL equivalente e applicata localmente soltanto secondo il workflow del progetto.

## Percorsi chiave

- `C:\laragon\www\kmoney-app\AI_CONTEXT.md`
- `C:\laragon\www\kmoney-app\PROJECT_MAP.md`
- `C:\laragon\www\kmoney-app\CLAUDE.md`
- `C:\laragon\www\kmoney-app\AGENTS.md`
- `C:\laragon\www\kmoney-app\app\Services\TransferBookingService.php`
- `C:\laragon\www\kmoney-app\app\Models\Account.php`
- `C:\laragon\www\kmoney-app\app\Models\Transfer.php`
- `C:\laragon\www\kmoney-app\app\Models\LedgerEntry.php`
- `C:\laragon\www\kmoney-app\app\Models\AuditLog.php`
- `C:\laragon\www\kmoney-app\app\helpers.php`
- `C:\laragon\www\kmoney-app\bootstrap\app.php`
- `C:\laragon\www\kmoney-app\routes\web.php`
- `C:\laragon\www\kmoney-app\routes\api.php`
- `C:\laragon\www\kmoney-app\routes\console.php`
- `C:\laragon\www\kmoney-app\composer.json`
- `C:\laragon\www\kmoney-app\package.json`
- `C:\laragon\www\kmoney-app\vite.config.js`
- `C:\laragon\www\kmoney-app\phpunit.xml`
- `C:\laragon\www\kmoney-app\tests\Feature`
- `C:\laragon\www\kmoney-app\tests\e2e\mobile-smoke.spec.js`

## Conclusione

Il nucleo finanziario appare strutturato intorno a un servizio centrale con transazioni, lock pessimistici, partita doppia e audit. Il rischio principale per interventi futuri non è soltanto la complessità del dominio, ma anche il divario tra la documentazione di giugno e il codebase di agosto. Prima di modificare un'area recente è opportuno usare la documentazione per le regole invarianti del dominio, verificando però route, componenti e scheduler direttamente nei file attuali e limitando sempre l'ispezione all'area interessata.
