# Migrazione SQLite → MySQL

## 1. Crea il database MySQL

```sql
CREATE DATABASE kmoney CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kmoney_user'@'localhost' IDENTIFIED BY 'scegli_password_sicura';
GRANT ALL PRIVILEGES ON kmoney.* TO 'kmoney_user'@'localhost';
FLUSH PRIVILEGES;
```

## 2. Aggiorna il file .env

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kmoney
DB_USERNAME=kmoney_user
DB_PASSWORD=scegli_password_sicura
```

## 3. Esegui le migration

```bash
php artisan migrate --force
```

## 4. (Opzionale) Importa i dati da SQLite

Se hai dati in SQLite da portare in MySQL, usa questo script:

```bash
# Installa il tool di conversione
pip install sqlite3mysql

# Converti il database
sqlite3mysql -f database/database.sqlite -d kmoney -u kmoney_user -p scegli_password_sicura
```

## 5. Verifica

```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> \App\Models\User::count();
```

## Note tecniche

- Tutte le migration sono MySQL-compatibili (Laravel abstrae SQLite/MySQL)
- Usato motore **InnoDB** (obbligatorio per foreign keys e row-level locking)
- Charset **utf8mb4** per supporto completo Unicode (emoji, caratteri speciali)
- La migration `2026_05_26_100000_add_mysql_performance_indexes.php` aggiunge
  indici composti ottimizzati per le query più frequenti del circuito

## Stack produzione consigliato

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kmoney
DB_USERNAME=kmoney_user
DB_PASSWORD=password_molto_sicura

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```
