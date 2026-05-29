# Guida Migrazione Database — Vecchio Sito → KMoney App

> Documento generato il 27/05/2026 — aggiornare prima del go-live se il vecchio sito continua ad accettare operazioni.

---

## Riepilogo dati da migrare

| Tabella vecchio DB | Dati trovati | Destinazione nuova app |
|---|---|---|
| `users` | **1.211 utenti** (ID 3-1232) | `companies` + `users` + `accounts` |
| `transactions` | **311 righe** | `transfers` (da cassa circuito) |
| `transactions_new` | **118 righe** | `transfers` (da cassa circuito) |
| `balance_transfers` | **50 bonifici** | `transfers` (peer-to-peer) |
| `deposits` | **75 depositi EUR→KY** | `transfers` (da cassa circuito) |
| `beneficiaries` | 63 record | Non serve migrare (sostituita dalla directory aziende) |
| Password (bcrypt) | ✅ compatibili | Trasferimento diretto |

---

## Mapping concettuale

### Vecchio `users` → Nuova app

Ogni record `users` del vecchio sito rappresentava un'azienda/utente. Nella nuova app questo corrisponde a **3 record**:

```
vecchio users (1 riga)
    ├── companies      → company_name, vat_number, email, slug (da username)
    ├── users          → firstname+lastname, email, password (hash riutilizzabile)
    └── accounts       → balance × 100 = available_balance (bigint centesimi)
```

### Saldo KY: conversione

Il vecchio DB salva il saldo come `DECIMAL` (es. `30.00`).
La nuova app usa **bigint in centesimi** (es. `3000`).

```
vecchio balance = 30.00  →  nuovo available_balance = 3000
vecchio balance = 279.00 →  nuovo available_balance = 27900
```

### Tipi di transazione → Transfers

| Vecchio `remark` | Nuovo `kind` | Mittente | Destinatario |
|---|---|---|---|
| `balance_add` | `admin_credit` | cassa_circuito | account azienda |
| `admin_deposit` | `admin_credit` | cassa_circuito | account azienda |
| `deposit` | `admin_credit` | cassa_circuito | account azienda |
| `own_bank_transfer` (coppia -/+) | `trade_payment` | account mittente | account destinatario |
| `balance_transfers` completati | `trade_payment` | account mittente | account destinatario |

---

## ISTRUZIONI RAPIDE (3 comandi)

Apri il terminale nella cartella `C:\laragon\www\kmoney-app` ed esegui nell'ordine:

```bash
# 1. Pulisce il DB (rimuove i dati demo di test)
php artisan migrate:fresh

# 2. Crea i ruoli di sistema
php artisan db:seed --class=RolesAndPermissionsSeeder

# 3. Importa tutto dal vecchio sito (cambia il percorso al dump!)
php artisan kmoney:import-old-data "C:\percorso\al\dump.sql" --admin-email=admin@kosmomoney.com --admin-password=LAtuaPasswordSicura
```

Fatto. Tempo stimato: 2-5 minuti.

---

## FASI DI MIGRAZIONE (dettaglio)

---

### FASE 0 — Preparazione (OBBLIGATORIA)

**0.1 — Backup del nuovo DB (se già ci sono dati)**
```bash
mysqldump kmoney_app > backup_kmoney_app_PRIMA_MIGRAZIONE.sql
```

**0.2 — Importa il vecchio DB in un database separato sul tuo server**
Crea un database ausiliario chiamato `kmoney_old` e importa il dump:
```bash
mysql -u root -p -e "CREATE DATABASE kmoney_old CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p kmoney_old < "kosmomoney_updated_kmoney_db (2)270526.sql"
```

**0.3 — Aggiungi la connessione `old` al config Laravel**

