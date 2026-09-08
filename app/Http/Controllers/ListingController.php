<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesMovementFilters;
use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingCategory;
use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use App\Models\Transfer;
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
        $subcategory = $request->query('subcategory', '');
        $q = trim((string) $request->query('q', ''));

        // Filtro per venditore (2026-08-25, richiesta di Laura): i pulsanti
        // "SHOP" della directory aziende e del profilo azienda linkavano gia'
        // /shop?company={id} (companies.blade.php, company-show.blade.php) ma
        // il parametro veniva IGNORATO qui — si finiva sul catalogo intero del
        // circuito invece che sui prodotti di quell'azienda. Ora la griglia
        // mostra i soli prodotti del venditore scelto, con un banner per
        // tornare al catalogo completo.
        $companyId = (int) $request->query('company', 0);
        $selectedCompany = $companyId > 0
            ? Company::query()->select(['id', 'name', 'slug'])->find($companyId)
            : null;

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

        // Un prodotto sospeso dalla propria azienda (o da admin) resta visibile
        // SOLO al proprietario (stesso company_id) e agli admin che navigano lo
        // shop col proprio account super_admin — per chiunque altro lo scope
        // active() lo esclude come prima. Cosi' chi ha messo in pausa un
        // prodotto lo ritrova nella stessa griglia per poterlo riattivare,
        // invece di doverlo cercare in un'altra pagina (2026-07-30).
        $ownCompanyId = $user->company_id;

        $listingsQuery = Listing::query()
            ->with(['company.plan', 'activeOffer'])
            ->where(function ($query) use ($ownCompanyId) {
                $query->active();
                if ($ownCompanyId) {
                    $query->orWhere('company_id', $ownCompanyId);
                }
            })
            ->when($selectedCompany !== null, fn ($query) => $query->where('company_id', $selectedCompany->id))
            ->when($category !== '', fn ($query) => $query->inCategory($category))
            ->when($subcategory !== '', fn ($query) => $query->inSubcategory($subcategory))
            ->when($q !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->where('title', 'like', "%{$q}%")
                      ->orWhere('description', 'like', "%{$q}%")
                      ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->when($exactKy !== null, fn ($query) => $query->where('ky_percentage', '=', $exactKy))
            ->when($minKy !== null, fn ($query) => $query->where('ky_percentage', '>=', $minKy))
            ->orderByDesc('featured')
            ->orderByDesc('created_at');

        // 20 e non piu' 15 (08/09/2026). Il 15 nacque il 12/08 perche' la
        // griglia era a 5 colonne fisse. Da oggi le colonne le conta il
        // catalogo sullo spazio che ha davvero (auto-fill), e con il menu che
        // si stringe e i filtri che si aprono lo stesso schermo da 1440 offre
        // 6, 5 o 4 colonne: 15 lascia la riga spaiata in due casi su tre.
        // 20 si divide per 5, 4 e 2 — i casi che capitano su desktop.
        $listings = $listingsQuery->paginate(20)->withQueryString();
        // Con il filtro venditore attivo la fascia "in primo piano" (che pesca
        // da TUTTO il circuito) contraddirebbe la pagina: si sta guardando un
        // solo negozio. Niente query inutile: collection vuota.
        $featuredListings = $selectedCompany !== null
            ? collect()
            : Listing::query()->with(['company.plan', 'activeOffer'])->active()->featured()->latest()->take(4)->get();

        return view('portal.shop', [
            'pageTitle'       => $selectedCompany !== null ? 'Shop di '.$selectedCompany->name : 'Shop del circuito',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $user,
            'listings'        => $listings,
            'featuredListings' => $featuredListings,
            'categories'      => ListingCategory::activeTopLevelOptions(),
            'subcategoriesBySlug' => ListingCategory::activeSubcategoriesBySlug(),
            'selectedCategory' => $category,
            'selectedSubcategory' => $subcategory,
            'searchQuery'     => $q,
            'kyPercentages'   => Listing::KY_PERCENTAGES,
            'kyFilter'        => $kyFilter,
            'selectedCompany' => $selectedCompany,
            'activeNav'       => 'shop',
        ]);
    }

    // ── Portale: "I miei prodotti" (solo la propria azienda, tutti gli stati) ──

    /**
     * Elenco completo dei prodotti pubblicati dalla PROPRIA azienda, in
     * qualunque stato (attivo/sospeso/bozza/scaduto) — a differenza di
     * index() (shop pubblico, dove i prodotti propri sono mescolati a quelli
     * di tutte le altre aziende ed e' facile perderli tra le pagine), qui
     * non compaiono MAI prodotti di altre aziende: serve a chi pubblica
     * prodotti per ritrovarli e verificarli senza scorrere l'intero
     * catalogo del circuito (richiesta di Laura, 2026-08-12).
     */
    public function mine(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        if (! $user->company_id) {
            return redirect()->route('portal.shop')
                ->with('portal_error', '"I miei prodotti" è disponibile solo per utenti collegati a un\'azienda.');
        }

        $status = trim((string) $request->query('status', ''));
        $q = trim((string) $request->query('q', ''));

        $listings = Listing::query()
            ->where('company_id', $user->company_id)
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(15)->withQueryString();

        return view('portal.shop-mine', [
            'pageTitle'      => 'I miei prodotti',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'listings'       => $listings,
            'statuses'       => Listing::STATUSES,
            'statusFilter'   => $status,
            'searchQuery'    => $q,
            'activeNav'      => 'shop',
        ]);
    }

    // ── Portale: "Offerte della settimana" ────────────────────────────────────

    /**
     * Vetrina pubblica dei prodotti con un'offerta attualmente in corso
     * (2026-08-13, richiesta di Laura) — vedi Listing::scopeOnOffer()/
     * activeOffer() e ListingOffer per come un'offerta "scade" da sola,
     * senza bisogno di alcun job schedulato. Elenco tipicamente piccolo
     * (curato a mano dall'admin settimana per settimana), quindi niente
     * paginazione: ordinati per scadenza più vicina prima, stile
     * e-commerce "finisce tra poco".
     */
    public function offers(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        // Ordinamento e paginazione nel DATABASE, non in PHP (audit 26/08,
        // punto 4.3). Prima si caricavano TUTTE le offerte attive in memoria
        // e si ordinavano dopo: una pagina che cresce senza limite, e che il
        // giorno in cui le offerte diventassero cinquecento si porterebbe
        // dietro cinquecento prodotti con azienda e piano per mostrarne
        // quindici.
        //
        // La sotto-select pesca la scadenza dell'offerta viva di ogni
        // prodotto: e' esattamente la strada che l'indice
        // (listing_id, cancelled_at, expires_at) di `listing_offers` sa
        // percorrere.
        $listings = Listing::query()
            ->with(['company.plan', 'activeOffer'])
            ->active()
            ->onOffer()
            ->addSelect(['scadenza_offerta' => ListingOffer::query()
                ->select('expires_at')
                ->whereColumn('listing_id', 'listings.id')
                ->whereNull('cancelled_at')
                ->where('expires_at', '>', now())
                ->orderBy('expires_at')
                ->limit(1),
            ])
            ->orderBy('scadenza_offerta')
            ->paginate(15)
            ->withQueryString();

        return view('portal.shop-offers', [
            'pageTitle'      => 'Offerte della settimana — Shop KMoney',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'listings'       => $listings,
            'activeNav'      => 'shop-offers',
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

        // Il contatore visite si aggiorna DOPO aver risposto (audit 26/08,
        // punto 4.3). Era una UPDATE sincrona dentro la richiesta: su un
        // prodotto molto visto le richieste si mettevano in fila sul lock di
        // quella riga, e chi guardava la pagina aspettava il proprio turno
        // per far salire un numero che non gli interessa.
        //
        // `afterResponse` non toglie la scrittura, la sposta fuori
        // dall'attesa: la pagina parte, poi si conta. E se fallisse, si
        // perderebbe una visita — che e' il genere giusto di cosa da perdere.
        $daContare = $listing->id;

        dispatch(function () use ($daContare) {
            Listing::query()->whereKey($daContare)->increment('views_count');
        })->afterResponse();

        return view('portal.shop-show', [
            'pageTitle'      => $listing->title . ' — Shop KMoney',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'listing'        => $listing->load([
                'company.plan',
                'activeOffer',
                // Le combinazioni acquistabili, coi loro valori (fase D).
                'variantiAttive.values.attribute',
            ]),
            // ALTRI PRODOTTI DELLO STESSO VENDITORE (08/09/2026, richiesta
            // di Laura). Fino a ieri erano prodotti della stessa CATEGORIA
            // presi da tutto il circuito: la fascia in fondo alla scheda
            // mostrava al compratore tre concorrenti del venditore che lo
            // aveva portato li'. Chi pubblica nello shop si trovava la
            // vetrina dei rivali stampata sotto il proprio prodotto.
            //
            // Adesso la fascia resta dentro il negozio: `company_id` uguale,
            // e la stessa categoria ordinata PRIMA (chi guarda un trapano
            // vuole vedere gli altri utensili, non le magliette) — non
            // filtrata, pero': un venditore con quattro categorie e un
            // prodotto per categoria avrebbe la fascia vuota proprio quando
            // avrebbe piu' da mostrare.
            //
            // Nessun ripiego sul catalogo generale quando il venditore e' solo
            // con quel prodotto: la sezione sparisce e basta (la view la
            // stampa solo se `$related` non e' vuota). Ripiegare sugli altri
            // venditori rimetterebbe in pagina esattamente cio' che questa
            // modifica toglie.
            //
            // `active()` resta: un prodotto sospeso non si mostra nemmeno tra
            // i "suoi", perche' la sua pagina non e' raggiungibile (show() la
            // blocca) e il link porterebbe a un redirect.
            'related'        => Listing::query()->with(['company.plan', 'activeOffer'])->active()
                                    ->where('company_id', $listing->company_id)
                                    ->whereKeyNot($listing->id)
                                    ->orderByRaw('CASE WHEN category = ? THEN 0 ELSE 1 END', [$listing->category])
                                    ->latest()->take(4)->get(),
            'activeNav'      => 'shop',
        ]);
    }

    // ── Portale: acquisto diretto di un prodotto ──────────────────────────────

    // `buy()` viveva qui fino al 26/08/2026. E' stato spostato in
    // CartController (`buyNow`/`buyNowForm`) quando "Compra ora" e' entrato
    // nella cassa vera: teneva una seconda copia della strada verso i soldi,
    // senza spunta sulle condizioni ne' scelta dell'indirizzo, e le due copie
    // erano gia' divergenti. Vedi AUDIT_ECOMMERCE_2026-08-26.md, blocco 3.

    // ── Portale: form creazione ───────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($currentAccount, $user)) {
            return $redirect;
        }

        if (! $user->canSellInMarketplace()) {
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
            'categories'           => ListingCategory::activeTopLevelOptions(),
            'subcategoriesBySlug'  => ListingCategory::activeSubcategoriesBySlug(),
            'editingListing'       => null,
            'allowedKyPercentages' => $currentAccount->allowedKyPercentages(),
            'requiredKyPercentage' => $currentAccount->requiredKyPercentage(),
            // In creazione l'azienda non si sceglie: il prodotto nasce sotto
            // quella di chi lo pubblica (per conto di un'altra azienda esiste
            // adminCreate()). Il selettore compare solo in modifica, e solo al
            // backoffice — vedi edit().
            'companies'            => null,
            'companyKyRules'       => null,
            'activeNav'            => 'shop',
        ]);
    }

    // ── Portale: salva nuovo prodotto ─────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canSellInMarketplace()) {
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
        abort_unless($user->canAccessBackoffice() || $listing->company_id === $user->company_id, 403);

        // Stesso ragionamento di update(): un admin/backoffice che apre il form
        // di modifica del prodotto di un'altra azienda non ha un conto proprio
        // da usare per calcolare le percentuali KY consentite (spesso non ha
        // nemmeno un'azienda associata, causa del 404 riprodotto il 24/07 su
        // /shop/{id}/modifica) — usiamo quello dell'azienda proprietaria del
        // prodotto, stesso conto usato in update()/adminStore(). Nota 2026-08-12:
        // esteso da is_super_admin a canAccessBackoffice() perché l'Account di
        // Sistema (permesso backoffice.access, non super admin) può caricare
        // prodotti per conto azienda ma prendeva 403 in modifica.
        $currentAccount = ($user->canAccessBackoffice() && $listing->company_id !== $user->company_id)
            ? $listing->company->primaryBusinessAccount()
            : $this->resolveAccount($user);

        if (! $user->canAccessBackoffice() && ($redirect = $this->redirectIfNoAccount($currentAccount, $user))) {
            return $redirect;
        }

        [$editCategories, $editSubcategoriesBySlug] = $this->categoryFormOptions($listing);

        // RIASSEGNARE IL PRODOTTO A UN'ALTRA AZIENDA (08/09/2026, segnalato
        // dai colleghi di Laura). Il selettore azienda esisteva solo in
        // "Nuovo prodotto per conto azienda" (adminCreate): una volta salvato,
        // il prodotto restava sotto quell'azienda per sempre, e uno caricato
        // sotto l'azienda sbagliata si poteva solo cancellare e rifare — con
        // le foto da ricaricare.
        //
        // Lo vede SOLO chi ha accesso al backoffice: il prodotto e' la strada
        // per cui i soldi di un ordine arrivano su un conto, e un venditore
        // che potesse spostare i propri prodotti sposterebbe gli incassi su
        // un'azienda non sua.
        $puoRiassegnare = $user->canAccessBackoffice();
        [$companies, $companyKyRules] = $puoRiassegnare
            ? $this->companiesWithKyRules()
            : [null, null];

        return view('portal.shop-create', [
            'pageTitle'            => 'Modifica prodotto',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $user,
            'categories'           => $editCategories,
            'subcategoriesBySlug'  => $editSubcategoriesBySlug,
            'editingListing'       => $listing,
            // Col selettore in pagina le percentuali ammesse dipendono
            // dall'azienda SCELTA, non da quella attuale: si stampano tutte e
            // le blocca il JS sul 100% quando l'azienda scelta e' in debito
            // (companyKyRules), come in admin/listing-create.blade.php. Il
            // server ricontrolla comunque col conto dell'azienda di
            // destinazione, vedi update().
            'allowedKyPercentages' => $puoRiassegnare
                ? Listing::KY_PERCENTAGES
                : ($currentAccount?->allowedKyPercentages() ?? Listing::KY_PERCENTAGES),
            'requiredKyPercentage' => $puoRiassegnare ? null : $currentAccount?->requiredKyPercentage(),
            'companies'            => $companies,
            'companyKyRules'       => $companyKyRules,
            'activeNav'            => 'shop',
        ]);
    }

    // ── Portale: aggiorna prodotto ────────────────────────────────────────────

    public function update(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBackoffice() || $listing->company_id === $user->company_id, 403);

        // L'AZIENDA DI DESTINAZIONE (08/09/2026). Il form di modifica mostra il
        // selettore azienda solo al backoffice (vedi edit()): qui si accetta
        // company_id solo da chi lo vede davvero, altrimenti un venditore
        // potrebbe spostarsi un prodotto — e i suoi incassi — su un'altra
        // azienda con una richiesta scritta a mano.
        $nuovaAzienda = null;

        if ($user->canAccessBackoffice() && $request->filled('company_id')) {
            $request->validate([
                'company_id' => ['required', 'integer', 'exists:companies,id'],
            ]);

            $scelta = Company::findOrFail((int) $request->input('company_id'));

            if ((int) $scelta->id !== (int) $listing->company_id) {
                // Senza conto business principale l'azienda non puo' incassare:
                // stesso controllo di adminStore(), qui e' anche piu' grave
                // perche' il prodotto e' gia' in vetrina e comprabile.
                if (! $scelta->primaryBusinessAccount()) {
                    return back()->withInput()->with('portal_error', 'Questa azienda non ha un conto business principale: impossibile assegnarle il prodotto.');
                }

                $nuovaAzienda = $scelta;
            }
        }

        // Un admin/backoffice che modifica il prodotto di un'altra azienda non
        // ha un conto proprio da usare per calcolare le percentuali KY
        // consentite (spesso non ha nemmeno un'azienda associata): usiamo
        // quello dell'azienda proprietaria del prodotto, stesso conto usato in
        // adminStore(). Se il prodotto sta cambiando padrone, le regole sono
        // quelle dell'azienda che se lo prende: il mix KY/EUR lo deve reggere
        // il conto che incassera'.
        $currentAccount = $nuovaAzienda
            ? $nuovaAzienda->primaryBusinessAccount()
            : (($user->canAccessBackoffice() && $listing->company_id !== $user->company_id)
                ? $listing->company->primaryBusinessAccount()
                : $this->resolveAccount($user));
        $validated = $this->validateListing($request, $currentAccount, $listing);

        // Carica nuove immagini e le aggiunge a quelle esistenti
        $newPaths   = $this->storeUploadedImages($request, $listing->uuid);
        $existing   = $listing->images ?? [];
        $merged     = array_values(array_unique(array_merge($existing, $newPaths)));

        $daSalvare = array_merge($validated, ['images' => $merged]);

        if ($nuovaAzienda) {
            $daSalvare['company_id'] = $nuovaAzienda->id;
        }

        $listing->update($daSalvare);

        $messaggio = $nuovaAzienda
            ? 'Prodotto aggiornato e assegnato a ' . $nuovaAzienda->name . '.'
            : 'Prodotto aggiornato correttamente.';

        // "SALVA E GESTISCI VARIANTI" (08/09/2026, segnalato dai colleghi di
        // Laura). Il pulsante varianti nel form di modifica era un semplice
        // link: chi aveva appena cambiato il titolo se lo perdeva per strada,
        // e tornava a un prodotto con le combinazioni nuove e il titolo
        // vecchio. Adesso e' un submit di QUESTO form — si salva prima, si va
        // alle varianti dopo — e `ritorno=modifica` fa si' che il link in cima
        // alla pagina varianti riporti al form invece che alla scheda.
        if ($request->input('dopo_salvataggio') === 'varianti') {
            return redirect()->route('portal.shop.variants', [$listing, 'ritorno' => 'modifica'])
                ->with('portal_success', $messaggio);
        }

        // La scheda prodotto rimbalza alla home dello shop quando il prodotto
        // non e' 'active' (vedi show()): chi salva le modifiche di un prodotto
        // sospeso si ritrovava fuori, con un "Questo prodotto non e' piu'
        // disponibile" al posto della conferma. In quel caso si resta sul
        // form, che e' anche il posto da cui si riattiva.
        if ($listing->status !== 'active') {
            return redirect()->route('portal.shop.edit', $listing)->with('portal_success', $messaggio);
        }

        return redirect()->route('portal.shop.show', $listing)->with('portal_success', $messaggio);
    }

    // ── Portale: elimina prodotto ─────────────────────────────────────────────

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBackoffice() || $listing->company_id === $user->company_id, 403);

        $listing->deleteAllImages();
        $listing->delete();

        return redirect()->route('portal.shop')->with('portal_success', 'Prodotto rimosso dallo shop.');
    }

    // ── Portale: elimina singola immagine ─────────────────────────────────────

    public function destroyImage(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBackoffice() || $listing->company_id === $user->company_id, 403);

        $request->validate(['path' => ['required', 'string']]);
        $path = $request->input('path');

        // Sicurezza: il path deve stare dentro la cartella del listing
        if (! str_starts_with($path, "listings/{$listing->uuid}/")) {
            abort(403, 'Percorso non autorizzato.');
        }

        $listing->deleteImage($path);

        return back()->with('portal_success', 'Immagine eliminata.');
    }

    // ── Portale: sospendi/riattiva prodotto (nascondi dal pubblico e viceversa) ──

    /**
     * Permette all'azienda proprietaria (o a un admin) di nascondere
     * temporaneamente un prodotto dallo shop pubblico ('suspended') e di
     * riattivarlo quando serve ('active'), senza doverlo eliminare — richiesta
     * di Laura del 2026-07-30. Solo questi due stati sono selezionabili da
     * qui: 'draft'/'expired' restano gestibili solo da admin/sistema tramite
     * adminUpdateStatus().
     */
    public function updateOwnStatus(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBackoffice() || $listing->company_id === $user->company_id, 403);

        $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $listing->update(['status' => $request->input('status')]);

        $message = $request->input('status') === 'suspended'
            ? 'Prodotto sospeso: non è più visibile nello shop pubblico.'
            : 'Prodotto riattivato: torna visibile nello shop.';

        return back()->with('portal_success', $message);
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

        [$companies, $companyKyRules] = $this->companiesWithKyRules();

        return view('admin.listing-create', [
            'pageTitle'      => 'Nuovo prodotto per conto azienda',
            'companies'      => $companies,
            'categories'     => ListingCategory::activeTopLevelOptions(),
            'subcategoriesBySlug' => ListingCategory::activeSubcategoriesBySlug(),
            'companyKyRules' => $companyKyRules,
            'activeNav'      => 'admin-listings',
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
                // order_title per primo: è lo snapshot, l'unico campo che
                // continuerà a esistere quando il catalogo sarà fuori da qui.
                $scope->where('order_title', 'like', "%{$q}%")
                      ->orWhereHas('listing', fn ($l) => $l->where('title', 'like', "%{$q}%"))
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

    /**
     * Categorie/sotto-categorie per il form di modifica prodotto: le attive,
     * più — se il prodotto ha già una categoria/sotto-categoria nel frattempo
     * disattivata — quella stessa voce, per non farla sparire silenziosamente
     * dalla select (che altrimenti la sostituirebbe con la prima opzione
     * disponibile al salvataggio, cambiando categoria a insaputa di chi
     * modifica). Vedi anche ListingCategory::selectableTopLevelSlugs() usato
     * lato validazione con lo stesso ragionamento.
     *
     * @return array{0: \Illuminate\Support\Collection<int, ListingCategory>, 1: array}
     */
    private function categoryFormOptions(?Listing $listing = null): array
    {
        $categories = ListingCategory::activeTopLevelOptions();
        $subcategoriesBySlug = ListingCategory::activeSubcategoriesBySlug();

        if ($listing && $listing->category && ! $categories->contains('slug', $listing->category)) {
            $categories->push(new ListingCategory([
                'slug' => $listing->category,
                'name' => ListingCategory::labelFor($listing->category) ?? $listing->category,
            ]));
        }

        if ($listing && $listing->subcategory) {
            $alreadyListed = collect($subcategoriesBySlug[$listing->category] ?? [])->contains('slug', $listing->subcategory);
            if (! $alreadyListed) {
                $subcategoriesBySlug[$listing->category] = array_merge($subcategoriesBySlug[$listing->category] ?? [], [
                    ['slug' => $listing->subcategory, 'name' => ListingCategory::labelFor($listing->subcategory) ?? $listing->subcategory],
                ]);
            }
        }

        return [$categories, $subcategoriesBySlug];
    }

    /**
     * Le aziende selezionabili in un form prodotto, con le regole KY di
     * ciascuna.
     *
     * Nato dentro adminCreate() il 12/08/2026 ed estratto qui l'08/09/2026,
     * quando il selettore azienda e' comparso anche nel form di modifica del
     * portale (edit()): due copie della stessa query si sarebbero divise alla
     * prima modifica.
     *
     * Il conto business principale di ciascuna azienda arriva eager-loaded
     * (stesso filtro di Company::primaryBusinessAccount()) per non fare una
     * query a testa nel calcolo delle regole.
     *
     * Le regole servono a non far scegliere una % KY/EUR che il salvataggio
     * rifiuterebbe comunque — azienda in debito → solo 100% KY, vedi
     * Account::requiredKyPercentage() — mostrandolo PRIMA dell'invio invece
     * che con "Il valore selezionato per ky percentage non è valido."
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function companiesWithKyRules(): array
    {
        $companies = Company::query()
            ->orderBy('name')
            ->with(['accounts' => function ($query) {
                $query->where('is_system_account', false)
                    ->where('owner_type', 'company')
                    ->whereNull('parent_account_id');
            }])
            ->get(['id', 'name']);

        $companyKyRules = $companies->mapWithKeys(function (Company $company) {
            $account = $company->accounts->first();
            $required = $account?->requiredKyPercentage();

            return [$company->id => [
                'required' => $required !== null,
                'message'  => $required !== null
                    ? '100% KY obbligatorio — il saldo di questa azienda è ' . ky_format($account->available_balance) . ' KY (negativo). Deve incassare KY per recuperare il saldo prima di poter offrire un mix EUR.'
                    : null,
            ]];
        });

        return [$companies, $companyKyRules];
    }

    private function validateListing(Request $request, ?\App\Models\Account $account = null, ?Listing $listing = null): array
    {
        // Determina le percentuali KY consentite per questo venditore
        $allowedPercentages = $account ? $account->allowedKyPercentages() : Listing::KY_PERCENTAGES;

        // Se il venditore e' in debito la percentuale e' forzata al 100
        $requiredPercentage = $account?->requiredKyPercentage();

        $validated = $request->validate([
            'title'          => ['required', 'string', 'max:160'],
            'description'    => ['required', 'string', 'max:2000'],
            // 2026-08-12: categorie/sotto-categorie non più hardcoded, vivono in
            // listing_categories (gestibili da Admin -> Shop -> Categorie).
            // selectableTopLevelSlugs() include anche l'eventuale categoria GIA'
            // assegnata al prodotto pur se nel frattempo disattivata, altrimenti
            // salvare il form di modifica senza toccarla fallirebbe la validazione.
            'category'       => ['required', Rule::in(ListingCategory::selectableTopLevelSlugs($listing?->category))],
            // Sotto-categoria facoltativa (richiesta di Laura): deve appartenere
            // alla categoria scelta, se presente.
            'subcategory'    => ['nullable', 'string', function ($attribute, $value, $fail) use ($request, $listing) {
                if ($value === null || $value === '') {
                    return;
                }
                $allowed = ListingCategory::selectableSubSlugs((string) $request->input('category'), $listing?->subcategory);
                if (! in_array($value, $allowed, true)) {
                    $fail('La sotto-categoria selezionata non è valida per la categoria scelta.');
                }
            }],
            // Il venditore digita il prezzo in KY (es. "10" o "10,50"), non in
            // centesimi: 'numeric' valida la stringa decimale così com'è
            // digitata; la conversione in centesimi avviene subito sotto con
            // ky_to_cents(), stessa convenzione di tutti gli altri form di
            // importo del progetto (vedi CLAUDE.md "Importi sempre in
            // centesimi"). PRIMA questo campo era validato come 'integer' e
            // salvato COSÌ COM'ERA in price_ky: un prodotto a "5 KY" finiva
            // salvato come 5 centesimi (0,05 KY) — bug segnalato da Laura il
            // 24/07 ("ho caricato un prodotto a 5ky, i clienti lo vedono a 0,05").
            // Il minimo NON e' piu' 0,01 KY (audit 26/08/2026, 1.5). Sotto la
            // soglia di config('kmoney.shop.min_price_ky') la quota KY
            // arrotondata puo' diventare zero - un centesimo al 25% fa
            // round(0,25) = 0 - e un movimento da zero non e' registrabile:
            // in un carrello con piu' venditori una riga cosi' faceva fallire
            // l'INTERO acquisto, con un messaggio che nominava l'azienda ma non
            // spiegava niente. Si blocca qui, dove il venditore puo' capirlo.
            'price_ky'       => ['required', 'numeric', 'min:' . number_format(((int) config('kmoney.shop.min_price_ky', 100)) / 100, 2, '.', ''), 'max:99999.99'],
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

        // Normalizza la sotto-categoria: la select del form invia stringa vuota
        // per "— Nessuna —", ma è facoltativa e va salvata come NULL, non "".
        $validated['subcategory'] = ($validated['subcategory'] ?? '') !== '' ? $validated['subcategory'] : null;

        // Converte il prezzo digitato dall'utente (KY, es. "10,50") nei
        // centesimi interi memorizzati in price_ky.
        $validated['price_ky'] = ky_to_cents($validated['price_ky']);

        // Il costo di spedizione ha senso solo per i prodotti "da spedire":
        // per ritiro/servizio lo ignoriamo sempre (anche se il form lo ha
        // eventualmente inviato, es. cambio tipo senza svuotare il campo).
        $validated['shipping_cost'] = ($validated['delivery_type'] === Listing::DELIVERY_TYPE_SPEDIZIONE && $validated['shipping_cost'] !== null && $validated['shipping_cost'] !== '')
            ? ky_to_cents($validated['shipping_cost'])
            : null;

        // Override di sicurezza lato server: se obbligatorio, forza 100%.
        //
        // 13/08/2026 (richiesta di Laura): desired_ky_percentage è la
        // percentuale "vera", scelta liberamente dal negozio quando il conto
        // NON è in debito — viene ripristinata automaticamente su
        // ky_percentage non appena il saldo torna >= 0 (vedi
        // Account::syncListingsKyPercentage(), agganciato al salvataggio del
        // conto). Se il conto è in debito proprio ora, il form permette solo
        // 100% comunque: non c'è una scelta libera più recente da registrare,
        // quindi NON tocchiamo desired_ky_percentage per un prodotto già
        // esistente (resta quella salvata prima di andare in debito). Per un
        // prodotto nuovo, in assenza di storico, ripieghiamo sul valore
        // forzato.
        if ($requiredPercentage !== null) {
            $validated['ky_percentage'] = $requiredPercentage;
            if ($listing === null) {
                $validated['desired_ky_percentage'] = $requiredPercentage;
            }
        } else {
            $validated['desired_ky_percentage'] = $validated['ky_percentage'];
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
        $ridimensionatore = app(\App\Services\ImageResizer::class);

        foreach ($request->file('images') as $file) {
            if ($file->isValid()) {
                $path = $file->store("listings/{$uuid}", 'public');
                $paths[] = $path;

                // Le versioni piccole si fanno QUI, una volta sola, mentre il
                // venditore sta gia' aspettando il salvataggio (27/08/2026).
                // L'alternativa - generarle alla prima visita della pagina -
                // farebbe pagare l'attesa al cliente invece che a chi carica,
                // e la prima visita e' proprio quella che deve essere veloce.
                // Se falliscono non succede niente: si mostrera' l'originale.
                $ridimensionatore->generaTutte($path);
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
        return Account::operativoPer($user);
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
