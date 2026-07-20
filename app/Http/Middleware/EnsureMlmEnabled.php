<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocca l'accesso alle rotte MLM (KNM) quando il programma agenti è
 * disattivato su questa installazione (config('kmoney.mlm_enabled'), env
 * MLM_ENABLED — vedi config/kmoney.php). Restituisce 404 anziché 403: a
 * flag spento la feature non deve risultare "vietata", ma inesistente,
 * coerente con le voci di menu che in quel caso sono nascoste.
 *
 * Alias registrato in bootstrap/app.php: 'mlm.enabled'.
 */
class EnsureMlmEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('kmoney.mlm_enabled'), 404);

        return $next($request);
    }
}
