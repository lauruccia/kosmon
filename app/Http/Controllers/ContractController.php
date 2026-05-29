<?php

namespace App\Http\Controllers;

use App\Models\ContractSignature;
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

        // Firma registrata — salva snapshot contratto
        $settings         = SystemSetting::contractSettings();
        $contractHtml     = $settings->renderContractText($user->company, $user);
        $contractVersion  = $settings->contract_version ?? 1;
        $now              = now();

        ContractSignature::create([
            'user_id'                => $user->id,
            'company_id'             => $user->company_id,
            'contract_version'       => $contractVersion,
            'contract_html_snapshot' => $contractHtml,
            'signed_at'              => $now,
            'ip_address'             => $request->ip(),
            'user_agent'             => $request->userAgent(),
        ]);

        $user->update([
            'contract_signed_at'      => $now,
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
     * Mostra il contratto firmato (snapshot) nell'area riservata utente.
     */
    public function viewSigned(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->contract_signed_at) {
            return redirect()->route('portal.contract.sign')
                ->withErrors(['general' => 'Non hai ancora firmato il contratto.']);
        }

        $signature = \App\Models\ContractSignature::where('user_id', $user->id)
            ->latest('signed_at')
            ->first();

        if (! $signature) {
            // Utenti che hanno firmato prima dell'introduzione degli snapshot: mostra testo attuale
            $settings     = SystemSetting::contractSettings();
            $contractHtml = $settings->renderContractText($user->company, $user);

            return view('portal.contract-view-legacy', [
                'user'         => $user,
                'company'      => $user->company,
                'contractHtml' => $contractHtml,
                'signedAt'     => $user->contract_signed_at,
                'contractVer'  => $settings->contract_version ?? 1,
            ]);
        }

        return view('portal.contract-view', compact('signature'));
    }

    /**
     * Scarica il contratto firmato come PDF.
     */
    public function downloadSigned(Request $request): \Illuminate\Http\Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->contract_signed_at) {
            return redirect()->route('portal.contract.sign');
        }

        $signature = \App\Models\ContractSignature::where('user_id', $user->id)
            ->latest('signed_at')->first();

        $contractHtml = $signature?->contract_html_snapshot
            ?? SystemSetting::contractSettings()->renderContractText($user->company, $user);
        $version  = $signature?->contract_version ?? (SystemSetting::contractSettings()->contract_version ?? 1);
        $signedAt = $signature?->signed_at ?? $user->contract_signed_at;
        $ipAddr   = $signature?->ip_address ?? 'n.d.';
        $companyName = $user->company?->name ?? $user->name;

        $html = '<html><head><meta charset="UTF-8">
<style>
body{font-family:Georgia,serif;font-size:13px;line-height:1.7;color:#111;margin:40px 50px;}
h1{font-size:1.2rem;color:#0f766e;margin-bottom:4px;}
.meta{font-size:11px;color:#666;margin-bottom:32px;border-bottom:1px solid #ddd;padding-bottom:16px;}
h2{font-size:.95rem;font-weight:700;margin:20px 0 8px;color:#0f766e;}
p{margin:0 0 12px;}
hr{border:none;border-top:1px solid #ddd;margin:20px 0;}
ul,ol{padding-left:20px;}
li{margin-bottom:6px;}
.footer{margin-top:40px;border-top:2px solid #0f766e;padding-top:16px;font-size:11px;color:#555;}
</style></head><body>
<h1>Contratto di Adesione al Circuito KMoney &mdash; v' . $version . '</h1>
<div class="meta">
<strong>Azienda:</strong> ' . e($companyName) . ' &nbsp;|&nbsp;
<strong>Firmato il:</strong> ' . $signedAt->format('d/m/Y \l\l\e H:i:s') . '
</div>
' . $contractHtml . '
<div class="footer">
Documento generato da KMoney &mdash; Firma digitale con OTP via email<br>
' . ($signature ? 'Codice firma: ' . strtoupper(substr(md5($signature->id . $signature->signed_at), 0, 12)) : '') . '
</div>
</body></html>';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4');
        $filename = 'contratto-' . \Illuminate\Support\Str::slug($companyName) . '-' . $signedAt->format('Ymd') . '.pdf';
        return $pdf->download($filename);
    }

    
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
