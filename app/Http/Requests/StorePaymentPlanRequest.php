<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione di un piano rateale. authorize() replica l'abort_if(canAccessBackoffice)
 * di resolveCurrentContext (403 backoffice prima della validazione). La risoluzione
 * dell'account e i controlli di business restano nel controller/servizio.
 */
class StorePaymentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->canAccessBackoffice();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('total_amount')) {
            $this->merge(['total_amount' => str_replace(',', '.', (string) $this->input('total_amount'))]);
        }
    }

    public function rules(): array
    {
        return [
            'initiator_role'     => ['required', 'in:debtor,creditor'],
            'counterparty_id'    => ['required', 'integer', 'exists:accounts,id'],
            'total_amount'       => ['required', 'numeric', 'min:0.02'],
            'installments_count' => ['required', 'integer', 'min:2', 'max:60'],
            'frequency'          => ['required', 'in:weekly,biweekly,monthly'],
            'first_due_date'     => ['required', 'date', 'after_or_equal:today'],
            'description'        => ['nullable', 'string', 'max:255'],
        ];
    }
}
