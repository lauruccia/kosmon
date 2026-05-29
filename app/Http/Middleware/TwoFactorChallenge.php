<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorChallenge
{
    /**
     * If the authenticated user has 2FA enabled but the current session
     * has not completed the OTP challenge, redirect to the challenge page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // 2FA not configured for this user — pass through
        if (! $user->two_factor_confirmed_at) {
            return $next($request);
        }

        // Already verified in this session — pass through
        if ($request->session()->get('two_factor_verified')) {
            return $next($request);
        }

        // Do not redirect challenge routes themselves (avoid loop)
        if ($request->routeIs('2fa.*')) {
            return $next($request);
        }

        return redirect()->route('2fa.challenge');
    }
}
