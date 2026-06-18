<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Richiesta del gestore di un sottoconto per aumento limite / sforamento.
 * L'autorizzazione (no backoffice + gestore effettivo del sottoconto) è qui,
 * così viene valutata PRIMA della validazione — stesso ordine del controller originale.
 */
class StoreSubAccountLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || $user->canAccessBackoffice()) {
            return false;
        }

        $subaccount = $this->route('subaccount');

        return $subaccount !== null && $user->canManageSubAccount($subaccount);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('requested_amount')) {
            // Normalizza virgola → punto prima della validazione numerica
            $this->merge([
                'requested_amount' => str_replace(',', '.', (string) $this->input('requested_amount')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'type'             => ['required', 'in:spending_limit_increase,daily_limit_increase,monthly_limit_increase,temporary_overdraft'],
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason'           => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
