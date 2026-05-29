# KMoney — Guida al Deploy in Produzione

Questa guida copre il deploy su **Laravel Forge** + **Envoyer** con **Redis** per session/cache/queue, MySQL e queue worker via Supervisor.

---

## Indice

1. [Prerequisiti](#1-prerequisiti)
2. [Provisioning server con Forge](#2-provisioning-server-con-forge)
3. [MySQL — creazione database](#3-mysql--creazione-database)
4. [Redis — configurazione](#4-redis--configurazione)
5. [Configurazione sito Forge](#5-configurazione-sito-forge)
6. [Variabili d'ambiente (.env produzione)](#6-variabili-dambiente-env-produzione)
7. [Deploy con Envoyer](#7-deploy-con-envoyer)
8. [Queue worker con Supervisor](#8-queue-worker-con-supervisor)
9. [Storage — immagini e documenti KYC](#9-storage--immagini-e-documenti-kyc)
10. [Checklist go-live](#10-checklist-go-live)
11. [Comandi utili post-deploy](#11-comandi-utili-post-deploy)

---

## 1. Prerequisiti

| Componente | Versione minima |
|---|---|
| PHP | 8.2 |
| MySQL / MariaDB | 8.0 / 10.6 |
| Redis | 7.x |
| Node.js | 20 LTS (solo per build asset) |
| Composer | 2.x |

**Estensioni PHP richieste:** `pdo_mysql`, `redis` (phpredis), `gd` o `imagick`, `bcmath`, `mbstring`, `xml`, `curl`, `zip`.

---

## 2. Provisioning server con Forge

1. Accedi a [forge.laravel.com](https://forge.laravel.com) → **Create Server**
2. Scegli provider (DigitalOcean / AWS / Hetzner) e regione `eu` (es. Frankfurt o Amsterdam)
3. Tipo consigliato: **2 vCPU, 4 GB RAM** (scalabile in seguito)
4. Stack: **PHP 8.3**, **MySQL 8.0**, **Redis**
5. Dopo il provisioning (~5 min), Forge ti fornisce l'IP del server

**Accesso SSH:**
```bash
ssh forge@<IP_SERVER>
```

---

## 3. MySQL — creazione database

Accedi a Forge → **Databases** oppure via SSH:

```sql
mysql -u root -p

CREATE DATABASE kmoney CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kmoney_user'@'localhost' IDENTIFIED BY 'PASSWORD_SICURA';
GRANT ALL PRIVILEGES ON kmoney.* TO 'kmoney_user'@'localhost';
FLUSH PRIVILEGES;
```

> Genera la password con: `openssl rand -base64 32`

---

## 4. Redis — configurazione

Forge installa Redis automaticamente. Verifica che sia attivo:

```bash
systemctl status redis-server
redis-cli ping   # deve rispondere: PONG
```

Per abilitare l'autenticazione Redis (consigliato):

```bash
sudo nano /etc/redis/redis.conf
# Aggiungi/modifica:
requirepass TUA_REDIS_PASSWORD
```

```bash
sudo systemctl restart redis-server
```

Testa:
```bash
redis-cli -a TUA_REDIS_PASSWORD ping
```

---

## 5. Configurazione sito Forge

1. Forge → **Sites** → **New Site**
   - Domain: `kmoney.it`
   - Project type: `Laravel`
   - Web directory: `/public`

2. **Nginx** → sostituisci con il contenuto di `deploy/nginx/kmoney.conf`
   - Adatta `server_name` e i path SSL

3. **SSL** → usa il pulsante "Let's Encrypt" in Forge per il certificato gratuito

4. **PHP-FPM**: assicurati di usare PHP 8.3:
   ```bash
   sudo update-alternatives --set php /usr/bin/php8.3
   ```

---

## 6. Variabili d'ambiente (.env produzione)

In Forge → **Sites** → `kmoney.it` → **Environment**, incolla e adatta:

```ini
APP_NAME=KMoney
APP_ENV=production
APP_KEY=                          # genera con: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://kmoney.it

APP_LOCALE=it
APP_FALLBACK_LOCALE=it
APP_FAKER_LOCALE=it_IT
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

# Log
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=error

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kmoney
DB_USERNAME=kmoney_user
DB_PASSWORD=PASSWORD_SICURA

# Redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=TUA_REDIS_PASSWORD
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2

# Session / Cache / Queue → tutti su Redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_DOMAIN=kmoney.it
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# Mail (esempio con Resend)
MAIL_MAILER=resend
RESEND_KEY=re_xxxxxxxxxxxxxxxxxxxx
MAIL_FROM_ADDRESS="noreply@kmoney.it"
MAIL_FROM_NAME="KMoney"

# Filesystem
FILESYSTEM_DISK=local             # o 's3' se usi S3 per i documenti KYC

# AWS S3 (documenti KYC — opzionale)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=eu-south-1
AWS_BUCKET=kmoney-docs

VITE_APP_NAME="KMoney"
```

> ⚠️ Dopo aver salvato l'`.env`, esegui sempre `php artisan config:cache` per rendere effettive le modifiche.

---

## 7. Deploy con Envoyer

### Setup progetto

1. Accedi a [envoyer.io](https://envoyer.io) → **New Project**
   - Nome: `KMoney`
   - Repo: `github.com/tuo-org/kmoney-app`
   - Branch: `main`

2. **Servers** → Aggiungi il server Forge:
   - Host: `<IP_SERVER>`
   - User: `forge`
   - Deploy path: `/home/forge/kmoney.it`

3. **Linked Folders** (condivisi tra le release):
   ```
   storage → /home/forge/kmoney.it/shared/storage
   ```

4. **Deployment Hooks** — configura nell'ordine:

   | Quando | Script |
   |---|---|
   | Before This Release Is Active | contenuto di `deploy/hooks/before-deploy.sh` |
   | After This Release Is Active | contenuto di `deploy/hooks/after-deploy.sh` |

5. **Health Check**: aggiungi `https://kmoney.it/up` (Laravel include questo endpoint di default)

### Primo deploy manuale

Sul server, prima del primo deploy Envoyer:

```bash
# Crea la struttura shared
mkdir -p /home/forge/kmoney.it/shared/storage/{app/public,app/private,logs,framework/{cache,sessions,views}}

# Copia l'env di produzione
cp /home/forge/.env.kmoney /home/forge/kmoney.it/shared/.env
```

Poi lancia il deploy da Envoyer → **Deploy**.

---

## 8. Queue worker con Supervisor

### Installazione

```bash
sudo apt install supervisor -y
```

### Configurazione

Copia il file `deploy/supervisor/kmoney-worker.conf` sul server:

```bash
sudo cp /home/forge/kmoney.it/current/deploy/supervisor/kmoney-worker.conf \
        /etc/supervisor/conf.d/kmoney-worker.conf
```

Ricarica Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start kmoney-worker:*
sudo supervisorctl status
```

### Aggiornamento dopo ogni deploy

L'hook `after-deploy.sh` esegue `php artisan queue:restart` automaticamente — Supervisor rilancia i worker sul nuovo codice.

---

## 9. Storage — immagini e documenti KYC

### Disk `public` (immagini shop)

Il disk `public` scrive in `storage/app/public/` e viene servito tramite symlink:

```bash
php artisan storage:link
```

Nginx serve `/storage/` direttamente dalla shared folder (già configurato in `kmoney.conf`).

### Disk `private` (documenti KYC)

I documenti KYC sono su disk `private` (`storage/app/private/`), **non** accessibili via web. Vengono serviti solo attraverso il controller con autenticazione (`KycController::download`).

### Opzione S3 (documenti KYC in produzione)

Se preferisci S3 per i documenti KYC, aggiungi in `.env`:

```ini
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-south-1
AWS_BUCKET=kmoney-docs
```

E aggiorna `KycController::download` e `KycDocument::deleteFile` per usare `Storage::disk('s3')`.

---

## 10. Checklist go-live

Spunta ogni punto prima di puntare il DNS:

- [ ] `APP_DEBUG=false` in `.env` produzione
- [ ] `APP_ENV=production`
- [ ] `SESSION_ENCRYPT=true`
- [ ] Certificato SSL attivo (Let's Encrypt via Forge)
- [ ] `php artisan migrate --force` completato senza errori
- [ ] `php artisan storage:link` eseguito
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache` eseguiti
- [ ] Queue workers attivi: `supervisorctl status kmoney-worker:*`
- [ ] Mail di test inviata (es. reset password) e ricevuta
- [ ] Upload immagine nello shop funzionante
- [ ] Upload documento KYC funzionante e download sicuro
- [ ] Login, pagamento, notifica email — smoke test completo
- [ ] Log di errore puliti: `tail -f storage/logs/laravel.log`
- [ ] DNS puntato al server Forge
- [ ] Record MX / SPF / DKIM configurati per il dominio email mittente

---

## 11. Comandi utili post-deploy

```bash
# Svuota tutte le cache
php artisan optimize:clear

# Rigenera cache produzione
php artisan optimize

# Controlla lo stato dei job falliti
php artisan queue:failed

# Ritenta i job falliti
php artisan queue:retry all

# Cancella i job falliti vecchi
php artisan queue:flush

# Modalità manutenzione (con messaggio personalizzato)
php artisan down --message="Aggiornamento in corso, torna tra 5 minuti." --retry=300

# Riporta online
php artisan up

# Monitora i worker in tempo reale
sudo supervisorctl tail -f kmoney-worker:kmoney-worker_00 stdout

# Verifica Redis
redis-cli -a TUA_REDIS_PASSWORD info stats
```

---

> File generati automaticamente nella cartella `deploy/`:
> - `deploy/supervisor/kmoney-worker.conf` — configurazione Supervisor
> - `deploy/hooks/before-deploy.sh` — hook pre-deploy Envoyer
> - `deploy/hooks/after-deploy.sh` — hook post-deploy Envoyer
> - `deploy/nginx/kmoney.conf` — configurazione Nginx
