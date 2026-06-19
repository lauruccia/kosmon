<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Impostazione/cambio del PIN di pagamento dell'utente autenticato. */
class SetPaymentPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }
}
