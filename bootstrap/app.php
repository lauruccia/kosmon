<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy fidati per X-Forwarded-*: configurati in config/trustedproxy.php
        // (letto dal middleware TrustProxies a request time). NIENTE
        // trustProxies(at:) qui: questa callback gira prima del caricamento
        // della config e con `config:cache` env() restituirebbe null.
        $middleware->web(append: [
            \App\Http\Middleware\ContentSecurityPolicy::class,
        ]);
        $middleware->alias([
            'onboarding' => \App\Http\Middleware\EnsureOnboardingComplete::class,
            'twofactor'  => \App\Http\Middleware\TwoFactorChallenge::class,
            'api.token'  => \App\Http\Middleware\ApiTokenAuth::class,
            'not.suspended' => \App\Http\Middleware\EnsureCompanyNotSuspended::class,
            'step.up'       => \App\Http\Middleware\RequireStepUp::class,
            'contract'      => \App\Http\Middleware\EnsureContractSigned::class,
            'backoffice'    => \App\Http\Middleware\EnsureCanAccessBackoffice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry: attivo solo se DSN configurato E package installato
        // Per attivare: composer require sentry/sentry-laravel
        //               poi aggiungere SENTRY_LARAVEL_DSN=https://xxx@oXXX.ingest.sentry.io/XXX nel .env
        if (config('sentry.dsn') && class_exists(\Sentry\Laravel\Integration::class)) {
            \Sentry\Laravel\Integration::handles($exceptions);
        }

        // CSRF/token scaduto (419): niente pagina "Page Expired" grezza.
        // Capita tipicamente su form rimasti aperti a lungo (es. logout dopo
        // inattività) — riportiamo l'utente al login con un messaggio chiaro
        // invece di mostrare l'errore Laravel di default.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sessione scaduta. Ricarica la pagina ed effettua nuovamente il login.',
                ], 419);
            }

            return redirect()->route('login')
                ->with('status', 'La tua sessione è scaduta per inattività. Effettua nuovamente il login.');
        });
    })->create();
