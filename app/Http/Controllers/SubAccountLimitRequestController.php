<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubAccountLimitRequest;
use App\Models\Account;
use App\Models\SubAccountLimitRequest;
use App\Services\SubAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubAccountLimitRequestController extends Controller
{
    /**
     * Il gestore del sottoconto invia una richiesta di aumento limite o sforamento.
     * Route: POST /conti/sottoconti/{subaccount}/richieste-limite
     */
    public function store(StoreSubAccountLimitRequest $request, Account $subaccount, SubAccountService $service): RedirectResponse
    {
        // Autorizzazione e validazione (con normalizzazione importo) in StoreSubAccountLimitRequest
        $validated = $request->validated();

        try {
            $service->requestLimitChange(
                subAccount:      $subaccount,
                requestedBy:     $request->user(),
                type:            $validated['type'],
                requestedAmount: ky_to_cents($validated['requested_amount']),
                reason:          $validated['reason'],
                ipAddress:       $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Richiesta inviata. Il titolare del conto verrà notificato.');
    }

    /**
     * Il titolare approva una richiesta.
     * Route: POST /conti/sottoconti/richieste-limite/{limitRequest}/approva
     */
    public function approve(Request $request, SubAccountLimitRequest $limitRequest, SubAccountService $service): RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);
        $this->authorizeOwner($request, $limitRequest);

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->approveLimitRequest(
                limitRequest: $limitRequest,
                approvedBy:   $request->user(),
                note:         $validated['decision_note'] ?? null,
                ipAddress:    $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Richiesta approvata. Il gestore del sottoconto è stato notificato.');
    }

    /**
     * Il titolare rifiuta una richiesta.
     * Route: POST /conti/sottoconti/richieste-limite/{limitRequest}/rifiuta
     */
    public function reject(Request $request, SubAccountLimitRequest $limitRequest, SubAccountService $service): RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);
        $this->authorizeOwner($request, $limitRequest);

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->rejectLimitRequest(
                limitRequest: $limitRequest,
                rejectedBy:   $request->user(),
                note:         $validated['decision_note'] ?? null,
                ipAddress:    $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Richiesta rifiutata. Il gestore del sottoconto è stato notificato.');
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function authorizeOwner(Request $request, SubAccountLimitRequest $limitRequest): void
    {
        // Il titolare deve essere il proprietario del conto padre del sottoconto
        $subAccount   = $limitRequest->subAccount()->with('parentAccount')->firstOrFail();
        $parentAccount = $subAccount->parentAccount;

        abort_if($parentAccount === null, 404);

        $user = $request->user();

        $isOwner = ($parentAccount->owner_user_id && $parentAccount->owner_user_id === $user->id)
                || ($parentAccount->company_id && $user->company_id === $parentAccount->company_id && $user->managed_account_id === null);

        abort_unless($isOwner, 403);
    }
}
