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
        // Proxy fidati per X-Forwarded-* — NON usare '*' (header falsificabile).
        // Default sicuro: loopback + reti private. Override via env TRUSTED_PROXIES.
        // Vedi App\Support\TrustedProxies per la motivazione di sicurezza.
        $middleware->trustProxies(at: \App\Support\TrustedProxies::resolve());
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
    