<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aggiunge l'header Content-Security-Policy a tutte le risposte web.
 *
 * Whitelist costruita sulle dipendenze effettive:
 *  - Stripe.js (pagamenti KY Card)
 *  - Laravel Reverb (WebSocket, porta/host da config)
 *  - Sentry ingest (error monitoring)
 *  - data: URI (QR code generati inline, font embed)
 *
 * Per ambiente di sviluppo (local/development) viene aggiunto 'unsafe-eval'
 * per supportare Vite HMR / source-maps.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Non aggiungere CSP su redirect o risposte binarie (PDF, export...)
        if (! method_exists($response, 'header')) {
            return $response;
        }

        // Evita di sovrascrivere CSP già impostato (es. da un controller specifico)
        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $csp = $this->buildPolicy();
        $response->headers->set('Content-Security-Policy', $csp);

        // HSTS — forza HTTPS per 1 anno (inclusi sottodomini)
        if (! app()->environment('local', 'development', 'testing')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Nessun referrer verso siti terzi (protegge URL interni nei log di terze parti)
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Disabilita API browser non necessarie (geolocalizzazione, microfono, camera)
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(self)'
        );

        return $response;
    }

    private function buildPolicy(): string
    {
        $isLocal = app()->environment('local', 'development', 'testing');

        // WebSocket Reverb — usa host/porta da config se disponibili
        $reverbHost = config('reverb.servers.reverb.host', config('broadcasting.connections.reverb.host', ''));
        $reverbPort = config('reverb.servers.reverb.port', config('broadcasting.connections.reverb.port', ''));
        $reverbScheme = config('reverb.servers.reverb.scheme', 'https');

        $wsScheme = ($reverbScheme === 'https') ? 'wss' : 'ws';

        if ($reverbHost) {
            $wsOrigin = $reverbPort
                ? "{$wsScheme}://{$reverbHost}:{$reverbPort}"
                : "{$wsScheme}://{$reverbHost}";
        } else {
            // Fallback: consenti wss same-origin (Reverb stesso server)
            $wsOrigin = "wss://{$_SERVER['HTTP_HOST']}";
        }

        // In sviluppo aggiungiamo anche ws:// per Vite HMR
        if ($isLocal) {
            $wsOrigin .= ' ws://localhost:* wss://localhost:*';
        }

        // cdn.jsdelivr.net e cdnjs.cloudflare.com: Chart.js caricato via CDN nei grafici
        // (dashboard portale, analytics/report admin, merchant-report) — senza questi host
        // il browser blocca lo script e i grafici restano vuoti senza errore visibile.
        $scriptSrc = "'self' 'unsafe-inline' https://js.stripe.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com";
        if ($isLocal) {
            // Vite HMR usa eval() per source maps
            $scriptSrc .= " 'unsafe-eval' http://localhost:* https://localhost:*";
        }

        $directives = [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' {$wsOrigin} https://api.stripe.com https://*.ingest.sentry.io",
            "frame-src 'self' https://js.stripe.com https://*.stripe.com",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests",
        ];

        return implode('; ', $directives);
    }
}
