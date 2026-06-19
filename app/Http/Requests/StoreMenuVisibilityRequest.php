<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'menu_item_key' => ['required', 'string', 'max:64'],
            'scope_type'    => ['required', 'in:global,account_type,company,user'],
            'scope_id'      => ['nullable', 'integer', 'min:1'],
            'account_type'  => ['nullable', 'in:private,company'],
            'visible'       => ['required', 'in:0,1'],
        ];
    }
}
