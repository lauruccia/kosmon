<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invio richiesta di pagamento testuale (creditore -> debitore).
 * authorize() replica il solo abort_if(canAccessBackoffice) di resolveCurrentContext,
 * cosi' il 403 backoffice resta PRIMA della validazione. I controlli di business
 * (conto attivo, no self, destinatario valido) restano nel controller.
 */
class StoreTextPaymentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->canAccessBackoffice();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('amount')) {
            $this->merge(['amount' => str_replace(',', '.', (string) $this->input('amount'))]);
        }
    }

    public function rules(): array
    {
        return [
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'causale'       => ['required', 'string', 'min:3', 'max:500'],
            'note'          => ['nullable', 'string', 'max:1000'],
            'due_date'      => ['nullable', 'date', 'after:today'],
        ];
    }
}
