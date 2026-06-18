<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoleController extends Controller
{
    use AuthorizesBackoffice;

    public function roles(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        return view('admin.roles', [
            'pageTitle' => 'Ruoli e permessi',
            'roles' => Role::query()->with('permissions')->orderBy('scope')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('name')->get(),
            'activeNav' => 'roles',
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'roles.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::updateOrCreate([
            'slug' => Str::slug($validated['name']),
        ], [
            'name' => $validated['name'],
            'scope' => $validated['scope'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($validated['permissions'] ?? []);

        return back()->with('portal_success', 'Ruolo creato correttamente.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'roles.manage');

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->forceFill([
            'description' => $validated['description'] ?? $role->description,
        ])->save();
        $role->permissions()->sync($validated['permissions'] ?? []);

        return back()->with('portal_success', 'Ruolo aggiornato correttamente.');
    }
}
