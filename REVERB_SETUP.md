# Setup Laravel Reverb (WebSocket real-time)

Il sistema usa polling AJAX come fallback — funziona senza Reverb.
Seguire questi passi per attivare i WebSocket real-time su QR e NFC.

## 1. Installa le dipendenze

```bash
composer require laravel/reverb
php artisan reverb:install
npm install laravel-echo pusher-js
npm run build
```

## 2. Variabili .env

Copia dal `.env.example` la sezione Broadcasting e compila:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=kmoney-app
REVERB_APP_KEY=genera_una_stringa_random_32_char
REVERB_APP_SECRET=genera_una_stringa_random_32_char
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http   # https in produzione

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Dopo aver modificato `.env`:
```bash
npm run build
php artisan config:clear
```

## 3. Avvio in sviluppo

```bash
# Terminale 1 — server Laravel
php artisan serve

# Terminale 2 — Reverb WebSocket server
php artisan reverb:start

# Terminale 3 — queue worker (per broadcast via queue)
php artisan queue:work
```

## 4. Produzione (Supervisor)

Aggiungere a Supervisor (oltre al worker già esistente):

```ini
[program:kmoney-reverb]
command=php /var/www/kmoney-app/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/kmoney-app
autostart=true
autorestart=true
user=www-data
```

Nginx: aggiungi proxy WebSocket nella config del dominio:

```nginx
location /app {
    proxy_pass             http://127.0.0.1:8080;
    proxy_http_version     1.1;
    proxy_set_header       Upgrade $http_upgrade;
    proxy_set_header       Connection "Upgrade";
    proxy_set_header       Host $host;
    proxy_cache_bypass     $http_upgrade;
}
```

## Come funziona

- Senza Reverb (BROADCAST_CONNECTION=log): QR e NFC usano polling AJAX ogni 2.5–3s
- Con Reverb attivo: appena il pagatore paga, il merchant vede l'aggiornamento istantaneamente via WebSocket senza polling
- Il fallback è automatico: se Echo non si connette, il polling parte comunque
