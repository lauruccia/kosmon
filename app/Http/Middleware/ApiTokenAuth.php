<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Notifications\ApiTokenNewIpNotification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'Missing Authorization header'], 401);
        }

        $raw  = substr($header, 7);
        $hash = hash('sha256', $raw);

        $token = ApiToken::where('token_hash', $hash)
            ->with('company.accounts')
            ->first();

        if (! $token) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if ($token->isExpired()) {
            return response()->json(['error' => 'Token expired'], 401);
        }

        if (! $token->can($ability)) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        $currentIp  = $request->ip();
        $previousIp = $token->last_used_ip;
        $ipChanged  = $previousIp && $previousIp !== $currentIp;

        // Evita una scrittura su DB a OGNI chiamata (write amplification su API
        // ad alta frequenza). Aggiorna solo se: l'IP è cambiato (rilevante per
        // sicurezza), oppure l'ultimo uso non è registrato o è più vecchio di 60s.
        $stale = $token->last_used_at === null
            || $token->last_used_at->lt(now()->subSeconds(60));

        if ($ipChanged || $stale) {
            // updateQuietly: niente eventi né touch di updated_at, solo i due campi.
            $token->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => $currentIp,
            ])->saveQuietly();
        }

        // Notifica se l'IP cambia rispetto all'ultima chiamata nota
        if ($ipChanged) {
            $creator = $token->creator ?? $token->company?->users()->where('role', 'owner')->first();
            if ($creator) {
                $creator->notify(new ApiTokenNewIpNotification($token, $currentIp, $previousIp));
            }
        }

        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_company', $token->company);

        return $next($request);
    }
}
