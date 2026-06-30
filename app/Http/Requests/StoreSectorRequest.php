<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:120', 'unique:sectors,name'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'parent_id'  => ['nullable', 'integer', 'exists:sectors,id'],
        ];
    }
}
