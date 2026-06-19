<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validazione del form "aggiungi beneficiario" del portale.
 * I controlli di business (non puoi salvare te stesso, destinatario attivo) restano nel controller,
 * perche' dipendono dall'account risolto nel corpo della action.
 */
class StoreBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'beneficiary_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'alias'                  => ['nullable', 'string', 'max:100'],
            'notes'                  => ['nullable', 'string', 'max:500'],
        ];
    }
}
