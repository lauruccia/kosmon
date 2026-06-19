<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proposta di compensazione (netting). authorize() replica l'abort_if(canAccessBackoffice)
 * di resolveCurrentContext. Il controllo "almeno un trasferimento selezionato" resta nel
 * controller (torna back()->with(...), non un errore di validazione).
 */
class StoreNettingProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'counterparty_account_id'     => ['required', 'integer', 'exists:accounts,id'],
            'proposer_transfer_ids'       => ['nullable', 'array'],
            'proposer_transfer_ids.*'     => ['integer'],
            'counterparty_transfer_ids'   => ['nullable', 'array'],
            'counterparty_transfer_ids.*' => ['integer'],
            'description'                 => ['nullable', 'string', 'max:500'],
        ];
    }
}
