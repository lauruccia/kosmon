<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesMovementFilters;
use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use App\Models\Transfer;
use App\Notifications\NewMarketplaceOrderNotification;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ListingController extends Controller
{
    use HandlesMovementFilters;

    // ── Portale: lista pubblica ───────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        $category = $request->query('category', '');
        $q = trim((string) $request->query('q', ''));

        // Filtro % Kmoney nello shop, su Listing::ky_percentage (colonna
        // diretta, niente subquery: qui e' il prodotto stesso, non serve
        // calcolare una percentuale "effettiva" come per Company). Un'unica
        // select nel form (ky_filter="exact:50" / "min:50") invece di due
        // campi separati, su richiesta di Laura (2026-07-29 sera).
        $kyFilter = trim((string) $request->query('ky_filter', ''));
        $exactKy = null;
        $minKy   = null;
        if ($kyFilter !== '') {
            [$kyMode, $kyValue] = array_pad(explode(':', $kyFilter, 2), 2, null);
            if (is_numeric($kyValue)) {
                if ($kyMode === 'exact') {
                    $exactKy = (int) $kyValue;
                } elseif ($kyMode === 'min') {
                    $minKy = (int) $kyValue;
                }
            }
        }

        $listingsQuery = Listing::query()
            ->with('company.plan')
            ->active()
            ->when($category !== '', fn ($query) => $query->inCategory($category))
            ->when($q !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->where('title', 'like', "%{$q}%")
                      ->orWhere('description', 'like', "%{$q}%")
                      ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->when($exactKy !== null, fn ($query) => $query->where('ky_percentage', '=', $exactKy))
            ->when($minKy !== null, fn ($query) => $query->where('ky_percentage', '>=', $minKy))
            ->orderByDesc('featured')
            ->orderByDesc('created_at');

        $listings = $listingsQuery->paginate(12)->withQueryString();
        $featuredListings = Listing::query()->with('company.plan')->active()->featured()->latest()->take(4)->get();

        return view('portal.shop', [
            'pageTitle'       => 'Shop del circuito',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $user,
            'listings'        => $listings,
            'featuredListings' => $featuredListings,
            'categories'      => Listing::CATEGORIES,
            'selectedCategory' => $category,
            'searchQuery'     => $q,
            'kyPercentages'   => Listing::KY_PERCENTAGES,
            'kyFilter'        => $kyFilter,
            'activeNav'       => 'shop',
        ]);
    }

    // ── Portale: dettaglio prodotto ───────────────────────────────────────────

    public function show(Request $request, Listing $listing): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        if ($listing->status !== 'active') {
            return redirect()->route('portal.shop')->with('portal_error', 'Questo prodotto non è più disponibile.');
        }

        // Incrementa contatore visite
        $listing->increment('views_count');

        return view('portal.shop-show', [
            'pageTitle'      => $listing->title . ' — Shop KMoney',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'listing'        => $listing->load('company.plan'),
            'related'        => Listing::query()->with('company.plan')->active()
                                    ->inCategory($listing->category)
                                    ->whereKeyNot($listing->id)
                                    ->latest()->take(3)->get(),
            'activeNav'      => 'shop',
        ]);
    }

    // ── Portale: acquisto diretto di un prodotto ──────────────────────────────

    /**
     * Acquisto strutturato di un prodotto shop: crea un Transfer con
     * kind=portal_marketplace_order collegato al listing (listing_id), scala lo
     * stock se limitato, e notifica il venditore. Sostituisce il precedente
     * link "Paga" che si limitava a precompilare il form di pagamento libero.
     *
     * Viene sempre addebitata la quota KY del prezzo (ky_amount) tramite il
     * circuito. Se il prodotto ha anche una quota EUR (ky_percentage < 100),
     * viene creato un MarketplaceOrderPayment collegato al Transfer e
     * l'acquirente viene mandato a scegliere il metodo di pagamento EUR tra
     * quelli configurati dall'azienda venditrice (Stripe/PayPal/Bonifico —
     * vedi PaymentGateway). Se il venditore non ha nessun metodo attivo
     * configurato, l'acquisto viene bloccato PRIMA di addebitare i KY: non ha
     * senso completare la parte KY se poi non c'è modo di pagare la parte EUR.
     */
    public function buy(Request $request, Listing $listing, TransferBookingService $bookingService): RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        if ($listing->status !== 'active' || $listing->is_expired) {
            return redirect()->route('portal.shop')->with('portal_error', 'Questo prodotto non è più disponibile.');
        }

        if ($listing->company_id === $currentAccount->company_id) {
            return back()->with('portal_error', 'Non puoi acquistare un prodotto pubblicato dalla tua stessa azienda.');
        }

        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ]);
        $quantity = (int) ($validated['quantity'] ?? 1);

        // Se il prodotto ha una quota EUR, il venditore deve avere almeno un
        // metodo di pagamento attivo e configurato: controllo PRIMA di
        // addebitare i KY, per non lasciare l'acquirente con un ordine KY
        // pagato ma senza modo di saldare la quota EUR.
        if ($listing->ky_percentage < 100) {
            $hasUsableGateway = PaymentGateway::query()
                ->where('company_id', $listing->company_id)
                ->active()
                ->get()
                ->contains(fn (PaymentGateway $g) => $g->is_configured);

            if (! $hasUsableGateway) {
                return back()->with('portal_error', 'Questo venditore non ha ancora configurato un metodo di pagamento per la quota in euro: riprova più tardi o contattalo direttamente.');
            }
        }

        // Prodotti "da spedire" (2026-07-29): l'indirizzo si compila una volta
        // sola nel profilo (vedi Account::hasShippingAddress()), non ad ogni
        // acquisto — se manca, blocchiamo PRIMA di addebitare qualsiasi cosa
        // e mandiamo il cliente a completarlo.
        if ($listing->requiresShippingAddress() && ! $currentAccount->hasShippingAddress()) {
            return back()->with('portal_error', 'Questo prodotto va spedito: prima di acquistarlo, completa il tuo indirizzo di spedizione nella sezione "Indirizzo di spedizione" del tuo profilo.');
        }

        try {
            $result = DB::transaction(function () use ($listing, $currentAccount, $user, $quantity, $bookingService, $request) {
                // Lock della riga prodotto per verificare/scalare lo stock in modo atomico.
                $lockedListing = Listing::query()->lockForUpdate()->findOrFail($listing->id);

                if ($lockedListing->status !== 'active') {
                    throw new \RuntimeException('Questo prodotto non è più disponibile.');
                }

                if ($lockedListing->hasLimitedStock() && $lockedListing->stock_quantity < $quantity) {
                    throw new \RuntimeException(
                        $lockedListing->stock_quantity <= 0
                            ? 'Prodotto esaurito.'
                            : "Disponibili solo {$lockedListing->stock_quantity} pezzi."
                    );
                }

                $sellerAccount = $lockedListing->company->accounts()
                    ->where('is_system_account', false)
                    ->where('owner_type', 'company')
                    ->whereNull('parent_account_id')
                    ->firstOrFail();

                $unitKyAmount = $lockedListing->ky_amount;
                $unitEuroAmount = $lockedListing->euro_amount;

                // Costo di spedizione: UNA sola volta per ordine (non moltiplicato
                // per la quantità, coerente con un ordine reale spedito in un unico
                // pacco), diviso KY/EUR con la STESSA percentuale del prodotto
                // (scelta di Laura, 2026-07-29).
                $requiresShipping = $lockedListing->requiresShippingAddress();
                $shippingKyAmount = $requiresShipping ? $lockedListing->shipping_ky_amount : 0;
                $shippingEuroAmount = $requiresShipping ? $lockedListing->shipping_euro_amount : 0;

                $totalAmount     = ($unitKyAmount * $quantity) + $shippingKyAmount;
                $totalEuroAmount = ($unitEuroAmount * $quantity) + $shippingEuroAmount;

                $description = 'Acquisto shop: ' . $lockedListing->title . ($quantity > 1 ? " (x{$quantity})" : '')
                    . ($shippingKyAmount > 0 || $shippingEuroAmount > 0 ? ' + spedizione' : '');

                $transfer = $bookingService->book([
                    'initiated_by'    => $user->id,
                    'from_account_id' => $currentAccount->id,
                    'to_account_id'   => $sellerAccount->id,
                    'amount'          => $totalAmount,
                    'kind'            => 'portal_marketplace_order',
                    'description'     => $description,
                    'listing_id'      => $lockedListing->id,
                    'quantity'        => $quantity,
                    // Snapshot indirizzo al momento dell'acquisto: se il cliente
                    // cambia poi l'indirizzo sul profilo, l'ordine già fatto resta
                    // storicamente corretto (stesso ragionamento del prezzo).
                    'shipping_recipient_name' => $requiresShipping ? $currentAccount->shipping_recipient_name : null,
                    'shipping_address'        => $requiresShipping ? $currentAccount->shipping_address : null,
                    'shipping_city'           => $requiresShipping ? $currentAccount->shipping_city : null,
                    'shipping_postal_code'    => $requiresShipping ? $currentAccount->shipping_postal_code : null,
                    'shipping_province'       => $requiresShipping ? $currentAccount->shipping_province : null,
                    'shipping_phone'          => $requiresShipping ? $currentAccount->shipping_phone : null,
                    'shipping_ky_amount'      => $requiresShipping ? $shippingKyAmount : null,
                    'idempotency_key' => (string) Str::uuid(),
                    'ip_address'      => $request->ip(),
                ]);

                if ($lockedListing->hasLimitedStock()) {
                    $lockedListing->decrement('stock_quantity', $quantity);
                }

                $payment = null;
                if ($totalEuroAmount > 0) {
                    $payment = MarketplaceOrderPayment::create([
                        'transfer_id' => $transfer->id,
                        'listing_id'  => $lockedListing->id,
                        'company_id'  => $lockedListing->company_id,
                        'amount'      => $totalEuroAmount,
                        'status'      => MarketplaceOrderPayment::STATUS_PENDING,
                    ]);
                }

                return [$transfer, $payment];
            });
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        [$transfer, $payment] = $result;

        // La transazione è già committata a questo punto: notifica il venditore
        // fuori dalla transazione, senza far fallire l'acquisto se la notifica ha problemi.
        $sellerOwner = $listing->company->primaryBusinessAccount()?->ownerUser;
        if ($sellerOwner) {
            try {
                $sellerOwner->notify(new NewMarketplaceOrderNotification($transfer, $listing->title, $quantity));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('marketplace_order.notify_failed', [
                    'transfer_id' => $transfer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if ($payment) {
            return redirect()->route('portal.shop.orders.pay', $payment)
                ->with('portal_success', ky_format((int) $transfer->amount) . ' KY pagati a ' . $listing->company->name . '. Ora completa il pagamento della quota rimanente in euro.');
        }

        return redirect()->route('portal.shop.show', $listing)
            ->with('portal_success', 'Acquisto completato: ' . ky_format((int) $transfer->amount) . ' KY pagati a ' . $listing->company->name . '.');
    }

    // ── Portale: form creazione ───────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        if (! $user->canAccessMarketplace()) {
            return redirect()->route('portal.shop')->with('portal_error', 'Non hai i permessi per pubblicare prodotti.');
        }

        // 2026-07-29: la pubblicazione prodotti non dipende piu' dal piano
        // Ecommerce, ma dalla presenza in directory (status attivo + KYC
        // approvato) — tutte le aziende in directory possono inserire i
        // loro prodotti, vedi Company::isInDirectory().
        if (! $user->company?->isInDirectory()) {
            return redirect()->route('portal.shop')
                ->with('portal_error', 'Per pubblicare prodotti nello shop del circuito la tua azienda deve essere presente nella directory (attiva e con KYC approvato). Contatta l\'amministrazione se pensi sia un errore.');
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

        if (! $user->company?->isInDirectory()) {
            abort(403);
        }

        $currentAccount = $this->resolveAccount($user);

        if (! $currentAccount) {
            abort(403);
        }

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
        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        // Stesso ragionamento di update(): un admin che apre il form di modifica
        // del prodotto di un'altra azienda non ha un conto proprio da usare per
        // calcolare le percentuali KY consentite (spesso non ha nemmeno
        // un'azienda associata, causa del 404 riprodotto il 24/07 su
        // /shop/{id}/modifica) — usiamo quello dell'azienda proprietaria del
        // prodotto, stesso conto usato in update()/adminStore().
        $currentAccount = ($user->is_super_admin && $listing->company_id !== $user->company_id)
            ? $listing->company->primaryBusinessAccount()
            : $this->resolveAccount($user);

        if (! $user->is_super_admin && ($redirect = $this->redirectIfNoAccount($currentAccount, $user))) {
            return $redirect;
        }

        return view('portal.shop-create', [
            'pageTitle'            => 'Modifica prodotto',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $user,
            'categories'           => Listing::CATEGORIES,
            'editingListing'       => $listing,
            'allowedKyPercentages' => $currentAccount?->allowedKyPercentages() ?? Listing::KY_PERCENTAGES,
            'requiredKyPercentage' => $currentAccount?->requiredKyPercentage(),
            'activeNav'            => 'shop',
        ]);
    }

    // ── Portale: aggiorna prodotto ────────────────────────────────────────────

    public function update(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $listing->company_id === $user->company_id, 403);

        // Un admin che modifica il prodotto di un'altra azienda non ha un conto
        // proprio da usare per calcolare le percentuali KY consentite (spesso
        // non ha nemmeno un'azienda associata): usiamo quello dell'azienda
        // proprietaria del prodotto, stesso conto usato in adminStore().
        $currentAccount = ($user->is_super_admin && $listing->company_id !== $user->company_id)
            ? $listing->company->primaryBusinessAccount()
            : $this->resolveAccount($user);
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

        $stats = [
            'total'     => (clone $this->baseListingStatsQuery())->count(),
            'active'    => (clone $this->baseListingStatsQuery())->where('status', 'active')->count(),
            'draft'     => (clone $this->baseListingStatsQuery())->where('status', 'draft')->count(),
            'suspended' => (clone $this->baseListingStatsQuery())->where('status', 'suspended')->count(),
        ];

        return view('admin.listings', [
            'pageTitle' => 'Moderazione shop',
            'listings'  => $listings,
            'statuses'  => Listing::STATUSES,
            'stats'     => $stats,
            'search'    => $q,
            'statusFilter' => $status,
            'activeNav' => 'admin-listings',
        ]);
    }

    // ── Admin: form creazione prodotto per conto di un'azienda ─────────────────

    public function adminCreate(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $companies = Company::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.listing-create', [
            'pageTitle'  => 'Nuovo prodotto per conto azienda',
            'companies'  => $companies,
            'categories' => Listing::CATEGORIES,
            'activeNav'  => 'admin-listings',
        ]);
    }

    // ── Admin: salva il prodotto assegnandolo all'azienda scelta ───────────────

    public function adminStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $company = Company::findOrFail($request->input('company_id'));
        $account = $company->primaryBusinessAccount();

        if (! $account) {
            return back()->withInput()->with('portal_error', 'Questa azienda non ha un conto business principale: impossibile assegnarle un prodotto.');
        }

        // Stesse regole KY dell'azienda selezionata (es. saldo negativo → solo
        // 100% KY, saldo al tetto massimo → vendita bloccata): l'admin pubblica
        // "per conto" dell'azienda, non bypassa le sue regole commerciali.
        $validated = $this->validateListing($request, $account);

        $uuid = (string) Str::uuid();
        $imagePaths = $this->storeUploadedImages($request, $uuid);

        Listing::create(array_merge($validated, [
            'uuid'               => $uuid,
            'company_id'         => $company->id,
            'created_by_user_id' => $request->user()->id,
            'status'             => 'active',
            'images'             => $imagePaths,
        ]));

        return redirect()->route('admin.listings.index')->with('portal_success', 'Prodotto pubblicato per conto di ' . $company->name . '.');
    }

    // ── Admin: cambia stato ───────────────────────────────────────────────────

    public function adminUpdateStatus(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['status' => ['required', Rule::in(Listing::STATUSES)]]);
        $listing->update(['status' => $request->input('status')]);

        return back()->with('portal_success', 'Stato prodotto aggiornato.');
    }

    // ── Admin: elenco ordini shop (tutti i Transfer kind=portal_marketplace_order) ──

    public function adminOrders(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $relations = [
            'listing',
            'fromAccount.company',
            'fromAccount.ownerUser',
            'toAccount.company',
            'toAccount.ownerUser',
            'marketplaceOrderPayment',
        ];

        if ($this->supportsTransferRefunds()) {
            $relations[] = 'reversalChildren';
            $relations[] = 'reversedTransfer';
        }

        $baseQuery = Transfer::query()
            ->where('kind', 'portal_marketplace_order')
            ->when($q !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->whereHas('listing', fn ($l) => $l->where('title', 'like', "%{$q}%"))
                      ->orWhereHas('fromAccount.ownerUser', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                      ->orWhereHas('fromAccount.company', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                      ->orWhereHas('toAccount.company', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status));

        // Nota: a differenza di movementTotals() (pensato per la vista generale
        // movimenti), qui "stornato" va contato guardando i reversalChildren
        // dell'ordine stesso: il Transfer di storno ha kind=admin_refund, quindi
        // non compare mai dentro $baseQuery (filtrato su kind=portal_marketplace_order).
        $orderTotals = [
            'count'       => (clone $baseQuery)->count(),
            'bookedCount' => (clone $baseQuery)->where('status', 'booked')->count(),
            'volume'      => (clone $baseQuery)->where('status', 'booked')->sum('amount'),
            'refunded'    => $this->supportsTransferRefunds()
                ? (clone $baseQuery)->whereHas('reversalChildren')->count()
                : 0,
        ];

        $orders = (clone $baseQuery)
            ->with($relations)
            ->latest('booked_at')
            ->latest('id')
            ->paginate(20)->withQueryString();

        return view('admin.listing-orders', [
            'pageTitle'              => 'Ordini shop',
            'orders'                 => $orders,
            'orderTotals'            => $orderTotals,
            'search'                 => $q,
            'statusFilter'           => $status,
            'refundWindowDays'       => self::ORDER_REFUND_WINDOW_DAYS,
            'supportsTransferRefunds' => $this->supportsTransferRefunds(),
            'activeNav'              => 'admin-listing-orders',
        ]);
    }

    // ── Helpers privati ───────────────────────────────────────────────────────

    /**
     * Finestra (in giorni) entro cui uno storno resta disponibile da questa vista.
     * Solo indicativa/di visualizzazione: l'enforcement reale è lato server in
     * AdminController::refundTransfer() (REFUND_WINDOW_DAYS, stesso valore).
     */
    private const ORDER_REFUND_WINDOW_DAYS = 30;

    private function baseListingStatsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Listing::query();
    }

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
            // Il venditore digita il prezzo in KY (es. "10" o "10,50"), non in
            // centesimi: 'numeric' valida la stringa decimale così com'è
            // digitata; la conversione in centesimi avviene subito sotto con
            // ky_to_cents(), stessa convenzione di tutti gli altri form di
            // importo del progetto (vedi CLAUDE.md "Importi sempre in
            // centesimi"). PRIMA questo campo era validato come 'integer' e
            // salvato COSÌ COM'ERA in price_ky: un prodotto a "5 KY" finiva
            // salvato come 5 centesimi (0,05 KY) — bug segnalato da Laura il
            // 24/07 ("ho caricato un prodotto a 5ky, i clienti lo vedono a 0,05").
            'price_ky'       => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'ky_percentage'  => ['required', 'integer', Rule::in(empty($allowedPercentages) ? Listing::KY_PERCENTAGES : $allowedPercentages)],
            'stock_mode'     => ['required', Rule::in(['unlimited', 'limited'])],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:999999', 'required_if:stock_mode,limited'],
            'contact_info'   => ['nullable', 'string', 'max:200'],
            'delivery_note'  => ['nullable', 'string', 'max:120'],
            // Tipo di consegna/erogazione (2026-07-29): solo "spedizione" ammette
            // un costo di spedizione facoltativo, validato in KY come price_ky
            // (stessa convenzione, convertito sotto in centesimi con ky_to_cents()).
            'delivery_type'  => ['required', Rule::in(array_keys(Listing::DELIVERY_TYPES))],
            'shipping_cost'  => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'expires_at'     => ['nullable', 'date', 'after:today'],
            'featured'       => ['nullable', 'boolean'],
            'images'         => ['nullable', 'array', 'max:6'],
            'images.*'       => ['image', 'mimes:jpeg,png,webp', 'max:3072'], // 3 MB
        ]);

        // Converte il prezzo digitato dall'utente (KY, es. "10,50") nei
        // centesimi interi memorizzati in price_ky.
        $validated['price_ky'] = ky_to_cents($validated['price_ky']);

        // Il costo di spedizione ha senso solo per i prodotti "da spedire":
        // per ritiro/servizio lo ignoriamo sempre (anche se il form lo ha
        // eventualmente inviato, es. cambio tipo senza svuotare il campo).
        $validated['shipping_cost'] = ($validated['delivery_type'] === Listing::DELIVERY_TYPE_SPEDIZIONE && $validated['shipping_cost'] !== null && $validated['shipping_cost'] !== '')
            ? ky_to_cents($validated['shipping_cost'])
            : null;

        // Override di sicurezza lato server: se obbligatorio, forza 100%
        if ($requiredPercentage !== null) {
            $validated['ky_percentage'] = $requiredPercentage;
        }

        // stock_mode e' solo un campo di UI: non e' una colonna di Listing.
        // NULL = illimitato, altrimenti la quantita' dichiarata.
        $validated['stock_quantity'] = $validated['stock_mode'] === 'limited'
            ? (int) $validated['stock_quantity']
            : null;
        unset($validated['stock_mode']);

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

    /**
     * NB: torna null (non lancia più ModelNotFoundException) quando l'utente
     * non ha nessun Account risolvibile — vedi redirectIfNoAccount().
     */
    private function resolveAccount($user): ?Account
    {
        if ($user->managed_account_id) {
            return Account::query()->with(['company', 'ownerUser'])->find($user->managed_account_id);
        }
        if ($user->company_id) {
            return Account::query()->with(['company'])->where('company_id', $user->company_id)->whereNull('parent_account_id')->first();
        }
        return Account::query()->with(['ownerUser'])->where('owner_user_id', $user->id)->whereNull('parent_account_id')->first();
    }

    /**
     * Le pagine shop rivolte al cliente richiedono un Account risolvibile
     * (personale, aziendale o gestito). Un operatore di puro backoffice
     * (staff senza azienda/conto proprio, es. profilo "Sala controllo") non
     * ne ha uno: prima resolveAccount() andava in 404 (ModelNotFoundException
     * da firstOrFail/findOrFail) invece di gestire il caso — bug riprodotto
     * il 24/07 su /shop, /shop/crea, /shop/{id}, /shop/{id}/modifica.
     *
     * Guardiamo solo se l'account esiste davvero (non canAccessBackoffice()):
     * un profilo Privato con is_super_admin ma con un conto proprio (com'è
     * il caso reale in produzione) deve continuare a vedere lo shop cliente
     * normalmente, non essere rimandato al backoffice.
     */
    private function redirectIfNoAccount(?Account $currentAccount, $user): ?RedirectResponse
    {
        if ($currentAccount !== null) {
            return null;
        }

        return $user->canAccessBackoffice()
            ? redirect()->route('admin.listings.index')->with('portal_error', 'Il tuo profilo di backoffice non ha un conto associato al circuito: gestisci lo shop da qui.')
            : redirect()->route('portal.dashboard')->with('portal_error', 'Impossibile determinare il tuo conto.');
    }
}
