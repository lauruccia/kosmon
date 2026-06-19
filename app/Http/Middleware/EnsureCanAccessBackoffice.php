<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consente l'accesso solo agli utenti con accesso al backoffice (admin / permesso backoffice.access).
 * Chiude il buco di autorizzazione sui controller admin le cui rotte stavano nel gruppo portale
 * senza guardia di ruolo (cashback, settori, menu-visibility, card NFC).
 */
class EnsureCanAccessBackoffice
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessBackoffice(), 403);

        return $next($request);
    }
}
