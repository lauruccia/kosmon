<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * GET /api/v1/me
     * Restituisce info azienda + saldo del conto principale.
     */
    public function me(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');
        $company->load('accounts');

        $mainAccount = $company->accounts
            ->where('status', 'active')
            ->whereNull('parent_account_id')
            ->first();

        return response()->json([
            'company' => [
                'id'   => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
            'account' => $mainAccount ? [
                'account_number'    => $mainAccount->account_number,
                'currency'          => $mainAccount->currency_code ?? 'KY',
                'balance'           => $mainAccount->available_balance,
                'available_balance' => $mainAccount->saldoDisponibile(),
                'status'            => $mainAccount->status,
            ] : null,
        ]);
    }

    /**
     * GET /api/v1/balance
     * Saldo dettagliato del conto principale: disponibile, fido, massimale, tetto.
     */
    public function balance(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');
        $company->load('accounts');

        $account = $company->accounts
            ->where('status', 'active')
            ->whereNull('parent_account_id')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'No active account'], 404);
        }

        return response()->json([
            'account_number'    => $account->account_number,
            'currency'          => $account->currency_code ?? 'KY',
            'balance'           => (int) $account->available_balance,
            'credit_limit'      => (int) $account->massimale(),
            'available_balance' => (int) $account->saldoDisponibile(),
            'max_balance'       => $account->max_balance !== null ? (int) $account->max_balance : null,
            'is_in_debit'       => $account->isInDebit(),
            'is_at_ceiling'     => $account->isAtCeiling(),
            'can_sell'          => $account->canSell(),
            'allowed_ky_percentages' => $account->allowedKyPercentages(),
        ]);
    }
}
