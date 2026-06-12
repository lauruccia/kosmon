<?php

namespace App\Http\Controllers;

use App\Events\PaymentRequestUpdated;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * NfcPaymentController
 *
 * Gestisce il flusso "incassa con NFC smartphone-to-smartphone" (Web NFC API).
 * Il merchant crea una PaymentRequest; il JS del browser chiama NDEFReader.write()
 * per scrivere l'URL di pagamento sul dispositivo del cliente avvicinato.
 * Il cliente apre /pay/{token} via Chrome (intercettazione NFC) e paga in un tap.
 *
 *   GET  /incassa/nfc                 -> form importo
 *   POST /incassa/nfc                 -> crea PaymentRequest, redirect a show
 *   GET  /incassa/nfc/{token}         -> pagina NFC attivo + polling
 *   GET  /incassa/nfc/{token}/stato   -> JSON status (AJAX polling)
 *   POST /incassa/nfc/{token}/annulla -> cancella la richiesta
 */
class NfcPaymentController extends Controller
{
    /** Form "Incassa con NFC". */
    public function form(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        return view('portal.nfc-form', [
            'pageTitle' => 'Incassa con NFC',
            'account'   => $account,
            'activeNav' => 'incasso-nfc',
        ]);
    }

    /** Crea la PaymentRequest e mostra la pagina NFC. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.incasso-nfc.form')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ], [
            'amount.required' => 'Inserisci l\'importo da incassare.',
            'amount.min'      => 'L\'importo minimo e\' 0,01 KY.',
        ]);

        $pr = PaymentRequest::create([
            'uuid'          => (string) Str::uuid(),
            'to_account_id' => $account->id,
            'amount'        => ky_to_cents($validated['amount']),
            'description'   => $validated['description'] ?? null,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        return redirect()->route('portal.incasso-nfc.show', $pr->token);
    }

    /** Pagina NFC attivo con countdown e polling. */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser', 'fromAccount.company'])
            ->where('token', $token)
            ->where('kind', 'nfc')
            ->firstOrFail();

        $account = $this->resolveAccount($user);

        abort_unless($pr->to_account_id === $account->id, 403);

        $payUrl = route('portal.pay-request.show', $pr->token);

        return view('portal.nfc-show', [
            'pageTitle' => 'Incassa con NFC',
            'pr'        => $pr,
            'account'   => $account,
            'payUrl'    => $payUrl,
            'activeNav' => 'incasso-nfc',
        ]);
    }

    /** Endpoint AJAX: restituisce lo stato attuale. */
    public function status(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::with(['fromAccount.company', 'fromAccount.ownerUser'])
            ->where('token', $token)
            ->where('kind', 'nfc')
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
            broadcast(new PaymentRequestUpdated($pr));
        }

        $payerName = null;
        if ($pr->fromAccount) {
            $payerName = $pr->fromAccount->company?->name ?? $pr->fromAccount->display_name;
        }

        return response()->json([
            'status'       => $pr->status,
            'is_expired'   => $pr->isExpired(),
            'is_paid'      => $pr->isPaid(),
            'payer_name'   => $payerName,
            'paid_at'      => $pr->paid_at?->format('H:i:s'),
            'seconds_left' => max(0, now()->diffInSeconds($pr->expires_at, false)),
        ]);
    }

    /** Cancella la richiesta. */
    public function cancel(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::where('token', $token)->where('kind', 'nfc')->firstOrFail();
        $account = $this->resolveAccount($user);

        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending') {
            $pr->update(['status' => 'cancelled']);
            broadcast(new PaymentRequestUpdated($pr->fresh()));
        }

        return redirect()->route('portal.incasso-nfc.form')
            ->with('portal_success', 'Richiesta NFC annullata.');
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
