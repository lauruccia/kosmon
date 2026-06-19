<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione richiesta di pagamento NFC (merchant). I controlli su card attiva,
 * limiti e conto merchant restano nel controller (tornano JSON 403/422 dedicati).
 */
class CreateNfcCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'card_uuid'   => ['required', 'string'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
