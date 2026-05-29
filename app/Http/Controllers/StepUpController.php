<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireStepUp;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Step-up authentication — conferma identità prima di operazioni sensibili.
 *
 * Flusso:
 *   GET  /profilo/conferma-identita  → mostra form (password o OTP se 2FA attivo)
 *   POST /profilo/conferma-identita  → verifica e segna sessione, poi redirect all'URL originale
 */
class StepUpController extends Controller
{
    public function show(Request $request): View
    {
        $user    = $request->user();
        $has2fa  = $user->two_factor_confirmed_at !== null;
        $reason  = session('step_up_reason', 'Conferma la tua identità per continuare.');

        return view('portal.step-up', compact('has2fa', 'reason'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $user   = $request->user();
        $has2fa = $user->two_factor_confirmed_at !== null;

        $request->validate([
            'password'      => ['nullable', 'string'],
            'totp_code'     => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $verified = false;

        // --- Verifica tramite OTP (se 2FA attivo e codice fornito) ---
        if ($has2fa && $request->filled('totp_code')) {
            $code = trim($request->input('totp_code'));
            if (Totp::verify($user->two_factor_secret, $code)) {
                $verified = true;
            }
        }

        // --- Verifica tramite password ---
        if (! $verified && $request->filled('password')) {
            if (Hash::check($request->input('password'), $user->password)) {
                $verified = true;
            }
        }

        if (! $verified) {
            return back()->withErrors([
                'credential' => $has2fa
                    ? 'Codice OTP o password non corretti. Riprova.'
                    : 'Password non corretta. Riprova.',
            ]);
        }

        // Segna la verifica in sessione con timestamp
        $request->session()->put('step_up_verified_at', now());
        $request->session()->forget('step_up_reason');

        $returnUrl = $request->session()->pull('step_up_return_url', route('portal.dashboard'));

        return redirect()->to($returnUrl)
            ->with('portal_success', 'Identità confermata. Puoi procedere.');
    }
}
