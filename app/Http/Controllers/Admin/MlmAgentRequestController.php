<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\MlmAgentRequestReviewedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Backoffice: coda delle richieste "voglio diventare agente KNM" + azione
 * dell'admin per rendere agente qualsiasi utente registrato (senza
 * richiesta previa). In entrambi i casi l'utente dovrà comunque firmare
 * il contratto di nomina per diventare agente a tutti gli effetti
 * (mlm_role passa ad 'agente' solo dopo la firma — vedi MlmAgentContractController).
 */
class MlmAgentRequestController extends Controller
{
    use AuthorizesBackoffice;

    /** GET /admin/mlm/richieste */
    public function index(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $status = (string) $request->query('status', 'pending');

        $requests = User::query()
            ->whereNotNull('mlm_agent_request_status')
            ->when($status !== '', fn ($q) => $q->where('mlm_agent_request_status', $status))
            ->with('mlmAgentReviewedBy:id,name')
            ->orderByDesc('mlm_agent_requested_at')
            ->paginate(25)->withQueryString();

        $pendingCount = User::where('mlm_agent_request_status', 'pending')->count();

        return view('admin.mlm.agent-requests', [
            'pageTitle'     => 'MLM — Richieste agente',
            'requests'      => $requests,
            'selectedStatus'=> $status,
            'pendingCount'  => $pendingCount,
            'activeNav'     => 'mlm',
        ]);
    }

    /** POST /admin/mlm/richieste/{user}/approva */
    public function approve(Request $request, User $user): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        abort_if($user->isMlmAgent(), 422, 'Questo utente è già agente.');

        $user->forceFill([
            'mlm_agent_request_status'   => 'approved',
            'mlm_agent_reviewed_at'      => now(),
            'mlm_agent_reviewed_by'      => $request->user()->id,
            'mlm_agent_rejection_reason' => null,
        ])->save();


        // Quota codice agente (31/08/2026): il debito nasce QUI, all'approvazione.
        app(\App\Services\AgentCodeFeeService::class)->markDueOnApproval($user->fresh());

        $user->notify(new MlmAgentRequestReviewedNotification('approved'));

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'mlm.agent_request.approved',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', 'Richiesta approvata. ' . $user->name . ' potrà ora firmare il contratto di nomina.');
    }

    /** POST /admin/mlm/richieste/{user}/rifiuta */
    public function reject(Request $request, User $user): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['reason' => 'motivo']);

        // PRIMA di toccare qualsiasi cosa: aveva gia' pagato i 480? Il rifiuto
        // non ci mette le mani — sono soldi veri incassati, e restituirli e'
        // una decisione, non un effetto collaterale di un click su "Rifiuta"
        // (scelta di Laura, 01/09/2026). Ma chi rifiuta lo deve sapere adesso,
        // mentre ci sta pensando, non fra un mese leggendo una tabella.
        $quotaGiaPagata = app(\App\Services\AgentCodeFeeService::class)->hasPaid($user);

        $user->forceFill([
            'mlm_agent_request_status'   => 'rejected',
            'mlm_agent_reviewed_at'      => now(),
            'mlm_agent_reviewed_by'      => $request->user()->id,
            'mlm_agent_rejection_reason' => $validated['reason'],
        ])->save();

        // Il percorso agente si chiude qui, e con lui le due quote che ci
        // stavano appese (01/09/2026):
        //   - i 480 del codice, se non ancora pagati, non sono piu' dovuti:
        //     lasciarglieli addosso vuol dire conto bloccato e una pagina che
        //     invita a pagare un codice che non arrivera' mai;
        //   - i 30 dei privati, se erano SOSPESI perche' era entrato dal
        //     portale di un agente, tornano dovuti: e' un privato come tutti.
        app(\App\Services\AgentCodeFeeService::class)->dropUnpaidDebt($user, $request->ip());
        app(\App\Services\RegistrationFeeService::class)->resumeAfterAgentPath($user->refresh(), $request->ip());

        $user->notify(new MlmAgentRequestReviewedNotification('rejected', $validated['reason']));

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'mlm.agent_request.rejected',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['reason' => $validated['reason'], 'quota_gia_pagata' => $quotaGiaPagata],
        ]);

        $messaggio = 'Richiesta rifiutata. ' . $user->name . ' è stato avvisato.';

        if ($quotaGiaPagata) {
            $messaggio .= ' ATTENZIONE: aveva già saldato la quota per il codice agente e quei soldi NON sono stati toccati. Se vanno restituiti, annulla la quota da Quote codice agente.';
        }

        return back()->with('portal_success', $messaggio);
    }

    /**
     * POST /admin/users/{user}/mlm/rendi-agente
     * L'admin puo' avviare direttamente il percorso agente per QUALSIASI
     * utente registrato, anche senza che questi abbia fatto richiesta.
     * Equivale a un'approvazione immediata: l'utente dovrà comunque firmare
     * il contratto di nomina per diventare agente a tutti gli effetti.
     */
    public function promote(Request $request, User $user): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        abort_if($user->isMlmAgent(), 422, 'Questo utente è già agente.');

        $user->forceFill([
            'mlm_agent_request_status'   => 'approved',
            'mlm_agent_requested_at'     => $user->mlm_agent_requested_at ?? now(),
            'mlm_agent_reviewed_at'      => now(),
            'mlm_agent_reviewed_by'      => $request->user()->id,
            'mlm_agent_rejection_reason' => null,
        ])->save();


        // Quota codice agente (31/08/2026): il debito nasce QUI, all'approvazione.
        app(\App\Services\AgentCodeFeeService::class)->markDueOnApproval($user->fresh());

        $user->notify(new MlmAgentRequestReviewedNotification('approved'));

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'mlm.agent_request.admin_promoted',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', $user->name . ' è stato abilitato a diventare agente: riceverà un\'email per firmare il contratto di nomina.');
    }
}
