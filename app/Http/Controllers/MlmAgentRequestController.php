<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Concerns\NotifiesAdmins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Richiesta di un utente "cliente" di aderire al programma agenti KNM.
 * Flusso: richiesta (pending) -> revisione admin (approved/rejected) ->
 * se approvata, firma del contratto di nomina (MlmAgentContractController)
 * -> mlm_role passa ad 'agente'.
 */
class MlmAgentRequestController extends Controller
{
    /** GET /portale/mlm/richiedi-agente */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isMlmAgent()) {
            return redirect()->route('portal.dashboard')->with('info', 'Sei già un agente KNM.');
        }

        if ($user->mlmAgentAwaitingContract()) {
            return redirect()->route('portal.mlm.agent-contract.show');
        }

        return view('portal.mlm.agent-request', [
            'pageTitle' => 'Diventa agente KNM',
            'user'      => $user,
            'activeNav' => 'mlm-agent-request',
        ]);
    }

    /** POST /portale/mlm/richiedi-agente */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canRequestMlmAgent(), 403, 'Non puoi presentare questa richiesta al momento.');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['note' => 'messaggio']);

        $user->forceFill([
            'mlm_agent_request_status'    => 'pending',
            'mlm_agent_requested_at'      => now(),
            'mlm_agent_request_note'      => $validated['note'] ?? null,
            'mlm_agent_reviewed_at'       => null,
            'mlm_agent_reviewed_by'       => null,
            'mlm_agent_rejection_reason'  => null,
        ])->save();

        NotifiesAdmins::notifyAdminsOfMlmAgentRequest($user);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_request.submitted',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['note' => $validated['note'] ?? null],
        ]);

        return redirect()->route('portal.mlm.agent-request.show')
            ->with('status', 'Richiesta inviata! Ti avviseremo appena verrà revisionata dal nostro team.');
    }
}
