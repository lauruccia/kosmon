<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentPlanController extends Controller
{
    /**
     * GET /api/v1/payment-plans
     *
     * Lista piani rateali del conto principale (come debitore o creditore).
     * Parametri opzionali:
     *   ?status=active|pending_approval|completed|cancelled|rejected
     *   ?role=debtor|creditor   (default: entrambi)
     *   ?per_page=25            (max 100)
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

        $status  = $request->query('status');
        $role    = $request->query('role');
        $perPage = min((int) ($request->query('per_page', 25)), 100);

        $query = PaymentPlan::with([
            'fromAccount.company',
            'toAccount.company',
            'installments',
        ]);

        // Filtra per ruolo
        if ($role === 'debtor') {
            $query->where('from_account_id', $account->id);
        } elseif ($role === 'creditor') {
            $query->where('to_account_id', $account->id);
        } else {
            $query->where(fn ($q) => $q
                ->where('from_account_id', $account->id)
                ->orWhere('to_account_id', $account->id)
            );
        }

        // Filtra per status
        $allowedStatuses = ['active', 'pending_approval', 'completed', 'cancelled', 'rejected'];
        if ($status && in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        $plans = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => $plans->getCollection()->map(fn ($p) => $this->formatPlan($p, $account)),
            'meta' => [
                'current_page' => $plans->currentPage(),
                'last_page'    => $plans->lastPage(),
                'total'        => $plans->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/payment-plans/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        $plan = PaymentPlan::with(['fromAccount.company', 'toAccount.company', 'installments'])
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q
                ->where('from_account_id', $account?->id)
                ->orWhere('to_account_id', $account?->id)
            )
            ->first();

        if (! $plan) {
            return response()->json(['error' => 'Payment plan not found'], 404);
        }

        return response()->json(['data' => $this->formatPlan($plan, $account)]);
    }

    private function formatPlan(PaymentPlan $plan, ?Account $viewer): array
    {
        $role = ($viewer && (int) $plan->from_account_id === $viewer->id) ? 'debtor' : 'creditor';

        return [
            'uuid'               => $plan->uuid,
            'status'             => $plan->status,
            'role'               => $role,
            'total_amount'       => (int) $plan->total_amount,
            'currency'           => $plan->currency_code ?? 'KY',
            'installments_count' => $plan->installments_count,
            'frequency'          => $plan->frequency,
            'first_due_date'     => $plan->first_due_date?->toDateString(),
            'description'        => $plan->description,
            'debtor'             => [
                'account_number' => $plan->fromAccount?->account_number,
                'company'        => $plan->fromAccount?->company?->name,
            ],
            'creditor'           => [
                'account_number' => $plan->toAccount?->account_number,
                'company'        => $plan->toAccount?->company?->name,
            ],
            'installments'       => $plan->installments->map(fn ($i) => [
                'number'       => $i->installment_number,
                'amount'       => (int) $i->amount,
                'due_date'     => $i->due_date?->toDateString(),
                'status'       => $i->status,
                'processed_at' => $i->processed_at?->toIso8601String(),
            ])->all(),
            'created_at'         => $plan->created_at?->toIso8601String(),
        ];
    }
}
