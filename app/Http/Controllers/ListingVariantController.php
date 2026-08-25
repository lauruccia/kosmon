<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingAttribute;
use App\Models\ListingAttributeValue;
use App\Models\ListingVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Le varianti di un prodotto, dal lato del VENDITORE — fase D, 25/08/2026.
 *
 * Sta in una pagina sua invece che dentro il form prodotto, che è già lungo ed
 * è condiviso fra creazione e modifica: le combinazioni si gestiscono su un
 * prodotto che esiste già, e infilarle là dentro avrebbe voluto dire toccare
 * un form che funziona.
 *
 * Il giro è in due tempi, senza righe di JavaScript da mantenere:
 *   1. il venditore spunta i valori che gli servono (S, M, L / rosso, blu)
 *   2. il sistema genera le combinazioni mancanti, e lui ci mette prezzo e
 *      giacenza.
 *
 * IL PREZZO SI SCRIVE IN CHIARO, non come differenza. Il venditore digita
 * "22,00" e qui dentro diventa "+2,00" sui 20,00 del prodotto: ragiona in
 * prezzi, il database ragiona in delta — ed è il delta che permette alle
 * Offerte della settimana di continuare a funzionare (vedi ListingVariant).
 */
class ListingVariantController extends Controller
{
    public function index(Request $request, Listing $listing): View|RedirectResponse
    {
        if ($redirect = $this->assertPuoGestire($request, $listing)) {
            return $redirect;
        }

        $listing->load(['variants.values.attribute', 'activeOffer']);

        // Quali valori sono già in uso su questo prodotto: servono a spuntare
        // le caselle giuste quando si riapre la pagina.
        $valoriInUso = $listing->variants
            ->flatMap(fn (ListingVariant $v) => $v->values->pluck('id'))
            ->unique()
            ->values()
            ->all();

        return view('portal.shop-variants', [
            'pageTitle'      => 'Varianti — ' . $listing->title,
            'currentAccount' => \App\Models\Account::operativoPer($request->user()),
            'currentUser'    => $request->user(),
            'listing'        => $listing,
            'attributi'      => ListingAttribute::utilizzabili(),
            'valoriInUso'    => $valoriInUso,
            'activeNav'      => 'shop',
        ]);
    }

    /**
     * Genera le combinazioni dei valori spuntati, senza toccare quelle che
     * esistono già (prezzi e giacenze non si perdono mai).
     */
    public function generate(Request $request, Listing $listing): RedirectResponse
    {
        if ($redirect = $this->assertPuoGestire($request, $listing)) {
            return $redirect;
        }

        $dati = $request->validate([
            'valori'   => ['required', 'array', 'min:1'],
            'valori.*' => ['integer', 'exists:listing_attribute_values,id'],
        ]);

        $valori = ListingAttributeValue::query()
            ->with('attribute')
            ->whereIn('id', $dati['valori'])
            ->where('is_active', true)
            ->get();

        if ($valori->isEmpty()) {
            return back()->with('portal_error', 'Nessun valore valido selezionato.');
        }

        // Un gruppo per attributo: le combinazioni sono il prodotto cartesiano
        // dei gruppi. Taglia(S,M) x Colore(rosso,blu) = 4 combinazioni.
        $perAttributo = $valori
            ->groupBy('listing_attribute_id')
            ->map(fn ($g) => $g->sortBy('sort_order')->values())
            ->values();

        $combinazioni = $this->prodottoCartesiano($perAttributo->all());

        if (count($combinazioni) > 200) {
            return back()->with('portal_error', 'Sono troppe combinazioni (' . count($combinazioni) . '): riduci i valori selezionati.');
        }

        $esistenti = $listing->variants()->with('values')->get()
            ->mapWithKeys(fn (ListingVariant $v) => [implode('-', $v->chiaveValori()) => $v]);

        $create = 0;
        $spente = 0;

        DB::transaction(function () use ($combinazioni, $listing, $esistenti, &$create, &$spente) {
            $chiaviGenerate = [];

            foreach ($combinazioni as $i => $combinazione) {
                $ids = collect($combinazione)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
                $chiave = $ids->implode('-');
                $chiaviGenerate[] = $chiave;

                if ($esistenti->has($chiave)) {
                    continue;  // c'è già: prezzo e giacenza restano suoi
                }

                $variante = ListingVariant::create([
                    'listing_id' => $listing->id,
                    'sort_order' => $i,
                ]);
                $variante->values()->sync($ids->all());
                $create++;
            }

            // LE COMBINAZIONI RIMASTE INDIETRO.
            //
            // Aggiungere un secondo attributo a un prodotto che ne aveva uno
            // solo lascia orfane le vecchie: chi aveva "S" e "M" e aggiunge il
            // colore si ritrova "S", "M", "S rosso", "S blu", "M rosso",
            // "M blu" — e chi compra vedrebbe nel selettore sia "S" sia
            // "S rosso", senza capire la differenza. Stessa cosa togliendo un
            // valore: le combinazioni che lo usavano restano appese.
            //
            // Vengono SPENTE, non cancellate: possono essere già state vendute,
            // e il venditore deve poter decidere lui se riaccenderle o
            // eliminarle. Spente spariscono dal selettore di chi compra, che è
            // la cosa che conta subito — e se una era già nel carrello di
            // qualcuno, la cassa la ferma con "Questa combinazione non è più
            // disponibile" invece di venderla di nascosto (CartItem::
            // motivoIndisponibilita).
            //
            // La regola è una sola, ed è quella che si spiega in una riga: una
            // combinazione che questa generazione non produce non è più fra
            // quelle che il venditore ha chiesto.
            foreach ($esistenti as $chiave => $variante) {
                // (string): con un attributo solo la chiave è "7", e PHP le
                // chiavi che sembrano numeri se le tiene come interi — un
                // in_array stretto contro "7" direbbe di no e spegnerebbe
                // combinazioni appena generate. Con due attributi ("7-9") non
                // succede, ed è il motivo per cui sfugge facilmente.
                if (in_array((string) $chiave, $chiaviGenerate, true) || ! $variante->is_active) {
                    continue;
                }

                $variante->update(['is_active' => false]);
                $spente++;
            }

            if (! $listing->has_variants) {
                $listing->forceFill(['has_variants' => true])->save();
            }
        });

        $messaggio = $create > 0
            ? ($create === 1 ? 'Aggiunta 1 combinazione.' : "Aggiunte {$create} combinazioni.")
            : 'Nessuna combinazione nuova: c\'erano già tutte.';

        if ($spente > 0) {
            $messaggio .= $spente === 1
                ? ' Una combinazione non più coerente con i valori scelti è stata disattivata: la trovi qui sotto, puoi riattivarla o eliminarla.'
                : " {$spente} combinazioni non più coerenti con i valori scelti sono state disattivate: le trovi qui sotto, puoi riattivarle o eliminarle.";
        }

        return back()->with('portal_success', $messaggio);
    }

