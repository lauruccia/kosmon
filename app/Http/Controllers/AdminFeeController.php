<?php

namespace App\Http\Controllers;

use App\Models\TransactionFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminFeeController extends Controller
{
    public function index(): View
    {
        $fees = TransactionFee::orderBy('operation_kind')->get();
        return view('admin.fees.index', [
            'pageTitle'  => 'Commissioni transazioni',
            'fees'       => $fees,
            'kindOptions'=> TransactionFee::kindOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.fees.form', [
            'pageTitle'   => 'Nuova commissione',
            'fee'         => null,
            'kindOptions' => TransactionFee::kindOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        TransactionFee::create($data);
        return redirect()->route('admin.fees.index')->with('success', 'Commissione creata.');
    }

    public function edit(TransactionFee $fee): View
    {
        return view('admin.fees.form', [
            'pageTitle'   => 'Modifica commissione',
            'fee'         => $fee,
            'kindOptions' => TransactionFee::kindOptions(),
        ]);
    }

    public function update(Request $request, TransactionFee $fee): RedirectResponse
    {
        $fee->update($this->validated($request));
        return redirect()->route('admin.fees.index')->with('success', 'Commissione aggiornata.');
    }

    public function destroy(TransactionFee $fee): RedirectResponse
    {
        $fee->delete();
        return redirect()->route('admin.fees.index')->with('success', 'Commissione eliminata.');
    }

    public function toggle(TransactionFee $fee): RedirectResponse
    {
        $fee->update(['is_active' => ! $fee->is_active]);
        return back()->with('success', $fee->is_active ? 'Commissione attivata.' : 'Commissione disattivata.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'operation_kind' => ['required', 'string', 'max:60'],
            'fee_type'       => ['required', 'in:flat,percentage'],
            'fee_value'      => ['required', 'numeric', 'min:0'],
            'min_fee'        => ['nullable', 'integer', 'min:0'],
            'max_fee'        => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['boolean'],
            'description'    => ['nullable', 'string', 'max:500'],
        ]);
    }
}
