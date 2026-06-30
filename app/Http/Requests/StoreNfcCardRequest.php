<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesNfcCardOwner;
use Illuminate\Foundation\Http\FormRequest;

class StoreNfcCardRequest extends FormRequest
{
    use ResolvesNfcCardOwner;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessBackoffice();
    }

    public function rules(): array
    {
        return array_merge($this->ownerRules(), [
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
