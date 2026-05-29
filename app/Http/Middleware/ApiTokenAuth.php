<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
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

        $token->update(['last_used_at' => now()]);

        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_company', $token->company);

        return $next($request);
    }
}
