#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# KMoney — Hook DOPO il deploy (gira sulla release attiva)
# In Envoyer: Deployment Hooks → After This Release Is Active
# ─────────────────────────────────────────────────────────────────────────────
set -e

cd {{ release }}

echo "[after-deploy] Riavvio queue workers..."
php artisan queue:restart

echo "[after-deploy] Pulizia cache stale..."
php artisan cache:prune-stale-tags || true

echo "[after-deploy] Ricarica Octane (se attivo)..."
# php artisan octane:reload || true   # decommenta se usi Laravel Octane

echo "[after-deploy] Completato. Release attiva: {{ release }}"
