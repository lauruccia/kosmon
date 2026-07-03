<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MlmAgentContractSignature;
use App\Models\SystemSetting;
use App\Notifications\MlmAgentActivatedNotification;
use App\Notifications\MlmAgentContractOtpNotification;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Firma del contratto di nomina ad Agente KNM, ultimo passo del percorso
 * richiesta -> approvazione admin -> firma contratto -> mlm_role = 'agente'.
 * Stesso schema OTP via email del ContractController principale.
 */
class MlmAgentContractController extends Controller
{
    /** GET /portale/mlm/contratto-agente */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isMlmAgent()) {
            return redirect()->route('portal.dashboard')->with('info', 'Sei già un agente KNM.');
        }

        if (! $user->mlmAgentAwaitingContract()) {
            return redirect()->route('portal.mlm.agent-request.show');
        }

        $settings     = SystemSetting::agentContractSettings();
        $contractHtml = $settings->renderAgentContractText($user);
        $contractVer  = $settings->mlm_agent_contract_version ?? 1;

        return view('portal.mlm.agent-contract-sign', [
            'pageTitle'    => 'Contratto di nomina ad agente KNM',
            'user'         => $user,
            'contractHtml' => $contractHtml,
            'contractVer'  => $contractVer,
            'activeNav'    => 'mlm-agent-request',
        ]);
    }

    /** POST /portale/mlm/contratto-agente/otp */
    public function sendOtp(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->mlmAgentAwaitingContract(), 403);

        $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(15);

        $user->forceFill([
            'mlm_agent_contract_otp'            => $otp,
            'mlm_agent_contract_otp_expires_at' => $expires,
        ])->save();

        $user->notify(new MlmAgentContractOtpNotification($otp));

        return redirect()->route('portal.mlm.agent-contract.show')
            ->with('otp_sent', true)
            ->with('otp_email', $user->email);
    }

    /** POST /portale/mlm/contratto-agente/firma */
    public function sign(Request $request, MlmTreeService $mlmTree): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Inserisci il codice OTP ricevuto via email.',
            'otp.size'     => 'Il codice deve essere di 6 cifre.',
            'otp.regex'    => 'Il codice deve contenere solo cifre.',
        ]);

        $user = $request->user();

        abort_unless($user->mlmAgentAwaitingContract(), 403);

        if (
            ! $user->mlm_agent_contract_otp
            || ! $user->mlm_agent_contract_otp_expires_at
            || now()->isAfter($user->mlm_agent_contract_otp_expires_at)
        ) {
            return back()->withErrors(['otp' => 'Il codice OTP è scaduto. Richiedi un nuovo codice.']);
        }

        if (! hash_equals($user->mlm_agent_contract_otp, $request->input('otp'))) {
            return back()->withErrors(['otp' => 'Codice OTP non corretto.'])->withInput();
        }

        $settings        = SystemSetting::agentContractSettings();
        $contractHtml    = $settings->renderAgentContractText($user);
        $contractVersion = $settings->mlm_agent_contract_version ?? 1;
        $now             = now();

        MlmAgentContractSignature::create([
            'user_id'                => $user->id,
            'contract_version'       => $contractVersion,
            'contract_html_snapshot' => $contractHtml,
            'signed_at'              => $now,
            'ip_address'             => $request->ip(),
            'user_agent'             => $request->userAgent(),
        ]);

        // Sponsor nell'albero MLM: il primo agente antenato nella catena di chi
        // ha invitato questo utente (stessa regola usata in registrazione).
        $sponsor = $mlmTree->resolveAgentForNewClient($user->referredBy);

        $user->forceFill([
            'mlm_agent_contract_signed_at'       => $now,
            'mlm_agent_contract_otp'             => null,
            'mlm_agent_contract_otp_expires_at'  => null,
            'mlm_role'                           => 'agente',
            'mlm_activated_at'                   => $now,
            'mlm_client_agent_id'                => null,
        ])->save();

        $mlmTree->attachAgent($user, $sponsor);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_contract.signed',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'context'        => ['contract_version' => $contractVersion, 'sponsor_user_id' => $sponsor?->id],
        ]);

        $user->notify(new MlmAgentActivatedNotification());

        return redirect()->route('portal.mlm.struttura')
            ->with('success', 'Contratto firmato: sei ufficialmente un agente KNM! Benvenuto nel programma.');
    }
}