In `config/database.php`, aggiungi dentro `connections`:
```php
'old' => [
    'driver'    => 'mysql',
    'host'      => env('DB_OLD_HOST', '127.0.0.1'),
    'port'      => env('DB_OLD_PORT', '3306'),
    'database'  => env('DB_OLD_DATABASE', 'kmoney_old'),
    'username'  => env('DB_OLD_USERNAME', 'root'),
    'password'  => env('DB_OLD_PASSWORD', ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

E nel `.env`:
```
DB_OLD_HOST=127.0.0.1
DB_OLD_PORT=3306
DB_OLD_DATABASE=kmoney_old
DB_OLD_USERNAME=root
DB_OLD_PASSWORD=tua_password
```

**0.4 — Verifica che la nuova app abbia le migration eseguite**
```bash
php artisan migrate:status
```
Tutte le migration devono risultare `Yes` (Ran).

---

### FASE 1 — Migrazione Utenti, Aziende e Conti

Esegui lo script di migrazione incluso in questo repository:

```bash
php artisan db:seed --class=ImportFromOldDbSeeder
```

Lo script fa, nell'ordine:
1. Legge tutti gli utenti dal vecchio DB (`kmoney_old.users`)
2. Per ogni utente crea:
   - Un record `companies` (status `approved`, kyc_status in base al campo `kv`)
   - Un record `users` (email, password hash, nome)
   - Un record `accounts` (tipo `main`, saldo = vecchio `balance × 100`)
3. Salva una tabella di mapping `old_user_id → new_company_id + new_account_id`

**Verifica dopo Fase 1:**
```sql
SELECT COUNT(*) FROM companies;  -- deve essere ≈1211
SELECT COUNT(*) FROM users;      -- deve essere ≈1211
SELECT COUNT(*) FROM accounts;   -- deve essere ≈1211
```

---

### FASE 2 — Migrazione Transazioni (Crediti Admin)

Esegui:
```bash
php artisan db:seed --class=ImportTransactionsSeeder
```

Lo script migra:
- `transactions` con remark `balance_add`, `admin_deposit`, `deposit` → `transfers` kind=`admin_credit`
- `transactions_new` — stesse tipologie
- La `cassa_circuito` (system account) è il mittente di tutti questi trasferimenti

**Verifica:**
```sql
SELECT COUNT(*) FROM transfers WHERE kind = 'admin_credit';
-- attesi: ~200 (transactions + transactions_new con remark crediti)
```

---

### FASE 3 — Migrazione Trasferimenti Peer-to-Peer

```bash
php artisan db:seed --class=ImportBalanceTransfersSeeder
```

Lo script migra:
- I 50 `balance_transfers` con `status=1` (completati)
- La coppia mittente/destinatario viene ricostruita tramite la tabella `beneficiaries`

**Verifica:**
```sql
SELECT COUNT(*) FROM transfers WHERE kind = 'trade_payment';
-- attesi: ~50
```

---

### FASE 4 — Riconciliazione Saldi (CRITICA)

Dopo le fasi 1-3, **verifica che i saldi siano coerenti**:

```bash
php artisan kmoney:reconcile-balances
```

Lo script confronta:
- Il saldo dell'account nella nuova app
- La somma di tutti i trasferimenti in entrata meno quelli in uscita

Se ci sono discrepanze, genera un report `storage/logs/reconciliation_YYYYMMDD.log`.

> **Nota:** È normale che alcuni saldi non tornino perfettamente dalla sola storia delle transazioni, perché il vecchio sito poteva avere operazioni non tracciate. In quel caso, il saldo importato direttamente dal campo `users.balance` ha la precedenza — è il dato più affidabile.

---

### FASE 5 — Verifica Finale e Go-Live

**5.1 — Test utenti campione**
Prendi 5-10 utenti reali e verifica manualmente:
- Email ✅
- Saldo KY ✅
- Password funzionante (chiedi loro di fare login)

**5.2 — Verifica admin**
Accedi con l'admin e controlla:
- Dashboard KPI circuito
- Lista aziende con saldi
- Audit log

**5.3 — Comunicazione agli utenti**
Prepara un'email per comunicare:
- Il nuovo indirizzo del portale
- Che le credenziali rimangono invariate
- I nuovi servizi disponibili (QR, rate, netting, API...)

---

## Note importanti

### Password
Le password del vecchio sito usano `bcrypt` di Laravel, esattamente come il nuovo. Non serve fare reset password — gli utenti possono fare login con le stesse credenziali.

### Account number
Il vecchio formato era `kmny230220302546`. Il nuovo app usa `KY` + 14 caratteri alfanumerici. Non serve mantenere il vecchio numero — il numero account viene rigenerato automaticamente dalla nuova app.

### KYC status mapping
| Vecchio `kv` | Nuovo `kyc_status` | Nuovo `status` |
|---|---|---|
| `0` (non verificato) | `pending` | `pending_review` |
| `2` (in attesa) | `pending` | `active` |
| `1` (verificato) | `approved` | `approved` |

### Utenti duplicati
Alcuni utenti nel vecchio DB potrebbero avere la stessa email (errori storici). Lo script gestisce questo caso saltando i duplicati e loggandoli in `storage/logs/migration_duplicates.log`.

---

## Rollback

Se qualcosa va storto, è sufficiente:
```bash
php artisan migrate:fresh
```
e rieseguire dall'inizio. Il vecchio DB non viene mai modificato.
