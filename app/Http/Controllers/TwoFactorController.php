<?php

namespace App\Http\Controllers;

use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    // -- Setup (portale autenticato) -------------------------------------------

    /**
     * Show the 2FA security settings page.
     */
    public function showSetup(Request $request): View
    {
        $user    = $request->user();
        $enabled = $user->two_factor_confirmed_at !== null;

        // If setup is in progress (secret stored in session), show QR
        $pendingSecret = $request->session()->get('2fa_pending_secret');
        $qrUri         = null;

        if ($pendingSecret) {
            $qrUri = Totp::getUri(
                secret:      $pendingSecret,
                accountName: $user->email,
                issuer:      config('app.name', 'KMoney'),
            );
        }

        $recoveryCodesCount = $enabled
            ? count($user->two_factor_recovery_codes ?? [])
            : 0;

        return view('portal.security', compact('enabled', 'pendingSecret', 'qrUri', 'recoveryCodesCount'));
    }

    /**
     * Generate a new secret and store it in session (not yet confirmed).
     */
    public function startSetup(Request $request): RedirectResponse
    {
        $request->session()->put('2fa_pending_secret', Totp::generateSecret());

        return redirect()->route('portal.security')
            ->with('portal_success', 'Scansiona il QR code con la tua app e inserisci il codice per attivare.');
    }

    /**
     * Confirm the OTP entered during setup -- activate 2FA and generate recovery codes.
     */
    public function confirmSetup(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $secret = $request->session()->get('2fa_pending_secret');

        if (! $secret) {
            return redirect()->route('portal.security')
                ->withErrors(['code' => 'Sessione scaduta. Riprova ad avviare la configurazione.']);
        }

        if (! Totp::verify($secret, $request->input('code'))) {
            return redirect()->route('portal.security')
                ->withErrors(['code' => 'Codice non valido. Riprova.']);
        }

        // Generate recovery codes (plaintext for one-time display, hashed for storage)
        $plainCodes  = Totp::generateRecoveryCodes(8);
        $hashedCodes = array_map(fn ($c) => bcrypt($c), $plainCodes);

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_confirmed_at'   => now(),
            'two_factor_recovery_codes' => $hashedCodes,
        ])->save();

        $request->session()->forget('2fa_pending_secret');
        $request->session()->put('two_factor_verified', true);
        // Store plaintext codes in session for one-time display only
        $request->session()->put('2fa_recovery_codes_plain', $plainCodes);

        return redirect()->route('portal.2fa.recovery-codes');
    }

    /**
     * Show recovery codes once (pulled from session -- cannot be revisited).
     */
    public function showRecoveryCodes(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->pull('2fa_recovery_codes_plain');

        if (! $codes) {
            return redirect()->route('portal.security');
        }

        return view('portal.recovery-codes', compact('codes'));
    }

    /**
     * Regenerate recovery codes after password confirmation.
     */
    public function regenerateCodes(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_confirmed_at) {
            return redirect()->route('portal.security');
        }

        $plainCodes  = Totp::generateRecoveryCodes(8);
        $hashedCodes = array_map(fn ($c) => bcrypt($c), $plainCodes);

        $user->forceFill(['two_factor_recovery_codes' => $hashedCodes])->save();
        $request->session()->put('2fa_recovery_codes_plain', $plainCodes);

        return redirect()->route('portal.2fa.recovery-codes');
    }

    /**
     * Disable 2FA after confirming current password.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $request->session()->forget('two_factor_verified');

        return redirect()->route('portal.security')
            ->with('portal_success', 'Autenticazione a due fattori disattivata.');
    }

    // -- Challenge (verifica OTP dopo login) -----------------------------------

    /**
     * Show the 2FA OTP challenge page.
     */
    public function showChallenge(): View
    {
        return view('2fa.challenge');
    }

    /**
     * Validate OTP (or recovery code) and mark the session as 2FA-verified.
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $request->validate([
            'code'          => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user   = $request->user();
        $secret = $user->two_factor_secret;

        // 1. Try TOTP code
        $code = trim($request->input('code', ''));
        if ($code !== '' && $secret && Totp::verify($secret, $code)) {
            $request->session()->put('two_factor_verified', true);
            return redirect()->intended(route('portal.dashboard'));
        }

        // 2. Try recovery code
        $recovery = trim($request->input('recovery_code', ''));
        if ($recovery !== '') {
            $stored = $user->two_factor_recovery_codes ?? [];
            foreach ($stored as $index => $hash) {
                if (Hash::check($recovery, $hash)) {
                    // Consume this code (one-time use)
                    unset($stored[$index]);
                    $user->forceFill([
                        'two_factor_recovery_codes' => array_values($stored),
                    ])->save();

                    $request->session()->put('two_factor_verified', true);
                    return redirect()->intended(route('portal.dashboard'));
                }
            }
        }

        return back()->withErrors([
            'code' => 'Codice non valido o scaduto. Riprova.',
        ]);
    }
}
