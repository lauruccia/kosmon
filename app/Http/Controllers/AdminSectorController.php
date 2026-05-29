<?php

namespace App\Http\Controllers;

use App\Models\Sector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSectorController extends Controller
{
    public function index(): View
    {
        $sectors = Sector::orderBy('sort_order')->orderBy('name')->get();
        return view('admin.sectors', compact('sectors'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120', 'unique:sectors,name'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        Sector::create([
            'name'       => $data['name'],
            'is_active'  => true,
            'sort_order' => $data['sort_order'] ?? 99,
        ]);

        return back()->with('success', "Settore \"{$data['name']}\" aggiunto.");
    }

    public function update(Request $request, Sector $sector): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120', 'unique:sectors,name,' . $sector->id],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $sector->update([
            'name'       => $data['name'],
            'sort_order' => $data['sort_order'] ?? $sector->sort_order,
            'is_active'  => isset($data['is_active']) ? (bool) $data['is_active'] : $sector->is_active,
        ]);

        return back()->with('success', 'Settore aggiornato.');
    }

    public function toggle(Sector $sector): RedirectResponse
    {
        $sector->update(['is_active' => ! $sector->is_active]);
        return back()->with('success', $sector->is_active ? 'Settore riattivato.' : 'Settore disattivato.');
    }

    public function destroy(Sector $sector): RedirectResponse
    {
        $inUse = \App\Models\Company::where('sector', $sector->name)->exists();
        if ($inUse) {
            return back()->with('error', "Impossibile eliminare: il settore è usato da almeno un'azienda.");
        }
        $sector->delete();
        return back()->with('success', 'Settore eliminato.');
    }
}
