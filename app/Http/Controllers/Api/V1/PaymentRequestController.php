<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRequestController extends Controller
{
    /**
     * GET /api/v1/payment-requests
     *
     * Lista richieste di pagamento ricevute (to_account) o inviate (from_account).
     * Parametri opzionali:
     *   ?status=pending|paid|expired|cancelled
     *   ?direction=incoming|outgoing   (default: entrambi)
     *   ?per_page=25                   (max 100)
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

        $status    = $request->query('status');
        $direction = $request->query('direction');
        $perPage   = min((int) ($request->query('per_page', 25)), 100);

        $query = PaymentRequest::with(['toAccount.company', 'fromAccount.company', 'transfer']);

        // Filtra per direzione
        if ($direction === 'incoming') {
            // Il conto è il destinatario (chi incassa)
            $query->where('to_account_id', $account->id);
        } elseif ($direction === 'outgoing') {
            // Il conto è il pagante
            $query->where('from_account_id', $account->id);
        } else {
            $query->where(fn ($q) => $q
                ->where('to_account_id', $account->id)
                ->orWhere('from_account_id', $account->id)
            );
        }

        // Filtra per status
        $allowedStatuses = ['pending', 'paid', 'expired', 'cancelled'];
        if ($status && in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        $requests = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => $requests->getCollection()->map(fn ($r) => $this->formatRequest($r, $account)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/payment-requests/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        $pr = PaymentRequest::with(['toAccount.company', 'fromAccount.company', 'transfer'])
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q
                ->where('to_account_id', $account?->id)
                ->orWhere('from_account_id', $account?->id)
            )
            ->first();

        if (! $pr) {
            return response()->json(['error' => 'Payment request not found'], 404);
        }

        return response()->json(['data' => $this->formatRequest($pr, $account)]);
    }

    private function formatRequest(PaymentRequest $pr, ?Account $viewer): array
    {
        $direction = ($viewer && (int) $pr->to_account_id === $viewer->id)
            ? 'incoming'
            : 'outgoing';

        return [
            'uuid'        => $pr->uuid,
            'status'      => $pr->status,
            'direction'   => $direction,
            'kind'        => $pr->kind ?? 'qr_dynamic',
            'amount'      => (int) $pr->amount,
            'currency'    => 'KY',
            'description' => $pr->description,
            'expires_at'  => $pr->expires_at?->toIso8601String(),
            'paid_at'     => $pr->paid_at?->toIso8601String(),
            'creditor'    => [
                'account_number' => $pr->toAccount?->account_number,
                'company'        => $pr->toAccount?->company?->name,
            ],
            'payer'       => $pr->fromAccount ? [
                'account_number' => $pr->fromAccount?->account_number,
                'company'        => $pr->fromAccount?->company?->name,
            ] : null,
            'transfer_uuid' => $pr->transfer?->uuid,
            'created_at'    => $pr->created_at?->toIso8601String(),
        ];
    }
}
