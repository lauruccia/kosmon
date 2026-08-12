<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreListingCategoryRequest;
use App\Http\Requests\UpdateListingCategoryRequest;
use App\Models\Listing;
use App\Models\ListingCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

/**
 * CRUD categorie/sotto-categorie shop (2026-08-12, richiesta di Laura).
 * Stesso pattern di AdminSectorController, ma con struttura FISSA a 2 livelli
 * (categoria -> sotto-categoria): una sotto-categoria non puo' avere a sua
 * volta sotto-categorie, a differenza dei Settori che ammettono profondita'
 * arbitraria.
 */
class AdminListingCategoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    public function index(): View
    {
        $categories = ListingCategory::flattenedTree();

        // Candidati padre per il form "Aggiungi": solo le categorie di primo
        // livello (mai una sotto-categoria, struttura fissa a 2 livelli).
        $parents = ListingCategory::whereNull('parent_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.listing-categories', [
            'categories' => $categories,
            'parents'    => $parents,
            'pageTitle'  => 'Categorie shop',
            'activeNav'  => 'admin-listing-categories',
        ]);
    }

    public function store(StoreListingCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (! empty($data['parent_id'])) {
            $parent = ListingCategory::find($data['parent_id']);
            if ($parent && $parent->parent_id !== null) {
                return back()->withInput()->with('error', 'Puoi creare sotto-categorie solo sotto una categoria principale, non sotto un\'altra sotto-categoria.');
            }
        }

        $category = ListingCategory::create([
            'name'       => $data['name'],
            'slug'       => ListingCategory::makeUniqueSlug($data['name']),
            'is_active'  => true,
            'sort_order' => $data['sort_order'] ?? 99,
            'parent_id'  => $data['parent_id'] ?? null,
        ]);

        $kind = $category->parent_id ? 'Sotto-categoria' : 'Categoria';

        return back()->with('success', "{$kind} \"{$category->name}\" aggiunta.");
    }

    public function update(UpdateListingCategoryRequest $request, ListingCategory $listingCategory): RedirectResponse
    {
        $data = $request->validated();

        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $listingCategory->parent_id;

        if ($parentId !== null) {
            $parentId = (int) $parentId;

            if ($parentId === $listingCategory->id || in_array($parentId, ListingCategory::subtreeIds($listingCategory->id), true)) {
                return back()->with('error', 'Una categoria non può essere sotto-categoria di sé stessa.');
            }

            $parent = ListingCategory::find($parentId);
            if ($parent && $parent->parent_id !== null) {
                return back()->with('error', 'Puoi creare sotto-categorie solo sotto una categoria principale, non sotto un\'altra sotto-categoria.');
            }
        }

        // NB: lo slug non viene MAI rigenerato qui — rinominare una categoria
        // dal pannello admin non deve scollegare i prodotti gia' assegnati
        // (vedi ListingCategory::makeUniqueSlug(), usato solo in creazione).
        $listingCategory->update([
            'name'       => $data['name'],
            'sort_order' => $data['sort_order'] ?? $listingCategory->sort_order,
            'is_active'  => isset($data['is_active']) ? (bool) $data['is_active'] : $listingCategory->is_active,
            'parent_id'  => $parentId,
        ]);

        return back()->with('success', 'Categoria aggiornata.');
    }

    public function toggle(ListingCategory $listingCategory): RedirectResponse
    {
        $listingCategory->update(['is_active' => ! $listingCategory->is_active]);

        return back()->with('success', $listingCategory->is_active ? 'Categoria riattivata.' : 'Categoria disattivata.');
    }

    public function destroy(ListingCategory $listingCategory): RedirectResponse
    {
        if ($listingCategory->children()->exists()) {
            return back()->with('error', 'Impossibile eliminare: la categoria ha delle sotto-categorie. Eliminale o spostale prima.');
        }

        $inUse = Listing::where('category', $listingCategory->slug)
            ->orWhere('subcategory', $listingCategory->slug)
            ->exists();

        if ($inUse) {
            return back()->with('error', 'Impossibile eliminare: la categoria è usata da almeno un prodotto dello shop.');
        }

        $listingCategory->delete();

        return back()->with('success', 'Categoria eliminata.');
    }
}
