<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectorRequest;
use App\Http\Requests\UpdateSectorRequest;
use App\Models\Sector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

class AdminSectorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    public function index(): View
    {
        $sectors = Sector::orderBy('sort_order')->orderBy('name')->get();
        return view('admin.sectors', compact('sectors'));
    }

    public function store(StoreSectorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Sector::create([
            'name'       => $data['name'],
            'is_active'  => true,
            'sort_order' => $data['sort_order'] ?? 99,
        ]);

        return back()->with('success', "Settore \"{$data['name']}\" aggiunto.");
    }

    public function update(UpdateSectorRequest $request, Sector $sector): RedirectResponse
    {
        $data = $request->validated();

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
