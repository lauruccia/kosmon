<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ListingController extends Controller
{
    // ── Portale: lista pubblica ───────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        $category = $request->query('category', '');
        $q = trim((string) $request->query('q', ''));

        $listingsQuery = Listing::query()
            ->with('company')
            ->active()
            ->when($category !== '', fn ($query) => $query->inCategory($category))
            ->when($q !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->where('title', 'like', "%{$q}%")
                      ->orWhere('description', 'like', "%{$q}%")
                      ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('featured')
            ->orderByDesc('created_at');

        $listings = $listingsQuery->paginate(12)->withQueryString();
        $featuredListings = Listing::query()->with('company')->active()->featured()->latest()->take(4)->get();

        return view('portal.shop', [
            'pageTitle'       => 'Shop del circuito',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $user,
            'listings'        => $listings,
            'featuredListings' => $featuredListings,
            'categories'      => Listing::CATEGORIES,
            'selectedCategory' => $category,
            'searchQuery'     => $q,
            'activeNav'       => 'shop',
        ]);
    }

    // ── Portale: dettaglio prodotto ───────────────────────────────────────────

    public function show(Request $request, Listing $listing): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($listing->status !== 'active') {
            return redirect()->route('portal.shop')->with('portal_error', 'Questo prodotto non è più disponibile.');
        }

        // Incrementa contatore visite
        $listing->increment('views_count');

        return view('portal.shop-show', [
            'pageTitle'      => $listing->title . ' — Shop KMoney',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'listing'        => $listing->load('company'),
            'related'        => Listing::query()->with('company')->active()
                                    ->inCategory($listing->category)
                                    ->whereKeyNot($listing->id)
                                    ->latest()->take(3)->get(),
            'activeNav'      => 'shop',
        ]);
    }

    // ── Portale: form creazione ───────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if (! $user->canAccessMarketplace()) {
            return redirect()->route('portal.shop')->with('portal_error', 'Non hai i permessi per pubblicare prodotti.');
        }

        if ($currentAccount->isAtCeiling()) {
            return redirect()->route('portal.shop')
                ->with('portal_error', 'Il tuo conto ha raggiunto il tetto massimo: per ora puoi solo acquistare, non vendere. Spendi i tuoi KY nel circuito per sbloccare la vendita.');
        }

        return view('portal.shop-create', [
            'pageTitle'            => 'Pubblica un prodotto',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $user,
            'categories'           => Listing::CATEGORIES,
            'editingListing'       => null,
            'allowedKyPercentages' => $currentAccount->allowedKyPercentages(),
            'requiredKyPercentage' => $currentAccount->requiredKyPercentage(),
            'activeNav'            => 'shop',
        ]);
    }

    // ── Portale: salva nuovo prodotto ─────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canAccessMarketplace()) {
            abort(403);
        }

        $currentAccount = $this->resolveAccount($user);

        if ($currentAccount->isAtCeiling()) {
            return redirect()->route('portal.shop')
                ->with('portal_error', 'Il tuo conto ha raggiunto il tetto massimo: puoi solo acquistare.');
        }

        $validated = $this->validateListing($request, $currentAccount);

        // Genera UUID anticipato per usarlo nel path delle immagini
        $uuid = (string) Str::uuid();
        $imagePaths = $this->storeUploadedImages($request, $uuid);

        Listing::create(array_merge($validated, [
            'uuid'               => $uuid,
            'company_id'         => $user->company_id,
            'created_by_user_id' => $user->id,
            'status'             => 'active',
            'images'             => $imagePaths,
        ]));

        return redirect()->route('portal.shop')->with('portal_success', 'Prodotto pubblicato nello shop del circuito.');
    }

    // ── Portale: form modifica ────────────────────────────────────────────────

    public function edit(Request $request, Listing $listing): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        return view('portal.shop-create', [
            'pageTitle'            => 'Modifica prodotto',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $user,
            'categories'           => Listing::CATEGORIES,
            'editingListing'       => $listing,
            'allowedKyPercentages' => $currentAccount->allowedKyPercentages(),
            'requiredKyPercentage' => $currentAccount->requiredKyPercentage(),
            'activeNav'            => 'shop',
        ]);
    }

    // ── Portale: aggiorna prodotto ────────────────────────────────────────────

    public function update(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        $currentAccount = $this->resolveAccount($user);
        $validated = $this->validateListing($request, $currentAccount);

        // Carica nuove immagini e le aggiunge a quelle esistenti
        $newPaths   = $this->storeUploadedImages($request, $listing->uuid);
        $existing   = $listing->images ?? [];
        $merged     = array_values(array_unique(array_merge($existing, $newPaths)));

        $listing->update(array_merge($validated, ['images' => $merged]));

        return redirect()->route('portal.shop.show', $listing)->with('portal_success', 'Prodotto aggiornato correttamente.');
    }

    // ── Portale: elimina prodotto ─────────────────────────────────────────────

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        $listing->deleteAllImages();
        $listing->delete();

        return redirect()->route('portal.shop')->with('portal_success', 'Prodotto rimosso dallo shop.');
    }

    // ── Portale: elimina singola immagine ─────────────────────────────────────

    public function destroyImage(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        $request->validate(['path' => ['required', 'string']]);
        $path = $request->input('path');

        // Sicurezza: il path deve stare dentro la cartella del listing
        if (! str_starts_with($path, "listings/{$listing->uuid}/")) {
            abort(403, 'Percorso non autorizzato.');
        }

        $listing->deleteImage($path);

        return back()->with('portal_success', 'Immagine eliminata.');
    }

    // ── Admin: lista moderazione ──────────────────────────────────────────────

    public function adminIndex(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $listings = Listing::query()
            ->with(['company', 'createdByUser'])
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%")
                ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%")))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)->withQueryString();

        return view('admin.listings', [
            'pageTitle' => 'Moderazione shop',
            'listings'  => $listings,
            'statuses'  => Listing::STATUSES,
            'activeNav' => 'admin',
        ]);
    }

    // ── Admin: cambia stato ───────────────────────────────────────────────────

    public function adminUpdateStatus(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['status' => ['required', Rule::in(Listing::STATUSES)]]);
        $listing->update(['status' => $request->input('status')]);

        return back()->with('portal_success', 'Stato prodotto aggiornato.');
    }

    // ── Helpers privati ───────────────────────────────────────────────────────

    private function validateListing(Request $request, ?\App\Models\Account $account = null): array
    {
        // Determina le percentuali KY consentite per questo venditore
        $allowedPercentages = $account ? $account->allowedKyPercentages() : Listing::KY_PERCENTAGES;

        // Se il venditore e' in debito la percentuale e' forzata al 100
        $requiredPercentage = $account?->requiredKyPercentage();

        $validated = $request->validate([
            'title'          => ['required', 'string', 'max:160'],
            'description'    => ['required', 'string', 'max:2000'],
            'category'       => ['required', Rule::in(array_keys(Listing::CATEGORIES))],
            'price_ky'       => ['required', 'integer', 'min:1', 'max:9999999'],
            'ky_percentage'  => ['required', 'integer', Rule::in(empty($allowedPercentages) ? Listing::KY_PERCENTAGES : $allowedPercentages)],
            'contact_info'   => ['nullable', 'string', 'max:200'],
            'delivery_note'  => ['nullable', 'string', 'max:120'],
            'expires_at'     => ['nullable', 'date', 'after:today'],
            'featured'       => ['nullable', 'boolean'],
            'images'         => ['nullable', 'array', 'max:6'],
            'images.*'       => ['image', 'mimes:jpeg,png,webp', 'max:3072'], // 3 MB
        ]);

        // Override di sicurezza lato server: se obbligatorio, forza 100%
        if ($requiredPercentage !== null) {
            $validated['ky_percentage'] = $requiredPercentage;
        }

        return $validated;
    }

    /**
     * Salva i file caricati nel disco public e ritorna i path relativi.
     *
     * @return string[]
     */
    private function storeUploadedImages(Request $request, string $uuid): array
    {
        if (! $request->hasFile('images')) {
            return [];
        }

        $paths = [];
        foreach ($request->file('images') as $file) {
            if ($file->isValid()) {
                $path = $file->store("listings/{$uuid}", 'public');
                $paths[] = $path;
            }
        }
        return $paths;
    }

    private function resolveAccount($user): Account
    {
        if ($user->managed_account_id) {
            return Account::query()->with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
        }
        if ($user->company_id) {
            return Account::query()->with(['company'])->where('company_id', $user->company_id)->whereNull('parent_account_id')->firstOrFail();
        }
        return Account::query()->with(['ownerUser'])->where('owner_user_id', $user->id)->whereNull('parent_account_id')->firstOrFail();
    }
}
