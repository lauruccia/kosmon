<?php

namespace App\Http\Controllers;

use App\Events\PaymentRequestUpdated;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CodePaymentController
 *
 * Pagamento con codice numerico a 6 cifre (fallback universale).
 *
 * MERCHANT (incassa):
 *   GET  /incassa/codice              -> form importo
 *   POST /incassa/codice              -> genera codice, redirect a show
 *   GET  /incassa/codice/{token}      -> display codice + polling
 *   GET  /incassa/codice/{token}/stato -> JSON status (AJAX)
 *   POST /incassa/codice/{token}/annulla -> cancella
 *
 * CLIENTE (paga):
 *   GET  /paga/codice                 -> keypad numerico
 *   POST /paga/codice/verifica        -> verifica codice + transfer
 */
class CodePaymentController extends Controller
{
    public function __construct(private readonly TransferBookingService $transferService) {}

    // =========================================================================
    // MERCHANT
    // =========================================================================

    public function form(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->canAccessBackoffice()) return redirect()->route('admin.dashboard');

        $account = $this->resolveAccount($user);
        if ($account->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        return view('portal.code.send', [
            'pageTitle' => 'Incassa con Codice',
            'account'   => $account,
            'activeNav' => 'incasso-codice',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->canAccessBackoffice()) return redirect()->route('admin.dashboard');

        $account = $this->resolveAccount($user);
        if ($account->status !== 'active') {
            return redirect()->route('portal.incasso-codice.form')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        // Genera codice 6 cifre univoco e non in uso
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (
            PaymentRequest::where('token', $code)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->exists()
        );

        PaymentRequest::create([
            'token'         => $code,
            'to_account_id' => $account->id,
            'amount'        => ky_to_cents($validated['amount']),
            'description'   => $validated['description'] ?? null,
            'kind'          => 'code',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        return redirect()->route('portal.incasso-codice.show', $code);
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->canAccessBackoffice()) return redirect()->route('admin.dashboard');

        $pr = PaymentRequest::with(['toAccount.company'])
            ->where('token', $token)
            ->where('kind', 'code')
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        return view('portal.code.send', [
            'pageTitle' => 'Incassa con Codice',
            'pr'        => $pr,
            'account'   => $account,
            'activeNav' => 'incasso-codice',
        ]);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        $user    = $request->user();
        $pr      = PaymentRequest::with(['fromAccount.company'])
            ->where('token', $token)->where('kind', 'code')->firstOrFail();
        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
            broadcast(new PaymentRequestUpdated($pr));
        }

        $payerName = $pr->fromAccount?->company?->name ?? $pr->fromAccount?->display_name;

        return response()->json([
            'status'       => $pr->status,
            'is_expired'   => $pr->isExpired(),
            'is_paid'      => $pr->isPaid(),
            'payer_name'   => $payerName,
            'paid_at'      => $pr->paid_at?->format('H:i:s'),
            'seconds_left' => max(0, now()->diffInSeconds($pr->expires_at, false)),
        ]);
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $user    = $request->user();
        $pr      = PaymentRequest::where('token', $token)->where('kind', 'code')->firstOrFail();
        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending') {
            $pr->update(['status' => 'cancelled']);
            broadcast(new PaymentRequestUpdated($pr->fresh()));
        }

        return redirect()->route('portal.incasso-codice.form')
            ->with('portal_success', 'Richiesta annullata.');
    }

    // =========================================================================
    // CLIENTE
    // =========================================================================

    public function receiveForm(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->canAccessBackoffice()) return redirect()->route('admin.dashboard');

        $account = $this->resolveAccount($user);
        if ($account->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        return view('portal.code.receive', [
            'pageTitle' => 'Paga con Codice',
            'account'   => $account,
            'activeNav' => 'paga-codice',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'code'    => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $account = $this->resolveAccount($user);

        $pr = PaymentRequest::with(['toAccount.company'])
            ->where('token', $validated['code'])
            ->where('kind', 'code')
            ->first();

        if (! $pr) {
            return response()->json(['error' => 'Codice non trovato. Controlla le cifre e riprova.'], 404);
        }
        if ($pr->isExpired()) {
            return response()->json(['error' => 'Codice scaduto (5 minuti). Chiedi un nuovo codice.'], 422);
        }
        if (! $pr->isPending()) {
            return response()->json(['error' => 'Questo codice e\' gia\' stato utilizzato.'], 422);
        }
        if ($pr->to_account_id === $account->id) {
            return response()->json(['error' => 'Non puoi pagare te stesso.'], 422);
        }
        if ($pr->toAccount === null || $pr->toAccount->status !== 'active') {
            return response()->json(['error' => 'Il conto del destinatario non è più attivo.'], 422);
        }
        if ($account->saldoDisponibile() < $pr->amount) {
            return response()->json([
                'error' => 'Saldo insufficiente (' . ky_format($account->saldoDisponibile()) . ' KY disponibili).',
            ], 422);
        }

        // Preview prima della conferma
        if (! $request->boolean('confirm')) {
            return response()->json([
                'preview'     => true,
                'code'        => $pr->token,
                'amount'      => $pr->amount,
                'description' => $pr->description,
                'merchant'    => $pr->toAccount->company?->name ?? 'Destinatario',
                'seconds_left'=> max(0, now()->diffInSeconds($pr->expires_at, false)),
            ]);
        }

        // Esegui transfer
        try {
            $transfer = $this->transferService->book([
                'from_account_id' => $account->id,
                'to_account_id'   => $pr->toAccount->id,
                'amount'          => $pr->amount,
                'description'     => $pr->description ?? 'Pagamento con codice',
                'kind'            => 'code',
                'idempotency_key' => 'code-' . $pr->token,
                'initiated_by'    => $user->id,
                'ip_address'      => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $pr->update([
            'status'          => 'paid',
            'paid_at'         => now(),
            'from_account_id' => $account->id,
            'transfer_id'     => $transfer->id,
        ]);

        broadcast(new PaymentRequestUpdated($pr->fresh()));

        return response()->json([
            'success'  => true,
            'amount'   => $pr->amount,
            'merchant' => $pr->toAccount->company?->name ?? 'Destinatario',
            'transfer' => $transfer->uuid,
        ]);
    }

    // =========================================================================

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
                ->orderBy('id')->firstOrFail();
        }
        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')->firstOrFail();
    }
}
