#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# KMoney — Hook PRIMA del deploy (gira sulla nuova release, prima dello swap)
# In Envoyer: Deployment Hooks → Before This Release Is Active
# ─────────────────────────────────────────────────────────────────────────────
set -e

cd {{ release }}

echo "[before-deploy] Installazione dipendenze Composer..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[before-deploy] Build assets Vite..."
npm ci --no-audit
npm run build

echo "[before-deploy] Esecuzione migration..."
php artisan migrate --force

echo "[before-deploy] Storage link..."
php artisan storage:link --quiet || true

echo "[before-deploy] Cache configurazione..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "[before-deploy] Completato."
