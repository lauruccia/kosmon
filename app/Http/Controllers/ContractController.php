<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Notifications\ContractOtpNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContractController extends Controller
{
    /**
     * Mostra la pagina di firma contratto.
     * Passa $canPostpone=false per i nuovi utenti o se l'admin ha forzato la firma.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->contract_signed_at) {
            return redirect()->route('portal.dashboard');
        }

        $canPostpone = $this->userCanPostpone($user);

        $settings     = \App\Models\SystemSetting::contractSettings();
        $contractHtml = $settings->renderContractText($user->company, $user);
        $contractVer  = $settings->contract_version ?? 1;

        return view('portal.contract-sign', [
            'canPostpone'  => $canPostpone,
            'user'         => $user,
            'company'      => $user->company,
            'contractHtml' => $contractHtml,
            'contractVer'  => $contractVer,
        ]);
    }

    /**
     * Invia il codice OTP per la firma contratto.
     */
    public function sendOtp(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->contract_signed_at) {
            return redirect()->route('portal.dashboard');
        }

        // Throttle: max 3 richieste OTP ogni 10 minuti (via named rate limiter in RouteServiceProvider)
        $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(15);

        $user->update([
            'contract_otp'             => $otp,
            'contract_otp_expires_at'  => $expires,
        ]);

        $companyName = $user->company?->name ?? $user->name;
        $user->notify(new ContractOtpNotification($otp, $companyName));

        return redirect()->route('portal.contract.sign')
            ->with('otp_sent', true)
            ->with('otp_email', $user->email);
    }

    /**
     * Verifica OTP e segna il contratto come firmato.
     */
    public function sign(Request $request): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Inserisci il codice OTP ricevuto via email.',
            'otp.size'     => 'Il codice deve essere di 6 cifre.',
            'otp.regex'    => 'Il codice deve contenere solo cifre.',
        ]);

        $user = $request->user();

        if ($user->contract_signed_at) {
            return redirect()->route('portal.dashboard');
        }

        // Verifica OTP
        if (
            ! $user->contract_otp
            || ! $user->contract_otp_expires_at
            || now()->isAfter($user->contract_otp_expires_at)
        ) {
            return back()->withErrors(['otp' => 'Il codice OTP è scaduto. Richiedi un nuovo codice.']);
        }

        if (! hash_equals($user->contract_otp, $request->input('otp'))) {
            return back()->withErrors(['otp' => 'Codice OTP non corretto.'])->withInput();
        }

        // Firma registrata
        $user->update([
            'contract_signed_at'      => now(),
            'contract_otp'            => null,
            'contract_otp_expires_at' => null,
        ]);

        return redirect()->route('portal.dashboard')
            ->with('success', 'Contratto firmato con successo. Benvenuto nel circuito KMoney!');
    }

    /**
     * Rimanda la firma a dopo (solo per utenti esistenti + non forzati).
     */
    public function postpone(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->userCanPostpone($user)) {
            return redirect()->route('portal.contract.sign')
                ->withErrors(['general' => 'La firma del contratto è obbligatoria per continuare.']);
        }

        $user->update(['contract_postponed_at' => now()]);

        return redirect()->route('portal.dashboard')
            ->with('info', 'Puoi firmare il contratto in qualsiasi momento dalla tua area personale.');
    }

    /**
     * Determina se l'utente può rimandare la firma.
     * - Può rimandare: utenti esistenti (creati prima del deploy) + admin non ha forzato.
     * - Non può rimandare: nuovi utenti, o se contract_force_sign=true.
     */
    private function userCanPostpone(\App\Models\User $user): bool
    {
        $forceSign       = (bool) SystemSetting::contractSettings()->contract_force_sign;
        if ($forceSign) {
            return false;
        }

        $requiredFrom = SystemSetting::contractSettings()->contract_required_from;
        if ($requiredFrom && $user->created_at && $user->created_at->toDateString() >= $requiredFrom) {
            // Utente registrato dopo il deploy: deve firmare subito
            return false;
        }

        return true;
    }
}
