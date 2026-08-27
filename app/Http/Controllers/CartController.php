<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Notifications\NewMarketplaceOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\ShippingAddressBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Il carrello dello shop (fase C del piano carrello) **e** la cassa, per
 * entrambe le strade che portano a un ordine.
 *
 * Fino al 26/08/2026 "Compra ora" viveva in `ListingController::buy()` e non
 * passava di qui: aveva il `confirm()` del browser al posto della cassa,
 * nessuna spunta sulle condizioni di vendita, nessuna scelta dell'indirizzo e
 * nessuna pagina "grazie". Erano due esperienze diverse sullo stesso negozio,
 * e quella piu' usata era la peggiore (audit 26/08, blocco 3).
 *
 * Adesso le due strade si incontrano qui e condividono tutto: la stessa pagina
 * di cassa, la stessa validazione, la stessa rubrica indirizzi, la stessa
 * pagina "grazie". L'unica differenza e' da dove arrivano le righe — dal
 * carrello, oppure da un solo prodotto scelto sulla sua pagina — e vive tutta
 * in `gruppiPerAcquistoImmediato()`.
 *
 * "Compra ora" NON tocca il carrello: chi ha gia' tre cose nel carrello e
 * compra al volo un libro non deve ritrovarsi ad aver pagato anche le altre
 * tre. E' anche il motivo per cui non e' implementato come "aggiungi e vai
 * alla cassa".
 */
class CartController extends Controller
{
    /**
     * Codice d'eccezione di `rigaImmediata()` che vuol dire "questo prodotto
     * non esiste piu' per chi compra": non ha senso rimandarlo alla sua pagina
     * o alla cassa, va riportato al catalogo. Tutti gli altri errori (variante
     * non scelta, quantita', prodotto proprio) lasciano l'utente dov'e'.
     */
    private const TORNA_AL_CATALOGO = 404;

    public function __construct(
        private readonly CartService $cartService,
        private readonly ShippingAddressBook $rubrica,
        private readonly OrderService $orderService,
    ) {
    }

    /** La pagina del carrello, raggruppata per venditore. */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $cart = Cart::attivoPer($account);
        $cart->load('items.listing.company.plan', 'items.listing.activeOffer', 'items.variant.values.attribute');

        $gruppi = $cart->perVenditore();

