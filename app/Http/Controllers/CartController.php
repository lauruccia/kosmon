<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Notifications\NewMarketplaceOrderNotification;
use App\Services\CartService;
use App\Services\ShippingAddressBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Il carrello dello shop (fase C del piano carrello).
 *
 * "Compra ora" resta dov'era, sulla pagina del prodotto: chi vuole un pezzo
 * solo non deve passare da tre pagine, e per noi quella è la strada già
 * collaudata che resta sempre percorribile. Il carrello è la strada in più.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ShippingAddressBook $rubrica,
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
            'cart'              => $cart,
            'gruppi'            => $gruppi,
            'totaleKy'          => $cart->totaleKy(),
            'totaleEuro'        => $cart->totaleEuro(),
            'saldoDisponibile'  => $account->saldoDisponibile(),
            'serveIndirizzo'    => $gruppi->contains(fn ($g) => $g['richiede_indirizzo']),
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

        $validated = $request->validate([
            // Spuntare le condizioni e' obbligatorio: e' l'unico gesto
            // esplicito rimasto ora che il confirm() del browser non c'e' piu'.
            'accetto_condizioni' => ['accepted'],
            'buyer_note'         => ['nullable', 'string', 'max:500'],
            // Quale indirizzo: l'id di uno della rubrica, oppure "nuovo".
            // Assente = il predefinito del conto, cioe' come si comportava la
            // cassa prima che la rubrica esistesse.
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
        ], [
            'accetto_condizioni.accepted' => 'Per completare l\'ordine devi accettare le condizioni di vendita.',
        ]);

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
            return back()->with('portal_error', $e->getMessage());
        }

        // Notifiche fuori dalla transazione, come già fa l'acquisto singolo:
        // un problema con una mail non deve annullare un ordine pagato.
        foreach ($ordini as $ordine) {
            $this->notificaVenditore($ordine);
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
