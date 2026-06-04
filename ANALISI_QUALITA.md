# Analisi Qualità — kmoney-app
**Data:** 2026-06-04 | **Revisore:** Claude (Cowork)

---

## Riepilogo

| Priorità | N° problemi |
|----------|-------------|
| 🔴 Critico (blocca funzionalità / può crashare) | 4 |
| 🟠 Alto (bug silenzioso / sicurezza) | 4 |
| 🟡 Medio (comportamento errato ma non bloccante) | 5 |
| 🟢 Basso / miglioramento | 6 |

---

## 🔴 BUG CRITICI

### 1. `bookFee()` — LedgerEntry creata con campi sbagliati → crash DB

**File:** `app/Services/TransferBookingService.php` — righe 809–810

```php
// CODICE ATTUALE (sbagliato)
\App\Models\LedgerEntry::create([
    'transfer_id' => $transfer->id,
    'account_id'  => $fromAccount->id,
    'type'        => 'debit',   // ❌ campo si chiama 'direction'
    'amount'      => $fee,
    // ❌ mancano 'balance_after' (NOT NULL) e 'posted_at' (NOT NULL)
]);
```

La migration di `ledger_entries` definisce `direction`, `balance_after` e `posted_at` tutti come `NOT NULL`. Il codice usa `'type'` (non in `$fillable` → ignorato da Eloquent), e omette le altre due colonne obbligatorie. Il risultato è un **errore DB ogni volta che viene addebitata una commissione** con fee > 0.

**Fix:**
```php
$bookedAt = \Carbon\CarbonImmutable::now();
$feeDebitAfter  = $fromAccount->available_balance; // già decrementato sopra
$feeCreditAfter = $systemAccount->available_balance; // già incrementato sopra

\App\Models\LedgerEntry::create([
    'transfer_id'   => $transfer->id,
    'account_id'    => $fromAccount->id,
    'direction'     => 'debit',
    'amount'        => $fee,
    'balance_after' => $feeDebitAfter,
    'posted_at'     => $bookedAt,
]);
\App\Models\LedgerEntry::create([
    'transfer_id'   => $transfer->id,
    'account_id'    => $systemAccount->id,
    'direction'     => 'credit',
    'amount'        => $fee,
    'balance_after' => $feeCreditAfter,
    'posted_at'     => $bookedAt,
]);
```

---

### 2. `bookFee()` — Usa `increment`/`decrement` invece di `forceFill + save`

**File:** `app/Services/TransferBookingService.php` — righe 805–806

```php
$fromAccount->decrement('available_balance', $fee);
$systemAccount->increment('available_balance', $fee);
```

Questo viola la regola d'oro del progetto ("mai aggiornare `available_balance` direttamente, usare `forceFill + save` con `lockForUpdate`"). `increment/decrement` bypass il pessimistic lock e può produrre **race condition** sui saldi in caso di pagamenti concorrenti.

**Fix:** Aprire una transazione con `lockForUpdate()` sugli account e usare `forceFill(['available_balance' => ...])->save()`, come nel resto del servizio.

---

### 3. `CheckBalanceAlerts` Job — usa `$account->balance` che non esiste

**File:** `app/Jobs/CheckBalanceAlerts.php` — riga 35

```php
$balance = $account->balance; // centesimi  ← SBAGLIATO
```

Il modello `Account` non ha né un attributo `balance` né un accessor `getBalanceAttribute`. Eloquent restituisce `null`. In PHP, `null < $alert->threshold_amount` è `true` per qualsiasi soglia > 0, il che significa che **tutti gli alert attivi si attivano ad ogni run** indipendentemente dal saldo reale.

**Fix:**
```php
$balance = $account->available_balance; // campo corretto
```

---

### 4. `bookFee()` — `related_transfer_id` non esiste nella tabella `transfers`

**File:** `app/Services/TransferBookingService.php` — riga 803

```php
Transfer::create([
    ...
    'related_transfer_id' => $parentTransfer->id, // ❌ non in $fillable, non in migration
]);
```

La colonna `related_transfer_id` non è né in `Transfer::$fillable` né nelle migration. Eloquent la ignora silenziosamente: il trasferimento fee viene creato **senza il collegamento al trasferimento padre**. Non è un crash, ma l'audit trail delle commissioni è incompleto.

**Fix:** Aggiungere una migration per la colonna e includerla in `Transfer::$fillable`, oppure riutilizzare `reversed_transfer_id` con un nome semantico appropriato.

