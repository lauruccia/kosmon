<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessMarketplace();
    }

    public function rules(): array
    {
        return [
            'type'         => ['required', Rule::in(array_keys(Announcement::TYPES))],
            'title'        => ['required', 'string', 'max:160'],
            'body'         => ['required', 'string', 'max:3000'],
            'sector'       => ['required', Rule::in(array_keys(Announcement::SECTORS))],
            'contact_info' => ['nullable', 'string', 'max:200'],
            'expires_at'   => ['nullable', 'date', 'after:today'],
            'featured'     => ['nullable', 'boolean'],
        ];
    }
}
