<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PaymentHandlerController
 *
 * Gestisce la finestra "handler" del W3C Payment Request API.
 *
 *   GET  /paga/handler        -> finestra di conferma (aperta dal browser payment sheet)
 *   POST /paga/handler/pay    -> esegue il transfer KY, risponde al SW
 *   POST /paga/handler/register -> registra lo strumento di pagamento nel SW
 */
class PaymentHandlerController extends Controller
{
    public function __construct(private readonly TransferBookingService $transferService) {}

    /**
     * Finestra di conferma aperta dal browser quando viene presentato
     * il Payment Request sheet.
     */
    public function window(Request $request): View
    {
        return view('payment-handler.window');
    }

    /**
     * Esegue il trasferimento KY e restituisce uuid al client JS.
     * Chiamato dalla finestra handler via AJAX.
     */
    public function pay(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Non autenticato.'], 401);
        }

        if ($user->canAccessBackoffice()) {
            return response()->json(['error' => 'Operazione non permessa.'], 403);
        }

        $validated = $request->validate([
            'pr_token' => ['required', 'string'],
            'pr_id'    => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return response()->json(['error' => 'Il tuo conto non e\' attivo.'], 422);
        }

        $pr = PaymentRequest::with(['toAccount.company'])
            ->where('token', $validated['pr_token'])
            ->whereIn('kind', ['qr', 'nfc', 'sonic', 'link'])
            ->first();

        if (! $pr) {
            return response()->json(['error' => 'Richiesta non trovata.'], 404);
        }

        // Verifica che il conto destinatario (merchant) sia ancora attivo.
        if ($pr->toAccount === null || $pr->toAccount->status !== 'active') {
            return response()->json(['error' => 'Il conto del destinatario non è più attivo.'], 422);
        }

        if ($pr->isExpired()) {
            return response()->json(['error' => 'La richiesta e\' scaduta.'], 422);
        }

        if (! $pr->isPending()) {
            return response()->json(['error' => 'Questa richiesta e\' gia\' stata saldata.'], 422);
        }

        if ($pr->to_account_id === $account->id) {
            return response()->json(['error' => 'Non puoi pagare te stesso.'], 422);
        }

        if ($account->saldoDisponibile() < $pr->amount) {
            return response()->json([
                'error' => 'Saldo insufficiente (' . ky_format($account->saldoDisponibile()) . ' KY disponibili).',
            ], 422);
        }

        try {
            $transfer = $this->transferService->book([
                'from_account_id' => $account->id,
                'to_account_id'   => $pr->toAccount->id,
                'amount'          => $pr->amount,
                'description'     => $pr->description ?? 'Pagamento via Payment Request API',
                'kind'            => 'portal_payment_request',
                'idempotency_key' => 'pra-' . $pr->token,
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

        return response()->json([
            'success'      => true,
            'transferUuid' => $transfer->uuid,
            'amount'       => $pr->amount,
            'merchant'     => $pr->toAccount->company?->name ?? 'Destinatario',
        ]);
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
