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

        $scriptSrc = "'self' 'unsafe-inline' https://js.stripe.com";
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
