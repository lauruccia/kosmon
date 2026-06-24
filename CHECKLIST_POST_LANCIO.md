# Checklist post-lancio — kmoney-app

_Aggiornata: 24/06/2026. Tutti i bug bloccanti pre-lancio sono chiusi (/admin/support, realtime off, .env prod). Qui i punti **non bloccanti** per le prossime sessioni._

Priorita: **A** = alta · **M** = media · **B** = bassa · **OPZ** = opzionale

---

## 1. Sicurezza

- [ ] **(A) CSP nonce-based** — rimuovere `'unsafe-inline'` da `script-src` in `app/Http/Middleware/ContentSecurityPolicy.php` e passare a nonce per-request. Annulla la protezione XSS finche resta. Sessione dedicata: rischioso, puo rompere gli script inline del portale → testare pagina per pagina.
- [ ] **(A) Backup MySQL prod** — dump automatico schedulato + copia offsite. Oggi assente.
- [ ] **(M) SESSION_SECURE_COOKIE=true** — confermare attivo in prod (HTTPS).

## 2. Debito tecnico / Refactor

- [ ] **(M) Split PortalController** (1848 righe / 35 metodi) per dominio: Pay / Receive / Dashboard / Profile — come gia fatto per `Admin/*`. A seguire: `WebAuthnController` (615), `NfcCardPaymentController` (564).
- [ ] **(M) FormRequest #2** — ridurre i `validate()` inline rimanenti (~81), a lotti, suite verde dopo ogni lotto. Rispettare la regola d'ordine authorize()/inline (vedi memoria).
- [ ] **(B) phpstan verso level 6** — erodere la baseline (322 errori) con `@property`/generics sulle relazioni, poi alzare il livello.

## 3. Test & CI

- [ ] **(M) Job CI MySQL** — oggi la CI gira solo su SQLite ma la prod e MySQL. Aggiungere un job con servizio MySQL.
- [ ] **(M) Unit test motore finanziario** — coprire edge: importo 0, self-transfer, fido al limite, valute diverse, idempotency. Oggi quasi tutto e Feature, 1 solo Unit.
- [ ] **(B) Smoke mobile** — eseguire `playwright` (`QA_MOBILE_CHECKLIST.md`, `tests/e2e/mobile-smoke.spec.js`).

## 4. Database & Performance

- [ ] **(B) Verificare indici** — `transfers(idempotency_key)`, `transfers(from_account_id, created_at)`.
- [ ] **(B) Droppare indice ridondante** — `audit_logs_actor_event_index` (ora prefisso di `audit_logs_actor_event_created_index`). Micro-ottimizzazione.
- [ ] **(B) Contesa lock conto sistema** — valutare `lockForUpdate` sulla cassa circuito sotto carico concorrente.

## 5. Monitoraggio & Ops (continuo)

- [ ] **Sentry** — confermare che gli errori prod arrivino (`SENTRY_LARAVEL_DSN` valorizzato).
- [ ] **failed_jobs** — controllare periodicamente la coda dei job falliti.
- [ ] **Integrita contabile** — verifica oraria `accounting:verify-integrity --quick` attiva + dead-man's switch in `/health`: controllare che l'heartbeat sia aggiornato (cron vivo).
- [ ] **Cron cPanel** — confermare schedulazione: pagamenti programmati (ogni minuto), rate (ogni ora), alert saldo, expire richieste (5 min), resoconti mensili (1 del mese).

## 6. Realtime (opzionale, futuro)

- [ ] **(OPZ) Attivare Pusher** — se vuoi notifiche live istantanee: chiavi in `.env.production` (`VITE_PUSHER_APP_KEY`/`VITE_PUSHER_APP_CLUSTER`), `BROADCAST_CONNECTION=pusher` + `PUSHER_*` nel `.env` prod, poi `npm run build` + commit `public/build`. Codice gia predisposto (bootstrap.js, config/broadcasting.php).

---

### Promemoria operativi (ambiente)

- **Commit/push solo da Laragon**, mai dal sandbox: il mount NTFS non gestisce `.git/index.lock` e non ha credenziali GitHub.
- **Mai `git add -A`** dopo una sessione: il mount puo mostrare Model "troncati" (artefatto di lettura) — committare sempre i file in modo esplicito e verificare `git status`/diff.
- **File PHP con accenti/Unicode**: modificare via Python (read/write binario), non con Edit diretto, per evitare troncamenti.
- **Deploy cPanel = solo file** (no `artisan migrate`): le migration vanno applicate via SQL su phpMyAdmin; il codice always-run va in `app/helpers.php` (una classe nuova nel bootstrap manda in 500).
