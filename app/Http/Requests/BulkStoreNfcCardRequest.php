<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreNfcCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:50'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ];
    }
}
