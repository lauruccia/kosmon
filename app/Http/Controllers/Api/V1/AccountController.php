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
                'id'                => $mainAccount->id,
                'account_number'    => $mainAccount->account_number,
                'currency'          => $mainAccount->currency_code ?? 'KY',
                'balance'           => $mainAccount->available_balance,
                'available_balance' => $mainAccount->saldoDisponibile(),
                'status'            => $mainAccount->status,
            ] : null,
        ]);
    }
}