        return view('portal.cart', [
            'pageTitle'      => 'Il tuo carrello — Shop KMoney',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'cart'           => $cart,
            'gruppi'         => $gruppi,
            'totaleKy'       => $cart->totaleKy(),
            'totaleEuro'     => $cart->totaleEuro(),
            'saldoDisponibile' => $account->saldoDisponibile(),
            'indirizzoCompleto' => $account->hasShippingAddress(),
            'activeNav'      => 'cart',
        ]);
    }

    public function add(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $validated = $request->validate([
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:999999'],
            'variant_id' => ['nullable', 'integer', 'exists:listing_variants,id'],
        ]);

        $variante = empty($validated['variant_id'])
            ? null
            : \App\Models\ListingVariant::query()
                ->where('listing_id', $listing->id)
                ->find($validated['variant_id']);

        try {
            $this->cartService->aggiungi(
                $account,
                $listing,
                (int) ($validated['quantity'] ?? 1),
                $variante,
            );
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', '"' . $listing->title . '" è nel carrello.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        try {
            $this->cartService->aggiornaQuantita($account, $item, (int) $validated['quantity']);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Carrello aggiornato.');
    }

    public function remove(Request $request, CartItem $item): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        try {
            $this->cartService->rimuovi($account, $item);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Prodotto rimosso dal carrello.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $this->cartService->svuota($account);

        return back()->with('portal_success', 'Carrello svuotato.');
    }

    /**
     * La pagina di cassa (fase A, 26/08/2026).
     *
     * Prima esisteva solo il POST qui sotto, chiamato da un bottone del
     * carrello con un confirm() del browser attaccato: fra il carrello e i
     * soldi che partivano non c'era nessuna pagina. Adesso c'e' questa, ed e'
     * l'unico posto da cui si arriva all'addebito.
     */
    public function checkoutForm(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $cart = Cart::attivoPer($account);
        $cart->load('items.listing.company.plan', 'items.listing.activeOffer', 'items.variant.values.attribute');

        if ($cart->isVuoto()) {
            return redirect()->route('portal.cart')->with('portal_error', 'Il carrello è vuoto.');
        }

        $gruppi = $cart->perVenditore();

        // Le stesse guardie del carrello, ricontrollate qui: alla cassa non ci
        // si arriva "di striscio" scrivendo l'URL a mano. Non e' la difesa
        // vera — quella resta dentro CartService::checkout() e
        // OrderService::place(), che rileggono tutto sotto lock — e' il non
        // far vedere una pagina di cassa a chi comunque non potrebbe pagare.
        if ($motivo = $this->motivoPerCuiNonSiPuoPagare($cart, $gruppi, $account)) {
            return redirect()->route('portal.cart')->with('portal_error', $motivo);
        }

        return view('portal.checkout', [
            'pageTitle'         => 'Conferma il tuo ordine — Shop KMoney',
            'currentAccount'    => $account,
            'currentUser'       => $user,
            'gruppi'            => $gruppi,
            'totalePezzi'       => $cart->totalePezzi(),
            'totaleKy'          => $cart->totaleKy(),
            'totaleEuro'        => $cart->totaleEuro(),
            'saldoDisponibile'  => $account->saldoDisponibile(),
            'serveIndirizzo'    => $gruppi->contains(fn ($g) => $g['richiede_indirizzo']),
            // Da dove si arriva e dove si torna indietro: la stessa pagina
            // serve il carrello e l'acquisto immediato.
            'formAction'        => route('portal.cart.checkout'),
            'urlIndietro'       => route('portal.cart'),
            'etichettaIndietro' => 'Torna al carrello',
            'ritornoIndirizzi'  => route('portal.cart.checkout.form', [], false),
            'campiNascosti'     => [],
            // Tutti gli indirizzi salvati, non i primi N: un indirizzo che non
            // si puo' scegliere in cassa non serve a niente (e' l'errore che
            // fa Shopify mostrandone solo 5).
            'indirizzi'         => $this->rubrica->elenco($account),
            'tettoIndirizzi'    => ShippingAddress::MAX_PER_ACCOUNT,
            'activeNav'         => 'cart',
        ]);
    }

    /**
     * La cassa: il carrello diventa un ordine per venditore.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        // Le regole stanno in validaConfermaOrdine(): sono le stesse che usa
        // "Compra ora", ed e' l'unico modo per essere certi che le due strade
        // non tornino a divergere.
        $validated = $this->validaConfermaOrdine($request);

        try {
            $indirizzoScelto = $this->risolviIndirizzo($request, $account, $validated);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        try {
            $ordini = $this->cartService->checkout(
                $account,
                $user,
                $request->ip(),
                blank($validated['buyer_note'] ?? null) ? null : trim($validated['buyer_note']),
                $indirizzoScelto,
            );
        } catch (\RuntimeException $e) {
            // `withInput()` come il catch qui sopra: chi ha scritto una nota e
            // un indirizzo nuovo e incappa in "saldo insufficiente" non deve
            // riscrivere tutto da capo (audit 26/08, blocco 5).
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        // Notifiche fuori dalla transazione, come già fa l'acquisto singolo:
        // un problema con una mail non deve annullare un ordine pagato.
        foreach ($ordini as $ordine) {
            $this->notificaVenditore($ordine);
            $this->notificaCompratore($ordine);
        }

        $totaleKy = (int) $ordini->sum('total_ky');

        $messaggio = $ordini->count() === 1
            ? 'Ordine completato: ' . ky_format($totaleKy) . ' KY pagati.'
            : 'Ordini completati: ' . ky_format($totaleKy) . ' KY pagati a ' . $ordini->count() . ' venditori.';

        // Si passa SEMPRE dalla pagina "grazie", anche quando resta una quota
        // in euro: prima con due quote da saldare si tornava allo shop e le si
        // andava a cercare nei movimenti. Adesso sono elencate li', con il
        // numero d'ordine accanto.
        return redirect()
            ->route('portal.cart.thanks', ['ids' => $ordini->pluck('uuid')->implode(',')])
            ->with('portal_success', $messaggio);
    }

    /**
     * La pagina "grazie": numero d'ordine, cosa succede adesso, e le eventuali
     * quote in euro ancora da saldare.
     *
     * Gli uuid viaggiano in query string e non in sessione, cosi' la pagina
     * regge un F5. L'unica autorizzazione che conta e' il buyer_account_id:
     * con l'uuid di un ordine altrui qui non si vede niente.
     */
    public function thanks(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => trim($id))
            ->filter()
            ->take(20)
            ->all();

        $ordini = $ids === [] ? collect() : Order::query()
            ->whereIn('uuid', $ids)
            ->where('buyer_account_id', $account->id)
            ->with(['company', 'items', 'payment'])
            ->orderBy('id')
            ->get();

        if ($ordini->isEmpty()) {
            return redirect()->route('portal.shop');
        }

        return view('portal.checkout-thanks', [
            'pageTitle'      => 'Ordine confermato — Shop KMoney',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'ordini'         => $ordini,
            'activeNav'      => 'shop',
        ]);
    }


    // ─────────────────────────────────────────────────────────────────────────
    // "Compra ora": la stessa cassa, con un prodotto solo e senza carrello
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La cassa per un acquisto immediato dalla pagina del prodotto.
     *
     * Il prodotto, la combinazione e la quantita' arrivano in query string:
     * questa pagina non scrive niente, quindi un GET va bene e regge un F5.
     * Il carrello non viene mai toccato.
     */
    public function buyNowForm(Request $request, Listing $listing): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        try {
            [$variante, $quantita] = $this->rigaImmediata($request, $listing, $account);
        } catch (\RuntimeException $e) {
            return $this->tornaIndietro($e, $listing, conInput: false);
        }

        $parametri = array_filter([
            'variant_id' => $variante?->id,
            'quantity'   => $quantita,
        ]);

        // Arrivato in POST dal form della pagina prodotto: si rimbalza in GET
        // con gli stessi parametri. Un F5 sulla cassa non deve chiedere
        // "vuoi reinviare i dati?", e il token CSRF non deve finire nell'URL.
        if ($request->isMethod('post')) {
            return redirect()->route('portal.shop.buy.form', array_merge(['listing' => $listing->id], $parametri));
        }

        $gruppi = $this->gruppiPerAcquistoImmediato($listing, $variante, $quantita);

        // Le stesse guardie del carrello. Non sono la difesa vera - quella
        // resta dentro OrderService::place(), che rilegge tutto sotto lock -
        // servono a non far vedere una cassa a chi comunque non potrebbe pagare.
        if ($motivo = $this->motivoPerCuiNonSiPuoPagareImmediato($gruppi, $account)) {
            return redirect()->route('portal.shop.show', $listing)->with('portal_error', $motivo);
        }

        return view('portal.checkout', [
            'pageTitle'         => 'Conferma il tuo ordine — Shop KMoney',
            'currentAccount'    => $account,
            'currentUser'       => $user,
            'gruppi'            => $gruppi,
            'totalePezzi'       => $quantita,
            'totaleKy'          => (int) $gruppi->sum('ky'),
            'totaleEuro'        => (int) $gruppi->sum('eur'),
            'saldoDisponibile'  => $account->saldoDisponibile(),
            'serveIndirizzo'    => $gruppi->contains(fn ($g) => $g['richiede_indirizzo']),
            'indirizzi'         => $this->rubrica->elenco($account),
            'tettoIndirizzi'    => ShippingAddress::MAX_PER_ACCOUNT,
            'activeNav'         => 'shop',
            'formAction'        => route('portal.shop.buy', $listing),
            'urlIndietro'       => route('portal.shop.show', $listing),
            'etichettaIndietro' => 'Torna al prodotto',
            'ritornoIndirizzi'  => route('portal.shop.buy.form', array_merge(['listing' => $listing->id], $parametri), false),
            // Combinazione e quantita' viaggiano nascoste nel form: il POST
            // deve poter rifare da solo la riga, senza fidarsi di niente che
            // non sia stato rivalidato.
            'campiNascosti'     => $parametri,
        ]);
    }

    /**
     * L'acquisto immediato: un prodotto solo diventa un ordine.
     *
     * Da qui in giu' e' identico alla cassa del carrello - stessa validazione,
     * stesso indirizzo, stessa pagina "grazie" - e infatti la maggior parte del
     * corpo e' condivisa con `checkout()`.
     */
    public function buyNow(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $validated = $this->validaConfermaOrdine($request, [
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:999999'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        try {
            [$variante, $quantita] = $this->rigaImmediata($request, $listing, $account);
        } catch (\RuntimeException $e) {
            return $this->tornaIndietro($e, $listing, conInput: true);
        }

        try {
            $indirizzoScelto = $this->risolviIndirizzo($request, $account, $validated);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        try {
            $ordine = $this->orderService->place(
                buyerAccount: $account,
                user: $user,
                righe: [['listing' => $listing, 'variant' => $variante, 'quantity' => $quantita]],
                ipAddress: $request->ip(),
                buyerNote: blank($validated['buyer_note'] ?? null) ? null : trim($validated['buyer_note']),
                shippingAddress: $indirizzoScelto,
            );
        } catch (\RuntimeException $e) {
            // Con `withInput()`: chi ha scritto una nota e un indirizzo nuovo e
            // incappa in "saldo insufficiente" non deve riscrivere tutto.
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        $this->notificaVenditore($ordine);
        $this->notificaCompratore($ordine);

        return redirect()
            ->route('portal.cart.thanks', ['ids' => $ordine->uuid])
            ->with('portal_success', 'Ordine completato: ' . ky_format((int) $ordine->total_ky) . ' KY pagati.');
    }

    /**
     * Combinazione e quantita' di un acquisto immediato, rivalidate.
     *
     * Sta in un posto solo perche' il GET della cassa e il POST che paga devono
     * vedere ESATTAMENTE lo stesso prodotto: se il GET accetta una combinazione
     * che il POST rifiuta (o viceversa) si apre una cassa che non si puo'
     * chiudere.
     *
     * @return array{0: \App\Models\ListingVariant|null, 1: int}
     *
     * @throws \RuntimeException con un messaggio gia' pronto per l'utente
     */
    private function rigaImmediata(Request $request, Listing $listing, Account $account): array
    {
        if ($listing->status !== 'active' || $listing->is_expired) {
            throw new \RuntimeException('Questo prodotto non è più disponibile.', self::TORNA_AL_CATALOGO);
        }

        if ((int) $listing->company_id === (int) $account->company_id) {
            throw new \RuntimeException('Non puoi acquistare un prodotto pubblicato dalla tua stessa azienda.');
        }

        // Azienda sospesa = fuori dal commercio. Un venditore sospeso e' come
        // un prodotto che non c'e' piu': si torna al catalogo. Il compratore
        // sospeso invece resta dov'e', il problema e' suo e deve leggerlo.
        if ($listing->company?->isSuspended()) {
            throw new \RuntimeException('Questo venditore non è al momento operativo nel circuito: riprova più tardi.', self::TORNA_AL_CATALOGO);
        }

        if ($account->company?->isSuspended()) {
            throw new \RuntimeException('La tua azienda è sospesa: non puoi effettuare acquisti finché la sospensione è attiva. Contatta il supporto.');
        }

        $quantita = max(1, (int) ($request->input('quantity') ?: 1));

        if ($quantita > 999999) {
            throw new \RuntimeException('Quantità non valida.');
        }

        $variante = null;
        $varianteId = $request->input('variant_id');

        if (! blank($varianteId)) {
            $variante = \App\Models\ListingVariant::query()
                ->where('listing_id', $listing->id)
                ->find((int) $varianteId);

            if (! $variante) {
                throw new \RuntimeException('Questa combinazione non appartiene a questo prodotto.');
            }

            if (! $variante->is_active) {
                throw new \RuntimeException('Questa combinazione non è più disponibile.');
            }
        }

        if ($listing->isVariabile() && ! $variante) {
            throw new \RuntimeException('Scegli una variante prima di acquistare.');
        }

        return [$variante, $quantita];
    }

    /**
     * Dove rimandare chi non puo' comprare questo prodotto.
     *
     * Un prodotto sospeso o scaduto non esiste piu' per chi compra: si torna al
     * catalogo, come faceva "Compra ora" da sempre. Per tutto il resto si resta
     * dov'e', con quello che aveva scritto ancora nei campi.
     */
    private function tornaIndietro(\RuntimeException $e, Listing $listing, bool $conInput): RedirectResponse
    {
        if ($e->getCode() === self::TORNA_AL_CATALOGO) {
            return redirect()->route('portal.shop')->with('portal_error', $e->getMessage());
        }

        // Sul POST della cassa si resta dov'e' con i campi ancora pieni. Sulla
        // GET no: `back()` senza referer finirebbe sulla home, e comunque la
        // scelta della combinazione si fa sulla pagina del prodotto - e' li'
        // che va rimandato chi e' arrivato senza averla scelta.
        return $conInput
            ? back()->withInput()->with('portal_error', $e->getMessage())
            : redirect()->route('portal.shop.show', $listing)->with('portal_error', $e->getMessage());
    }

    /**
     * La stessa forma che `Cart::perVenditore()` produce, per una riga sola.
     *
     * Costruire un `CartItem` non salvato invece di inventare una struttura
     * nuova e' quello che permette alla pagina di cassa di restare UNA: la view
     * chiama `totaleKy()` ed `etichettaVariante()` senza sapere se dietro c'e'
     * un carrello vero o un acquisto al volo.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function gruppiPerAcquistoImmediato(Listing $listing, $variante, int $quantita)
    {
        $riga = new CartItem(['quantity' => $quantita]);
        $riga->setRelation('listing', $listing);
        $riga->setRelation('variant', $variante);

        $daSpedire = $listing->requiresShippingAddress();

        $spedizioneKy  = $daSpedire ? (int) $listing->shipping_ky_amount : 0;
        $spedizioneEur = $daSpedire ? (int) $listing->shipping_euro_amount : 0;

        return collect([[
            'company'            => $listing->company,
            'righe'              => collect([$riga]),
            'ky'                 => $riga->totaleKy() + $spedizioneKy,
            'eur'                => $riga->totaleEuro() + $spedizioneEur,
            'spedizione_ky'      => $spedizioneKy,
            'spedizione_eur'     => $spedizioneEur,
            'richiede_indirizzo' => $daSpedire,
        ]]);
    }

    /** Come `motivoPerCuiNonSiPuoPagare()`, ma per una riga che non sta in un carrello. */
    private function motivoPerCuiNonSiPuoPagareImmediato($gruppi, Account $account): ?string
    {
        $riga = $gruppi->first()['righe']->first();

        if (! $riga->isDisponibile()) {
            return ($riga->listing?->title ?? 'Questo prodotto') . ': ' . $riga->motivoIndisponibilita();
        }

        if ($gruppi->contains(fn ($g) => $g['richiede_indirizzo']) && ! $account->hasShippingAddress()) {
            return 'Questo prodotto va spedito: completa il tuo indirizzo di spedizione per procedere.';
        }

        $totale = (int) $gruppi->sum('ky');

        if ($account->saldoDisponibile() < $totale) {
            return 'Saldo insufficiente: ti mancano ' . ky_format($totale - $account->saldoDisponibile()) . ' KY.';
        }

        return null;
    }

    /**
     * Le regole di validazione che valgono per QUALSIASI conferma d'ordine.
     *
     * Estratte da `checkout()` il 26/08/2026 quando "Compra ora" e' entrato in
     * cassa: erano gia' l'unica cosa che separava un clic da un addebito, e
     * duplicarle voleva dire lasciarle divergere di nuovo.
     *
     * @param  array<string, mixed>  $extra  regole aggiuntive del chiamante
     * @return array<string, mixed>
     */
    private function validaConfermaOrdine(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            // Spuntare le condizioni e' obbligatorio: e' l'unico gesto
            // esplicito rimasto ora che il confirm() del browser non c'e' piu'.
            'accetto_condizioni' => ['accepted'],
            'buyer_note'         => ['nullable', 'string', 'max:500'],
            'indirizzo_scelto'   => ['nullable', 'string', 'max:20'],
            'salva_indirizzo'    => ['nullable', 'boolean'],
            'rendi_predefinito'  => ['nullable', 'boolean'],
            'label'              => ['nullable', 'string', 'max:60'],
            'recipient_name'     => ['nullable', 'string', 'max:150'],
            'address'            => ['nullable', 'string', 'max:255'],
            'city'               => ['nullable', 'string', 'max:100'],
            'postal_code'        => ['nullable', 'string', 'max:12'],
            'province'           => ['nullable', 'string', 'max:60'],
            'phone'              => ['nullable', 'string', 'max:30'],
        ], $extra), [
            'accetto_condizioni.accepted' => 'Per completare l\'ordine devi accettare le condizioni di vendita.',
        ]);
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Il motivo per cui questo carrello non puo' andare in cassa, o null.
     * Sono le stesse tre condizioni che il carrello gia' mostra come avviso:
     * qui servono per non far aprire la pagina di cassa a vuoto.
     */
    private function motivoPerCuiNonSiPuoPagare(Cart $cart, $gruppi, Account $account): ?string
    {
        $indisponibile = $cart->items->first(fn (CartItem $r) => ! $r->isDisponibile());

        if ($indisponibile) {
            return ($indisponibile->listing?->title ?? 'Un prodotto del carrello')
                . ': ' . $indisponibile->motivoIndisponibilita();
        }

        if ($gruppi->contains(fn ($g) => $g['richiede_indirizzo']) && ! $account->hasShippingAddress()) {
            return 'Nel carrello ci sono prodotti da spedire: completa il tuo indirizzo di spedizione per procedere.';
        }

        if ($account->saldoDisponibile() < $cart->totaleKy()) {
            return 'Saldo insufficiente: ti mancano '
                . ky_format($cart->totaleKy() - $account->saldoDisponibile()) . ' KY.';
        }

        return null;
    }

    /**
     * Quale indirizzo deve ricevere questo ordine.
     *
     * Tre casi:
     *   - un id -> uno della rubrica (e dev'essere della TUA rubrica);
     *   - "nuovo" -> i campi compilati in cassa, salvati in rubrica se richiesto,
     *     altrimenti usati solo per questo ordine;
     *   - niente -> null, e OrderService usa il predefinito del conto. E' il
     *     comportamento che la cassa aveva prima che la rubrica esistesse, ed
     *     e' quello che tiene in piedi "compra ora" dalla pagina prodotto.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws \RuntimeException con un messaggio gia' pronto per l'utente
     */
    private function risolviIndirizzo(Request $request, Account $account, array $validated): ?ShippingAddress
    {
        $scelto = trim((string) ($validated['indirizzo_scelto'] ?? ''));

        if ($scelto === '') {
            return null;
        }

        if ($scelto !== 'nuovo') {
            $indirizzo = ShippingAddress::query()->find((int) $scelto);

            // Non un 404: chi manovra gli id di un'altra rubrica deve leggere
            // che non e' sua, e l'ordine non deve partire.
            if (! $indirizzo || (int) $indirizzo->account_id !== (int) $account->id) {
                throw new \RuntimeException('L\'indirizzo scelto non è nella tua rubrica.');
            }

            return $indirizzo;
        }

        $dati = [
            'label'          => $validated['label'] ?? null,
            'recipient_name' => $validated['recipient_name'] ?? null,
            'address'        => $validated['address'] ?? null,
            'city'           => $validated['city'] ?? null,
            'postal_code'    => $validated['postal_code'] ?? null,
            'province'       => $validated['province'] ?? null,
            'phone'          => $validated['phone'] ?? null,
        ];

        foreach (['recipient_name', 'address', 'city', 'postal_code'] as $obbligatorio) {
            if (blank(is_string($dati[$obbligatorio]) ? trim($dati[$obbligatorio]) : $dati[$obbligatorio])) {
                throw new \RuntimeException('Per spedire servono almeno nome del destinatario, via, città e CAP.');
            }
        }

        if ($request->boolean('salva_indirizzo')) {
            // Se la rubrica e' piena, ShippingAddressBook lancia e l'utente
            // legge che deve liberare un posto o togliere la spunta.
            return $this->rubrica->aggiungi($account, $dati, $request->boolean('rendi_predefinito'));
        }

        // Non salvato: vale solo per questo ordine. L'account_id c'e' lo stesso,
        // cosi' la difesa in profondita' dentro OrderService lo riconosce come
        // proprio.
        return new ShippingAddress(array_merge($dati, ['account_id' => $account->id]));
    }

    /**
     * Avvisa CHI HA COMPRATO che l'ordine è stato registrato (fase C).
     *
     * Sta accanto a `notificaVenditore()` e viene chiamato negli stessi due
     * punti - la cassa del carrello e "Compra ora" - perche' le due strade
     * devono avvisare le stesse persone. E' esattamente il tipo di cosa che
     * negli ultimi due giorni abbiamo visto divergere quando vive in due posti.
     *
     * Fuori dalla transazione, come la notifica al venditore: una email che non
     * parte non deve far fallire un acquisto gia' pagato.
     */
    private function notificaCompratore(Order $ordine): void
    {
        $destinatario = $ordine->buyerUser;

        if (! $destinatario) {
            return;
        }

        try {
            $destinatario->notify(new OrderPlacedNotification($ordine));
        } catch (\Throwable $e) {
            Log::error('order.placed.notify_failed', [
                'order_id' => $ordine->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function notificaVenditore(Order $ordine): void
    {
        $destinatario = $ordine->company->primaryBusinessAccount()?->ownerUser;

        if (! $destinatario) {
            return;
        }

        try {
            $destinatario->notify(new NewMarketplaceOrderNotification(
                $ordine->transfer,
                $ordine->summary_title,
                (int) $ordine->items()->sum('quantity'),
                $ordine->payment,
            ));
        } catch (\Throwable $e) {
            Log::error('marketplace_order.notify_failed', [
                'order_id' => $ordine->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function resolveAccount($user): ?Account
    {
        return Account::operativoPer($user);
    }

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
