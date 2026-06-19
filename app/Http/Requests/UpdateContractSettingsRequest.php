<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContractSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'contract_force_sign'    => ['nullable', 'boolean'],
            'contract_required_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
