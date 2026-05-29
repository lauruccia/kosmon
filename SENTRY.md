# Integrazione Sentry — KMoney

## Setup

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=https://xxx@oXXXX.ingest.sentry.io/XXXX
```

Oppure imposta manualmente nel `.env`:
```
SENTRY_LARAVEL_DSN=https://xxx@oXXXX.ingest.sentry.io/XXXX
SENTRY_TRACES_SAMPLE_RATE=0.1
```

## Configurazione inclusa

- `config/sentry.php` — configurazione completa con ignore_exceptions, breadcrumbs, tracing
- `bootstrap/app.php` — exception handler che chiama `captureException()` se Sentry è configurato
- Eccezioni ignorate: 404, 403, 422, AuthenticationException, ValidationException (rumore a basso valore)

## Verifica installazione

```bash
php artisan sentry:test
```

## Queue worker monitoring

Aggiungi al Supervisor il parametro `--sentry-dsn` oppure configura il worker con:
```
SENTRY_LARAVEL_DSN=...
```
I job falliti vengono automaticamente catturati grazie a `queue_job_try_capture=true`.

## Release tracking (opzionale, Envoyer/CI)

Nel deploy hook di Envoyer aggiungere:
```bash
php artisan sentry:create-release --release=$RELEASE_VERSION
```
