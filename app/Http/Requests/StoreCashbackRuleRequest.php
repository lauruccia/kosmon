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
            'min_amount'         => ['required', 'integer', 'min:0'],
            'percentage'         => ['required', 'numeric', 'min:0.01', 'max:100'],
            'max_cashback'       => ['nullable', 'integer', 'min:1'],
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
