# Analisi Senior — kmoney-app
**Data:** 2026-06-17 · **Revisore:** Claude (Cowork) · **Approccio:** revisione del codice attuale, non dei report precedenti

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