---

## 🟠 BUG ALTI

### 5. `EnsureCompanyNotSuspended` — non trova l'azienda per utenti company

**File:** `app/Http/Middleware/EnsureCompanyNotSuspended.php` — righe 32–42

Il middleware cerca l'azienda tramite `Account::where('owner_user_id', $user->id)`, ma i conti aziendali usano `company_id`, non `owner_user_id`. Per un utente con `company_id != null` ma senza account personale, `$company` rimane `null` e la sospensione **non viene applicata**.

**Fix:**
```php
// Aggiungere fallback diretto tramite company_id
if (! $company && $user->company_id) {
    $company = \App\Models\Company::find($user->company_id);
}
```

---

### 6. `PaymentHandlerController` — ignora il fido (massimale) nel controllo saldo

**File:** `app/Http/Controllers/PaymentHandlerController.php` — riga 83

```php
if ($account->available_balance < $pr->amount) { // ❌ ignora fido
```

Tutti gli altri controller usano `$account->saldoDisponibile()` (che include il massimale/fido) per verificare se un pagamento è possibile. Questo controllo manuale causa un **falso "saldo insufficiente"** per utenti con fido attivo che pagano via Payment Request API.

**Fix:**
```php
if ($account->saldoDisponibile() < $pr->amount) {
    return response()->json([
        'error' => 'Saldo insufficiente (' . ky_format($account->saldoDisponibile()) . ' KY disponibili).',
    ], 422);
}
```

---

### 7. `ProcessScheduledPayments` Job — import inutilizzato in `console.php`

**File:** `routes/console.php` — riga 5

```php
use App\Jobs\ProcessScheduledPayments; // ← mai usato nel file
```

La schedulazione usa il command `payments:run-scheduled`, non il Job. L'import è un residuo di refactoring. Non è un bug funzionale, ma causa confusione su quale path sia attivo.

**Fix:** Rimuovere la riga `use App\Jobs\ProcessScheduledPayments;` da `console.php`.

---

### 8. `PaymentHandlerController` — kind `'payment_request'` non documentato

**File:** `app/Http/Controllers/PaymentHandlerController.php` — riga 98

```php
'kind' => 'payment_request', // non in TransactionFee::kindOptions() né nel CLAUDE.md
```

Il kind usato nel Payment Request API non corrisponde a nessuno dei kind definiti nel sistema fee (`TransactionFee::kindOptions()`), quindi nessuna commissione viene calcolata per questi pagamenti. Potrebbe essere intenzionale ma va documentato.

---

## 🟡 PROBLEMI MEDI

### 9. `BrokerController::dashboard` — N+1 query

**File:** `app/Http/Controllers/BrokerController.php` — righe 36–52

Per ogni azienda nella lista clienti, viene eseguita una query `Transfer::query()->...->first()` separata. Con 50+ clienti, questo causa 50+ query aggiuntive. Usare eager loading o un `JOIN` aggregato.

---

### 10. `PortalController::dashboard` — 10+ query separate per KPI

**File:** `app/Http/Controllers/PortalController.php` — dashboard

Le statistiche dei 30 giorni (`income30`, `expense30`, `incomePrev`, `expensePrev`), il trend mensile (6 iterazioni × 2 query), e i KPI KyCard eseguono ~14 query distinte ogni volta che si carica la dashboard. In produzione con traffic medio questo può diventare lento.

**Miglioramento:** Cachare con `Cache::remember()` per 5 minuti, o consolidare in una query con `UNION` / subquery.

---

### 11. `EnsureContractSigned` — utenti privati esclusi dalla firma

**File:** `app/Http/Middleware/EnsureContractSigned.php` — riga 22

```php
if (! $user->company_id) {
    return $next($request); // skip per utenti senza azienda
}
```

Gli utenti con account privato (KYP) non vengono mai obbligati a firmare il contratto, anche se `contract_force_sign` è attivo. Verificare se questa è una scelta di design voluta o un gap da coprire.

---

### 12. `RecalcAccountBalances` Command — usa `$account->update()` invece di `forceFill + save`

**File:** `app/Console/Commands/RecalcAccountBalances.php` — riga 55

Viola la convenzione del progetto. Per un comando di manutenzione eseguito manualmente potrebbe essere accettabile, ma in un contesto concorrente potrebbe sovrascrivere un saldo aggiornato da un pagamento in corso.

**Miglioramento:** Wrappare in `DB::transaction()` con `lockForUpdate()`.

---

