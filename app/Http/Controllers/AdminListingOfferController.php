<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin: "Offerte della settimana" (2026-08-13, richiesta di Laura) — un
 * prodotto shop già pubblicato (da azienda o da admin per suo conto) può
 * essere messo in offerta per un periodo limitato, con un prezzo scontato e
 * una percentuale KY propria (in genere 100%). Vedi ListingOffer per come
 * un'offerta "scade" senza bisogno di un job schedulato, e
 * Listing::activeOffer()/effective_* per come si riflette automaticamente
 * ovunque il prodotto è mostrato o acquistato.
 *
 * Solo UNA offerta può essere attiva per prodotto alla volta: crearne una
 * nuova su un prodotto che ne ha già una attiva termina automaticamente
 * quella precedente (resta comunque nello storico, mai cancellata — stesso
 * ragionamento di ListingOffer sul non cancellare mai fisicamente le righe).
 */
class AdminListingOfferController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    public function index(): View
    {
        $offers = ListingOffer::query()
            ->with(['listing.company', 'createdByUser'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $activeCount = ListingOffer::query()->current()->count();

        return view('admin.listing-offers', [
            'pageTitle'   => 'Offerte della settimana',
            'offers'      => $offers,
            'activeCount' => $activeCount,
            'activeNav'   => 'admin-listing-offers',
        ]);
    }

    public function create(): View
    {
        // Solo prodotti attivi possono essere messi in offerta — un prodotto
        // sospeso/bozza/scaduto non è comunque acquistabile, un'offerta sopra
        // non avrebbe senso (stessa regola applicata in store()).
        $listings = Listing::query()
            ->with('company')
            ->where('status', 'active')
            ->orderBy('title')
            ->get(['id', 'title', 'price_ky', 'company_id']);

        return view('admin.listing-offer-create', [
            'pageTitle' => 'Nuova offerta della settimana',
            'listings'  => $listings,
            'activeNav' => 'admin-listing-offers',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'listing_id'          => ['required', 'integer', Rule::exists('listings', 'id')->where('status', 'active')],
            // Come price_ky nel form prodotto: l'admin digita il valore in KY
            // (es. "8,50"), convertito in centesimi sotto con ky_to_cents() —
            // stessa convenzione di tutti gli altri form di importo del
            // progetto (vedi CLAUDE.md "Importi sempre in centesimi").
            // Stessa soglia del prezzo pieno (audit 26/08/2026, 1.5): mettere
            // in offerta a un centesimo un prodotto col mix al 25% lo renderebbe
            // invendibile, e a scoprirlo sarebbe il cliente in cassa.
            'offer_price_ky'      => ['required', 'numeric', 'min:' . number_format(((int) config('kmoney.shop.min_price_ky', 100)) / 100, 2, '.', ''), 'max:99999.99'],
            'offer_ky_percentage' => ['required', 'integer', Rule::in(Listing::KY_PERCENTAGES)],
            'expires_at'          => ['required', 'date', 'after:now'],
        ]);

        $listing = Listing::findOrFail($validated['listing_id']);
        $offerPriceKy = ky_to_cents($validated['offer_price_ky']);

        if ($offerPriceKy >= $listing->price_ky) {
            return back()->withInput()->with('portal_error', 'Il prezzo in offerta deve essere inferiore al prezzo pieno del prodotto (' . ky_format($listing->price_ky) . ' KY).');
        }

        // Stessa regola commerciale di validateListing()/adminCreate(): un'azienda
        // in debito può vendere solo al 100% KY — l'offerta non può aggirarla
        // impostando un mix EUR.
        $account = $listing->company?->primaryBusinessAccount();
        $requiredPercentage = $account?->requiredKyPercentage();
        $kyPercentage = $requiredPercentage ?? $validated['offer_ky_percentage'];

        DB::transaction(function () use ($listing, $offerPriceKy, $kyPercentage, $validated, $request) {
            // Un prodotto ha al massimo un'offerta attiva alla volta: crearne
            // una nuova termina quella precedente (resta comunque come riga
            // storica, mai cancellata — vedi nota di classe).
            $listing->offers()->current()->update(['cancelled_at' => now()]);

            ListingOffer::create([
                'listing_id'             => $listing->id,
                'created_by_user_id'     => $request->user()->id,
                'full_price_ky_snapshot' => $listing->price_ky,
                'offer_price_ky'         => $offerPriceKy,
                'offer_ky_percentage'    => $kyPercentage,
                'expires_at'             => $validated['expires_at'],
            ]);
        });

        return redirect()->route('admin.listing-offers.index')
            ->with('portal_success', 'Offerta creata per "' . $listing->title . '".');
    }

    public function destroy(ListingOffer $listingOffer): RedirectResponse
    {
        if ($listingOffer->is_active) {
            $listingOffer->update(['cancelled_at' => now()]);
        }

        return back()->with('portal_success', 'Offerta terminata.');
    }
}
