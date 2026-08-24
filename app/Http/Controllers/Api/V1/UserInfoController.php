<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\OAuthAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/userinfo — "chi è l'utente che ti ha appena fatto entrare".
 *
 * È il pezzo che rende inutile una seconda anagrafica in kshop: nessuna
 * registrazione, nessuna password, nessun dato da tenere sincronizzato.
 *
 * Due cose che questo endpoint NON dice, di proposito:
 *  - **il saldo non esce mai da qui.** Sapere se puoi vendere è una cosa,
 *    sapere quanti KY hai in cassa è un'altra: al negozio non serve.
 *  - niente id numerici interni: l'utente è il suo `uuid`, l'azienda il suo,
 *    il conto è il numero KY che già oggi è pubblico dentro il circuito.
 *
 * Il contenuto segue gli scope concessi: senza `account.read` restano solo
 * l'identità e i dati anagrafici minimi.
 */
class UserInfoController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var OAuthAccessToken $token */
        $token = $request->attributes->get('oauth_token');

        /** @var User $user */
        $user = $token->user;

        $payload = [
            'sub'       => $user->uuid,
            'client_id' => $token->client_id,
            'scope'     => implode(' ', (array) $token->scopes),
        ];

        if ($token->hasScope('profile')) {
            $payload['profile'] = [
                'name'          => $user->name,
                'email'         => $user->email,
                'holder_type'   => $user->account_holder_type,   // 'company' | 'private'
                'email_verified' => $user->email_verified_at !== null,
            ];
        }

        if ($token->hasScope('account.read')) {
            $account = $this->resolveAccount($user);
            $company = $account?->company ?? $user->company;

            $payload['company'] = $company ? [
                'uuid'   => $company->uuid,
                'name'   => $company->name,
                'status' => $company->status,
            ] : null;

            $payload['account'] = $account ? [
                'number'   => $account->uuid,          // KYB…/KYP… — il numero di conto
                'type'     => $account->owner_type,    // 'company' | 'user'
                'currency' => $account->currency_code,
            ] : null;

            // Stato commerciale in stile Sardex: serve al negozio per sapere
            // se questo utente può mettere in vendita e con quale mix KY/EUR.
            $payload['trading'] = $account ? [
                'can_sell'               => $account->canSell(),
                'in_debit'               => $account->isInDebit(),
                'at_ceiling'             => $account->isAtCeiling(),
                'allowed_ky_percentages' => $account->allowedKyPercentages(),
            ] : null;

            $payload['roles'] = $this->roles($account);
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * Stessa risoluzione usata dal portale (WalletController, ListingController):
     * sottoconto → conto principale dell'azienda → conto personale.
     */
    private function resolveAccount(User $user): ?Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with('company')->find($user->managed_account_id);

            return $sub?->parentAccount ?? $sub;
        }

        if ($user->company_id !== null) {
            return Account::with('company')
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->first();
        }

        return Account::with('company')
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function roles(?Account $account): array
    {
        // Comprare può chiunque abbia un conto; vendere solo un conto aziendale
        // che in questo momento è nelle condizioni commerciali per farlo.
        $roles = ['buyer'];

        if ($account && $account->owner_type === 'company' && $account->canSell()) {
            $roles[] = 'seller';
        }

        return $roles;
    }
}
