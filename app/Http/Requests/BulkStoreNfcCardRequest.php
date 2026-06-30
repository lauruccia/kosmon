<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesNfcCardOwner;
use Illuminate\Foundation\Http\FormRequest;

class BulkStoreNfcCardRequest extends FormRequest
{
    use ResolvesNfcCardOwner;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return array_merge($this->ownerRules(), [
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'notes'    => ['nullable', 'string', 'max:500'],
        ]);
    }
}
