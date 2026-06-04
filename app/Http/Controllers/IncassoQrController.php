<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Events\PaymentRequestUpdated;
use Illuminate\View\View;

/**
 * IncassoQrController
 *
 * Gestisce il flusso "incassa con QR dinamico":
 *   GET  /incassa/qr          -> form importo + descrizione
 *   POST /incassa/qr          -> crea PaymentRequest, redirect al QR
 *   GET  /incassa/qr/{token}  -> pagina QR con countdown e polling
 *   GET  /incassa/qr/{token}/stato -> JSON status (per AJAX polling)
 *   POST /incassa/qr/{token}/annulla -> cancella la richiesta
 */
class IncassoQrController extends Controller
{
    /** Mostra il form "Incassa con QR". */
    public function form(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non è attivo. Non puoi generare richieste di pagamento.');
        }

        return view('portal.incasso-qr-form', [
            'pageTitle'  => 'Incassa con QR',
            'account'    => $account,
            'activeNav'  => 'incasso-qr',
        ]);
    }

    /** Crea la PaymentRequest e mostra il QR. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.incasso-qr.form')
                ->with('portal_error', 'Il tuo conto non è attivo.');
        }

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ], [
            'amount.required' => 'Inserisci l\'importo da incassare.',
            'amount.min'      => 'L\'importo minimo è 0,01 KY.',
        ]);

        $pr = PaymentRequest::create([
            'to_account_id' => $account->id,
            'amount'        => ky_to_cents($validated['amount']),
            'description'   => $validated['description'] ?? null,
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(10),
        ]);

        return redirect()->route('portal.incasso-qr.show', $pr->token);
    }

    /** Mostra la pagina QR con countdown e polling. */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser', 'fromAccount.company'])
            ->where('token', $token)
            ->firstOrFail();

        $account = $this->resolveAccount($user);

        // Solo chi ha creato la richiesta può vederla in questa vista merchant
        abort_unless($pr->to_account_id === $account->id, 403);

        $payUrl = route('portal.pay-request.show', $pr->token);

        return view('portal.incasso-qr-show', [
            'pageTitle'  => 'Incassa con QR',
            'pr'         => $pr,
            'account'    => $account,
            'payUrl'     => $payUrl,
            'activeNav'  => 'incasso-qr',
        ]);
    }

    /** Endpoint AJAX: restituisce lo stato attuale della richiesta. */
    public function status(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::with(['fromAccount.company', 'fromAccount.ownerUser'])
            ->where('token', $token)
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        // Aggiorna automaticamente a expired se scaduta
        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
        }

        $payerName = null;
        if ($pr->fromAccount) {
            $payerName = $pr->fromAccount->company?->name ?? $pr->fromAccount->display_name;
        }

        return response()->json([
            'status'      => $pr->status,
            'is_expired'  => $pr->isExpired(),
            'is_paid'     => $pr->isPaid(),
            'payer_name'  => $payerName,
            'paid_at'     => $pr->paid_at?->format('H:i:s'),
            'seconds_left' => max(0, now()->diffInSeconds($pr->expires_at, false)),
        ]);
    }

    /** Cancella manualmente la richiesta (dal merchant). */
    public function cancel(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::where('token', $token)->firstOrFail();
        $account = $this->resolveAccount($user);

        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending') {
            $pr->update(['status' => 'cancelled']);
            broadcast(new PaymentRequestUpdated($pr->fresh()));
        }

        return redirect()->route('portal.incasso-qr.form')
            ->with('portal_success', 'Richiesta QR annullata.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with(['company', 'ownerUser', 'parentAccount'])
                ->findOrFail($user->managed_account_id);
            return $sub->parentAccount ?? $sub;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->firstOrFail();
    }
}
