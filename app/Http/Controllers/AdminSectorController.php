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
        $sectors = Sector::flattenedTree();          // ordine gerarchico con depth/is_leaf
        $parents = Sector::parentCandidates();        // candidati padre per il form "Aggiungi"

        return view('admin.sectors', compact('sectors', 'parents'));
    }

    public function store(StoreSectorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Sector::create([
            'name'       => $data['name'],
            'is_active'  => true,
            'sort_order' => $data['sort_order'] ?? 99,
            'parent_id'  => $data['parent_id'] ?? null,
        ]);

        return back()->with('success', "Settore \"{$data['name']}\" aggiunto.");
    }

    public function update(UpdateSectorRequest $request, Sector $sector): RedirectResponse
    {
        $data = $request->validated();

        // Determina il nuovo padre (chiave presente = aggiornala, anche a null)
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $sector->parent_id;

        // Anti-ciclo: il padre non può essere il settore stesso né un suo discendente.
        if ($parentId !== null) {
            $parentId = (int) $parentId;
            if (in_array($parentId, Sector::subtreeIds($sector->id), true)) {
                return back()->with('error', 'Un settore non può essere figlio di sé stesso o di un proprio sotto-settore.');
            }
        }

        $sector->update([
            'name'       => $data['name'],
            'sort_order' => $data['sort_order'] ?? $sector->sort_order,
            'is_active'  => isset($data['is_active']) ? (bool) $data['is_active'] : $sector->is_active,
            'parent_id'  => $parentId,
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
        if ($sector->children()->exists()) {
            return back()->with('error', 'Impossibile eliminare: il settore ha dei sotto-settori. Eliminali o spostali prima.');
        }

        $inUse = \App\Models\Company::where('sector', $sector->name)->exists();
        if ($inUse) {
            return back()->with('error', "Impossibile eliminare: il settore è usato da almeno un'azienda.");
        }

        $sector->delete();
        return back()->with('success', 'Settore eliminato.');
    }
}
