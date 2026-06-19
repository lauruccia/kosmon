<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContractTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'contract_text' => ['nullable', 'string', 'max:100000'],
        ];
    }
}
