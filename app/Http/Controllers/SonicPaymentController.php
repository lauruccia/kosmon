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
 * SonicPaymentController
 *
 * Gestisce il flusso "paga con suono" (Web Audio API).
 *
 * MERCHANT (incassa):
 *   GET  /incassa/sonic               -> form importo
 *   POST /incassa/sonic               -> crea PaymentRequest kind=sonic, redirect a show
 *   GET  /incassa/sonic/{token}       -> pagina con encoder audio (suona il token)
 *   GET  /incassa/sonic/{token}/stato -> JSON status (polling AJAX)
 *   POST /incassa/sonic/{token}/annulla -> cancella
 *
 * CLIENTE (paga):
 *   GET  /paga/sonic                  -> pagina con decoder microfono
 *   POST /paga/sonic/verifica         -> verifica token audio, esegue transfer
 */
class SonicPaymentController extends Controller
{
    public function __construct(private readonly TransferBookingService $transferService) {}

    // =========================================================================
    // MERCHANT — lato incasso
    // =========================================================================

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

        return view('portal.sonic.send', [
            'pageTitle' => 'Incassa con Suono',
            'account'   => $account,
            'activeNav' => 'incasso-sonic',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.incasso-sonic.form')
                ->with('portal_error', 'Il tuo conto non e\' attivo.');
        }

        $validated = $request->validate([
            'amount'      => ['required', 'integer', 'min:1', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ], [
            'amount.required' => 'Inserisci l\'importo da incassare.',
            'amount.min'      => 'L\'importo minimo e\' 1 KY.',
        ]);

        // Token corto (8 hex) = trasmissibile in audio in ~2.6 secondi
        do {
            $sonicToken = bin2hex(random_bytes(4)); // 8 lowercase hex chars
        } while (PaymentRequest::where('token', $sonicToken)->exists());

        $pr = PaymentRequest::create([
            'token'         => $sonicToken,
            'to_account_id' => $account->id,
            'amount'        => (int) $validated['amount'],
            'description'   => $validated['description'] ?? null,
            'kind'          => 'sonic',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        return redirect()->route('portal.incasso-sonic.show', $pr->token);
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('token', $token)
            ->where('kind', 'sonic')
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        return view('portal.sonic.send', [
            'pageTitle'  => 'Incassa con Suono',
            'pr'         => $pr,
            'account'    => $account,
            'activeNav'  => 'incasso-sonic',
        ]);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::with(['fromAccount.company', 'fromAccount.ownerUser'])
            ->where('token', $token)
            ->where('kind', 'sonic')
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

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::where('token', $token)->where('kind', 'sonic')->firstOrFail();
        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending') {
            $pr->update(['status' => 'cancelled']);
            broadcast(new PaymentRequestUpdated($pr->fresh()));
        }

        return redirect()->route('portal.incasso-sonic.form')
            ->with('portal_success', 'Richiesta sonic annullata.');
    }

    // =========================================================================
    // CLIENTE — lato pagamento
    // =========================================================================

    public function receiveForm(Request $request): View|RedirectResponse
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

        return view('portal.sonic.receive', [
            'pageTitle' => 'Paga con Suono',
            'account'   => $account,
            'activeNav' => 'paga-sonic',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'size:8'],
        ]);

        $account = $this->resolveAccount($user);

        $pr = PaymentRequest::with(['toAccount.company'])
            ->where('token', $validated['token'])
            ->where('kind', 'sonic')
            ->first();

        if (! $pr) {
            return response()->json(['error' => 'Codice non trovato. Riprova.'], 404);
        }

        if ($pr->isExpired()) {
            return response()->json(['error' => 'Il codice audio e\' scaduto (5 minuti).'], 422);
        }

        if (! $pr->isPending()) {
            return response()->json(['error' => 'Questo codice e\' gia\' stato utilizzato.'], 422);
        }

        if ($pr->to_account_id === $account->id) {
            return response()->json(['error' => 'Non puoi pagare te stesso.'], 422);
        }

        // Se balance insufficiente, rispondi prima del transfer
        if ($account->available_balance < $pr->amount) {
            return response()->json([
                'error' => 'Saldo insufficiente (' . $account->available_balance . ' KY disponibili).',
            ], 422);
        }

        // Preview: mostra dettagli prima della conferma
        if ($request->boolean('preview', true) && ! $request->boolean('confirm')) {
            return response()->json([
                'preview'      => true,
                'token'        => $pr->token,
                'amount'       => $pr->amount,
                'description'  => $pr->description,
                'merchant'     => $pr->toAccount->company?->name ?? 'Destinatario',
                'seconds_left' => max(0, now()->diffInSeconds($pr->expires_at, false)),
            ]);
        }

        // Esecuzione transfer
        try {
            $transfer = $this->transferService->book([
                'from_account_id' => $account->id,
                'to_account_id'   => $pr->toAccount->id,
                'amount'          => $pr->amount,
                'description'     => $pr->description ?? 'Pagamento sonic',
                'kind'            => 'sonic',
                'idempotency_key' => 'sonic-' . $pr->token,
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
            'success'   => true,
            'amount'    => $pr->amount,
            'merchant'  => $pr->toAccount->company?->name ?? 'Destinatario',
            'transfer'  => $transfer->uuid,
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
