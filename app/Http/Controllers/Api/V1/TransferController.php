<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferBookingService $booking,
    ) {}

    /**
     * GET /api/v1/transfers
     * Lista trasferimenti del conto principale, ultimi 100, paginati.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'No active account'], 404);
        }

        $transfers = Transfer::query()
            ->with(['fromAccount.company', 'toAccount.company'])
            ->where(fn ($q) => $q
                ->where('from_account_id', $account->id)
                ->orWhere('to_account_id', $account->id)
            )
            ->where('status', 'booked')
            ->latest('booked_at')
            ->paginate(50);

        return response()->json([
            'data' => $transfers->getCollection()->map(fn ($t) => $this->formatTransfer($t, $account)),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'last_page'    => $transfers->lastPage(),
                'total'        => $transfers->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/transfers/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        $transfer = Transfer::with(['fromAccount.company', 'toAccount.company'])
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q
                ->where('from_account_id', $account?->id)
                ->orWhere('to_account_id', $account?->id)
            )
            ->first();

        if (! $transfer) {
            return response()->json(['error' => 'Transfer not found'], 404);
        }

        return response()->json(['data' => $this->formatTransfer($transfer, $account)]);
    }

    /**
     * POST /api/v1/transfers
     * Avvia un pagamento. Richiede ability: write.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');
        $token   = $request->attributes->get('api_token');

        if (! $token->can('write')) {
            return response()->json(['error' => 'Token requires write ability'], 403);
        }

        $data = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'integer', 'min:1'],
            'description'   => ['nullable', 'string', 'max:255'],
        ]);

        $fromAccount = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        if (! $fromAccount) {
            return response()->json(['error' => 'No active account for this company'], 422);
        }

        // Scope check sul destinatario: deve essere attivo, non di sistema, non se stessi
        $toAccount = Account::find((int) $data['to_account_id']);

        if (! $toAccount || $toAccount->status !== 'active') {
            return response()->json(['error' => 'Destination account not found or not active'], 422);
        }

        if ($toAccount->is_system_account) {
            return response()->json(['error' => 'Cannot transfer to system account'], 403);
        }

        if ($toAccount->id === $fromAccount->id) {
            return response()->json(['error' => 'Cannot transfer to your own account'], 422);
        }

        $initiator = $fromAccount->ownerUser ?? $fromAccount->company?->users()->first();
        if (! $initiator) {
            return response()->json(['error' => 'No user associated with this account'], 422);
        }

        try {
            $transfer = $this->booking->book([
                'initiated_by'    => $initiator->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => $toAccount->id,
                'amount'          => (int) $data['amount'],
                'description'     => $data['description'] ?? 'Pagamento API',
                'kind'            => 'api_payment',
                'idempotency_key' => 'api_' . Str::uuid(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->formatTransfer($transfer->load(['fromAccount.company', 'toAccount.company']), $fromAccount),
        ], 201);
    }

    private function formatTransfer(Transfer $t, ?Account $viewerAccount): array
    {
        $isCredit = $viewerAccount && (int) $t->to_account_id === $viewerAccount->id;

        return [
            'uuid'        => $t->uuid,
            'amount'      => $t->amount,
            'currency'    => 'KY',
            'direction'   => $isCredit ? 'credit' : 'debit',
            'status'      => $t->status,
            'kind'        => $t->kind,
            'description' => $t->description,
            'booked_at'   => $t->booked_at?->toIso8601String(),
            'from'        => [
                'account_id' => $t->from_account_id,
                'company'    => $t->fromAccount?->company?->name,
            ],
            'to'          => [
                'account_id' => $t->to_account_id,
                'company'    => $t->toAccount?->company?->name,
            ],
        ];
    }
}