    /** Prezzo e giacenza di ogni combinazione, salvati in blocco. */
    public function update(Request $request, Listing $listing): RedirectResponse
    {
        if ($redirect = $this->assertPuoGestire($request, $listing)) {
            return $redirect;
        }

        $dati = $request->validate([
            'varianti'          => ['required', 'array'],
            'varianti.*.prezzo' => ['nullable', 'string', 'max:20'],
            'varianti.*.scorte' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'varianti.*.sku'    => ['nullable', 'string', 'max:80'],
        ]);

        $varianti = $listing->variants()->get()->keyBy('id');
        $prezzoBase = (int) $listing->price_ky;

        foreach ($dati['varianti'] as $id => $valori) {
            $variante = $varianti->get((int) $id);
            if (! $variante) {
                continue;
            }

            // Il venditore scrive il prezzo pieno; qui diventa la differenza
            // rispetto al prezzo di listino del prodotto. Attenzione: si usa
            // `price_ky` e non `effective_price_ky` — la base è il LISTINO, non
            // il prezzo di un'offerta che oggi c'è e domani no.
            $delta = $variante->price_delta_ky;
            $prezzoScritto = trim((string) ($valori['prezzo'] ?? ''));

            if ($prezzoScritto !== '') {
                $delta = ky_to_cents($prezzoScritto) - $prezzoBase;
            }

            $variante->update([
                'price_delta_ky' => $delta,
                'stock_quantity' => ($valori['scorte'] ?? null) === null || $valori['scorte'] === ''
                    ? null
                    : (int) $valori['scorte'],
                'sku' => $valori['sku'] ?? null,
            ]);
        }

        return back()->with('portal_success', 'Varianti aggiornate.');
    }

    public function toggle(Request $request, Listing $listing, ListingVariant $variant): RedirectResponse
    {
        if ($redirect = $this->assertPuoGestire($request, $listing)) {
            return $redirect;
        }

        if ((int) $variant->listing_id !== (int) $listing->id) {
            return back()->with('portal_error', 'Questa combinazione non appartiene a questo prodotto.');
        }

        $variant->update(['is_active' => ! $variant->is_active]);

        return back()->with('portal_success', 'Combinazione aggiornata.');
    }

    public function destroy(Request $request, Listing $listing, ListingVariant $variant): RedirectResponse
    {
        if ($redirect = $this->assertPuoGestire($request, $listing)) {
            return $redirect;
        }

        if ((int) $variant->listing_id !== (int) $listing->id) {
            return back()->with('portal_error', 'Questa combinazione non appartiene a questo prodotto.');
        }

        $variant->delete();

        // Rimasto senza combinazioni, il prodotto torna semplice: altrimenti
        // resterebbe "variabile" e nessuno potrebbe più comprarlo.
        if ($listing->variants()->count() === 0) {
            $listing->forceFill(['has_variants' => false])->save();
        }

        return back()->with('portal_success', 'Combinazione eliminata.');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Il prodotto cartesiano dei gruppi di valori.
     *
     * @param  array<int, \Illuminate\Support\Collection>  $gruppi
     * @return array<int, array<int, ListingAttributeValue>>
     */
    private function prodottoCartesiano(array $gruppi): array
    {
        $risultato = [[]];

        foreach ($gruppi as $gruppo) {
            $nuovo = [];
            foreach ($risultato as $parziale) {
                foreach ($gruppo as $valore) {
                    $nuovo[] = array_merge($parziale, [$valore]);
                }
            }
            $risultato = $nuovo;
        }

        return $risultato;
    }

    private function assertPuoGestire(Request $request, Listing $listing): ?RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return null;
        }

        if ((int) $user->company_id === (int) $listing->company_id && $user->company_id !== null) {
            return null;
        }

        return redirect()
            ->route('portal.shop.show', $listing)
            ->with('portal_error', 'Puoi gestire le varianti solo dei prodotti della tua azienda.');
    }
}
