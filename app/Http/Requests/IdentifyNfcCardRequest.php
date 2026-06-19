<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Identificazione card NFC da uuid+sig. L'autenticazione della card e' via HMAC
 * (verificato nel controller), non a livello utente: authorize() resta true.
 */
class IdentifyNfcCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string', 'size:36'],
            'sig'  => ['required', 'string'],
        ];
    }
}
