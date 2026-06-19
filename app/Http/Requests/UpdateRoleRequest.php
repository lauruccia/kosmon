<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->is_super_admin || $user->hasPermission('roles.manage'));
    }

    public function rules(): array
    {
        return [
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
