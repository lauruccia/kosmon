<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCashbackRuleRequest;
use App\Http\Requests\UpdateCashbackRuleRequest;
use App\Models\CashbackRule;
use App\Models\User;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;

class CashbackRuleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

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

    public function store(StoreCashbackRuleRequest $request)
    {
        $data = $request->validated();

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

    public function update(UpdateCashbackRuleRequest $request, CashbackRule $cashbackRule)
    {
        $data = $request->validated();

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
