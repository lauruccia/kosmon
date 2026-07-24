<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashbackRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:100'],
            // L'admin digita la soglia/cap in KY (es. "50" o "50,00"), non in
            // centesimi: 'numeric' valida la stringa decimale così com'è; la
            // conversione avviene nel controller con ky_to_cents() prima del
            // salvataggio, stessa convenzione degli altri form di importo
            // (vedi CLAUDE.md "Importi sempre in centesimi"). PRIMA questi
            // due campi erano 'integer' e venivano salvati COSÌ COME
            // DIGITATI in colonne che sono in centesimi ovunque nel resto
            // del progetto: una soglia digitata "50 KY" diventava 50
            // centesimi (0,50 KY) — stesso bug ×100 già trovato e corretto
            // nel prezzo dei prodotti shop il 24/07.
            'min_amount'         => ['required', 'numeric', 'min:0'],
            'percentage'         => ['required', 'numeric', 'min:0.01', 'max:100'],
            'max_cashback'       => ['nullable', 'numeric', 'min:0.01'],
            'applicable_kinds'   => ['required', 'array', 'min:1'],
            'applicable_kinds.*' => ['string'],
            'is_active'          => ['boolean'],
            'valid_from'         => ['nullable', 'date'],
            'valid_until'        => ['nullable', 'date', 'after_or_equal:valid_from'],
            'target_type'        => ['required', 'in:all,company,personal,specific_user'],
            'target_user_id'     => ['nullable', 'required_if:target_type,specific_user', 'exists:users,id'],
        ];
    }
}
