<?php

namespace App\Http\Controllers;

use App\Models\ListingAttribute;
use App\Models\ListingAttributeValue;
use App\Models\ListingVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

/**
 * Attributi e valori dei prodotti variabili — fase D, 25/08/2026.
 *
 * Li gestisce l'ADMIN, non il venditore: scelta esplicita di Laura. Il
 * venditore sceglie fra i valori che esistono, e questo tiene il catalogo con
 * un vocabolario solo — senza "Taglia", "taglie", "TAGLIA" e "Misura" a
 * indicare la stessa cosa. Stesso pattern di AdminListingCategoryController.
 *
 * Struttura fissa a due livelli: attributo -> valori. Un valore non ha
 * sotto-valori.
 */
class AdminListingAttributeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    public function index(): View
    {
        $attributi = ListingAttribute::query()
            ->with('values')
            ->withCount('values')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.listing-attributes', [
            'attributi' => $attributi,
            'pageTitle' => 'Attributi prodotti variabili',
            'activeNav' => 'admin-listing-attributes',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'name'       => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $attributo = ListingAttribute::create([
            'name'       => trim($dati['name']),
            'sort_order' => $dati['sort_order'] ?? 99,
        ]);

        return back()->with('success', "Attributo \"{$attributo->name}\" aggiunto. Ora aggiungici i valori.");
    }

    public function update(Request $request, ListingAttribute $listingAttribute): RedirectResponse
    {
        $dati = $request->validate([
            'name'       => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        // Lo slug NON si tocca: è l'identificativo stabile.
        $listingAttribute->update([
            'name'       => trim($dati['name']),
            'sort_order' => $dati['sort_order'] ?? $listingAttribute->sort_order,
        ]);

        return back()->with('success', 'Attributo aggiornato.');
    }

    public function toggle(ListingAttribute $listingAttribute): RedirectResponse
    {
        $listingAttribute->update(['is_active' => ! $listingAttribute->is_active]);

        return back()->with('success', $listingAttribute->is_active
            ? "\"{$listingAttribute->name}\" è di nuovo disponibile ai venditori."
            : "\"{$listingAttribute->name}\" non sarà più proposto ai venditori. I prodotti che lo usano non cambiano.");
    }

    public function destroy(ListingAttribute $listingAttribute): RedirectResponse
    {
        // Un attributo usato da qualche prodotto non si cancella: si spegne.
        // Cancellarlo porterebbe via in cascata i valori e con loro le
        // combinazioni dei venditori — e gli ordini già fatti perderebbero il
        // riferimento (il testo resterebbe, grazie allo snapshot, ma il
        // prodotto in vendita diventerebbe incomprabile senza preavviso).
        if ($this->inUso($listingAttribute)) {
            return back()->with('error', "\"{$listingAttribute->name}\" è usato da prodotti già pubblicati: puoi solo disattivarlo.");
        }

        $nome = $listingAttribute->name;
        $listingAttribute->delete();

        return back()->with('success', "Attributo \"{$nome}\" eliminato.");
    }

    // ── Valori ───────────────────────────────────────────────────────────────

    public function storeValue(Request $request, ListingAttribute $listingAttribute): RedirectResponse
    {
        $dati = $request->validate([
            'value'      => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        ListingAttributeValue::create([
            'listing_attribute_id' => $listingAttribute->id,
            'value'                => trim($dati['value']),
            'sort_order'           => $dati['sort_order'] ?? 99,
        ]);

        return back()->with('success', 'Valore aggiunto.');
    }

    public function updateValue(Request $request, ListingAttributeValue $value): RedirectResponse
    {
        $dati = $request->validate([
            'value'      => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $value->update([
            'value'      => trim($dati['value']),
            'sort_order' => $dati['sort_order'] ?? $value->sort_order,
        ]);

        return back()->with('success', 'Valore aggiornato.');
    }

    public function toggleValue(ListingAttributeValue $value): RedirectResponse
    {
        $value->update(['is_active' => ! $value->is_active]);

        return back()->with('success', 'Valore aggiornato.');
    }

    public function destroyValue(ListingAttributeValue $value): RedirectResponse
    {
        if ($value->variants()->exists()) {
            return back()->with('error', "\"{$value->value}\" è usato da combinazioni già pubblicate: puoi solo disattivarlo.");
        }

        $etichetta = $value->value;
        $value->delete();

        return back()->with('success', "Valore \"{$etichetta}\" eliminato.");
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function inUso(ListingAttribute $attributo): bool
    {
        return ListingVariant::query()
            ->whereHas('values', fn ($q) => $q->where('listing_attribute_id', $attributo->id))
            ->exists();
    }
}
