<?php

namespace App\Http\Controllers;

use App\Models\CashbackRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashbackRuleController extends Controller
{
    public function index()
    {
        $rules = CashbackRule::with(['creator', 'targetUser'])
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        return view('admin.cashback.index', compact('rules'));
    }

    public function create()
    {
        $kindOptions        = CashbackRule::kindOptions();
        $targetTypeOptions  = CashbackRule::targetTypeOptions();
        $users              = User::orderBy('name')->get(['id', 'name', 'email']);
        return view('admin.cashback.form', [
            'rule'              => new CashbackRule(),
            'kindOptions'       => $kindOptions,
            'targetTypeOptions' => $targetTypeOptions,
            'users'             => $users,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'min_amount'       => 'required|integer|min:0',
            'percentage'       => 'required|numeric|min:0.01|max:100',
            'max_cashback'     => 'nullable|integer|min:1',
            'applicable_kinds' => 'required|array|min:1',
            'applicable_kinds.*' => 'string',
            'is_active'        => 'boolean',
            'valid_from'       => 'nullable|date',
            'valid_until'      => 'nullable|date|after_or_equal:valid_from',
            'target_type'      => 'required|in:all,company,personal,specific_user',
            'target_user_id'   => 'nullable|required_if:target_type,specific_user|exists:users,id',
        ]);

        $data['is_active']      = $request->boolean('is_active', true);
        $data['target_user_id'] = $data['target_type'] === 'specific_user' ? $data['target_user_id'] : null;
        $data['created_by']     = Auth::id();

        CashbackRule::create($data);

        return redirect()->route('admin.cashback.index')
            ->with('portal_success', 'Regola cashback creata con successo.');
    }

    public function edit(CashbackRule $cashbackRule)
    {
        $kindOptions        = CashbackRule::kindOptions();
        $targetTypeOptions  = CashbackRule::targetTypeOptions();
        $users              = User::orderBy('name')->get(['id', 'name', 'email']);
        return view('admin.cashback.form', [
            'rule'              => $cashbackRule,
            'kindOptions'       => $kindOptions,
            'targetTypeOptions' => $targetTypeOptions,
            'users'             => $users,
        ]);
    }

    public function update(Request $request, CashbackRule $cashbackRule)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'min_amount'       => 'required|integer|min:0',
            'percentage'       => 'required|numeric|min:0.01|max:100',
            'max_cashback'     => 'nullable|integer|min:1',
            'applicable_kinds' => 'required|array|min:1',
            'applicable_kinds.*' => 'string',
            'is_active'        => 'boolean',
            'valid_from'       => 'nullable|date',
            'valid_until'      => 'nullable|date|after_or_equal:valid_from',
            'target_type'      => 'required|in:all,company,personal,specific_user',
            'target_user_id'   => 'nullable|required_if:target_type,specific_user|exists:users,id',
        ]);

        $data['is_active']      = $request->boolean('is_active', false);
        $data['target_user_id'] = $data['target_type'] === 'specific_user' ? $data['target_user_id'] : null;

        $cashbackRule->update($data);

        return redirect()->route('admin.cashback.index')
            ->with('portal_success', 'Regola cashback aggiornata.');
    }

    public function destroy(CashbackRule $cashbackRule)
    {
        $cashbackRule->delete();

        return redirect()->route('admin.cashback.index')
            ->with('portal_success', 'Regola cashback eliminata.');
    }

    public function toggleActive(CashbackRule $cashbackRule)
    {
        $cashbackRule->update(['is_active' => ! $cashbackRule->is_active]);

        return back()->with('portal_success', 'Stato regola aggiornato.');
    }
}