### 13. `bookFee()` — usa `DB::transaction()` annidata senza savepoint

La funzione `bookFee()` chiama `DB::transaction()` dall'interno di `bookSettledTransfer()`, che è già dentro una transazione esterna. In Laravel/SQLite le transazioni annidate usano savepoint, ma in MySQL potrebbe causare comportamenti inattesi se una inner transaction viene rollbackata (il MySQL non supporta savepoint su rollback implicito).

---

## 🟢 MIGLIORAMENTI / BASSO IMPATTO

### 14. `helpers.php` — manca `ky_input()` nella documentazione CLAUDE.md

`CLAUDE.md` documenta `ky_format()` e `ky_to_cents()` ma non `ky_input()`, che è usata nei template Blade per pre-popolare i campi `<input type="number">`. Aggiungere alla documentazione.

---

### 15. `Transfer` — manca relationship per fee transfers

`Transfer` ha `reversedTransfer` e `reversalChildren`, ma non ha una relazione per i fee transfers (`reversed_transfer_id` usato come link). Aggiungere `relatedTransfer()` e `feeTransfers()` per query più pulite.

---

### 16. `CashbackService` — `portal_cashback` non in `TransactionFee::kindOptions()`

Il kind `portal_cashback` non è nell'elenco delle opzioni configurabili delle commissioni. Se un admin configura una fee su `*` (tutti i tipi), il cashback applicherebbe una commissione al cashback stesso (loop potenziale — già prevenuto da `if ($transfer->kind === 'portal_cashback') return;` in `CashbackService`). Ma `portal_cashback` dovrebbe essere esplicitamente escluso dalla lista fee.

---

### 17. `NfcCardPaymentController` — autenticazione merchant non verificata

**File:** `app/Http/Controllers/NfcCardPaymentController.php` — `createRequest()`

Il merchant che invia la richiesta di pagamento NFC non viene verificato per essere il titolare dell'account destinatario. Chiunque sia autenticato può richiedere un pagamento verso qualsiasi azienda. Aggiungere controllo `$request->user()->company_id === $merchantCompany->id`.

---

### 18. Manca indice su `transfers.idempotency_key` per ricerca duplicati

La migration crea l'indice `unique` su `idempotency_key`, ma `bookFee()` interroga con `Transfer::where('idempotency_key', $idempotencyKey)->exists()` in un context separato dal lock. L'indice unique è corretto, ma la query `exists()` prima di `create()` è ridondante — basta gestire `UniqueConstraintViolationException` dal `create()`.

---

### 19. `ProcessDueInstallments` — non schedulato su coda, eseguito come Job

Il Job `ProcessDueInstallments` è schedulato con `Schedule::job()` ma manda i pagamenti delle rate direttamente. Se la queue è lenta o il worker è fermo, le rate scadono senza essere processate. Considerare di usare `Schedule::command()` come per i pagamenti programmati, oppure aggiungere un `tries = 3` e `backoff`.

---

## Struttura e architettura — osservazioni positive

- ✅ `TransferBookingService` ha una struttura solida: idempotency key obbligatoria, lock pessimistici, `AuditLog` su ogni operazione.
- ✅ `LedgerEntry` in partita doppia correttamente implementato per tutti i transfer normali.
- ✅ Middleware stack del portale completo e ben stratificato.
- ✅ Rate limiting configurato per pagamenti, operazioni finanziarie e API.
- ✅ `CashbackService` protetto da loop infiniti e fallisce silenziosamente (fire-and-forget).
- ✅ Gestione sottoconti con limiti separati (spending_limit, daily, monthly) ben implementata.
- ✅ WebAuthn/Passkey e 2FA TOTP correttamente integrati nel flusso auth.
- ✅ Notifiche multi-canale (mail, database, broadcast, web push) con preferenze utente rispettate.

---

## Priorità di intervento raccomandata

1. **Subito** — Fix bug #3 (`$account->balance` → `$account->available_balance`) — causa alert spam in produzione
2. **Subito** — Fix bug #1 e #2 (`bookFee` LedgerEntry) — causa crash ogni volta che una fee viene addebitata
3. **Breve** — Fix bug #5 (middleware sospensione) — gap di sicurezza
4. **Breve** — Fix bug #4 (`related_transfer_id` + migration) — audit trail incompleto
5. **Medio** — Fix bug #6 (PaymentHandler ignora fido) — UX errata
6. **Basso** — Miglioramenti performance dashboard e cleanup import inutilizzati
