<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateListingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:120', Rule::unique('listing_categories', 'name')->ignore($this->route('listingCategory'))],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
            'parent_id'  => ['nullable', 'integer', 'exists:listing_categories,id'],
        ];
    }
}
