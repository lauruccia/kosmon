<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyMenuVisibilityRequest;
use App\Http\Requests\StoreMenuVisibilityRequest;
use App\Models\Company;
use App\Models\MenuVisibility;
use App\Models\User;
use App\Services\MenuVisibilityService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;

class AdminMenuVisibilityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    // -----------------------------------------------------------------------
    // Index — mostra tutte le regole raggruppate per voce di menu
    // -----------------------------------------------------------------------
    public function index()
    {
        $menuItems = MenuVisibility::menuItems();

        // Tutte le regole esistenti, indicizzate per lookup rapido
        $rules = MenuVisibility::all()->keyBy(fn($r) => $this->ruleKey($r));

        // Elenchi per i select "utente specifico" e "azienda specifica"
        $users     = User::where('is_super_admin', false)
                         ->whereNull('managed_account_id')
                         ->orderBy('name')
                         ->get(['id', 'name', 'email']);

        $companies = Company::orderBy('name')->get(['id', 'name']);

        return view('admin.menu-visibility.index', compact(
            'menuItems', 'rules', 'users', 'companies'
        ) + [
            'pageTitle' => 'Menu utenti',
            'activeNav' => 'admin-menu-visibility',
        ]);
    }

    // -----------------------------------------------------------------------
    // Store / toggle — salva una singola regola
    // -----------------------------------------------------------------------
    public function store(StoreMenuVisibilityRequest $request)
    {
        $data = $request->validated();

        // Coerenza: rimuovi campi non pertinenti allo scope
        if ($data['scope_type'] === MenuVisibility::SCOPE_GLOBAL) {
            $data['scope_id']    = null;
            $data['account_type'] = null;
        } elseif ($data['scope_type'] === MenuVisibility::SCOPE_ACCOUNT_TYPE) {
            $data['scope_id'] = null;
        } else {
            // company o user: scope_id obbligatorio
            $data['account_type'] = null;
            abort_if(empty($data['scope_id']), 422, 'scope_id obbligatorio per questo scope.');
        }

        MenuVisibilityService::setRule(
            key:         $data['menu_item_key'],
            scopeType:   $data['scope_type'],
            visible:     (bool) $data['visible'],
            scopeId:     $data['scope_id'] ?? null,
            accountType: $data['account_type'] ?? null,
        );

        return back()->with('portal_success', 'Regola salvata.');
    }

    // -----------------------------------------------------------------------
    // Destroy — elimina una regola (torna al default)
    // -----------------------------------------------------------------------
    public function destroy(DestroyMenuVisibilityRequest $request)
    {
        $data = $request->validated();

        MenuVisibilityService::deleteRule(
            key:         $data['menu_item_key'],
            scopeType:   $data['scope_type'],
            scopeId:     $data['scope_id'] ?? null,
            accountType: $data['account_type'] ?? null,
        );

        return back()->with('portal_success', 'Regola rimossa.');
    }

    // -----------------------------------------------------------------------
    // Bulk reset — rimuove TUTTE le regole per una voce di menu
    // -----------------------------------------------------------------------
    public function reset(string $key)
    {
        MenuVisibility::where('menu_item_key', $key)->delete();
        return back()->with('portal_success', "Regole per '{$key}' azzerate.");
    }

    // -----------------------------------------------------------------------

    private function ruleKey(MenuVisibility $r): string
    {
        return implode('|', [
            $r->menu_item_key,
            $r->scope_type,
            $r->account_type ?? '',
            $r->scope_id ?? '',
        ]);
    }
}
