<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\Order;
use App\Notifications\NewMarketplaceOrderNotification;
use App\Services\CartService;
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
     * La cassa: il carrello diventa un ordine per venditore.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        try {
            $ordini = $this->cartService->checkout($account, $user, $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        // Notifiche fuori dalla transazione, come già fa l'acquisto singolo:
        // un problema con una mail non deve annullare un ordine pagato.
        foreach ($ordini as $ordine) {
            $this->notificaVenditore($ordine);
        }

        $daPagare = $ordini->filter(fn (Order $o) => $o->payment !== null);
        $totaleKy = (int) $ordini->sum('total_ky');

        // Se resta una sola quota in euro da saldare si va dritti a pagarla;
        // se sono più di una si torna al carrello, che le elenca tutte.
        if ($daPagare->count() === 1) {
            return redirect()
                ->route('portal.shop.orders.pay', $daPagare->first()->payment)
                ->with('portal_success', ky_format($totaleKy) . ' KY pagati. Ora completa il pagamento della quota in euro.');
        }

        $messaggio = $ordini->count() === 1
            ? 'Ordine completato: ' . ky_format($totaleKy) . ' KY pagati.'
            : 'Ordini completati: ' . ky_format($totaleKy) . ' KY pagati a ' . $ordini->count() . ' venditori.';

        if ($daPagare->count() > 1) {
            $messaggio .= ' Restano ' . $daPagare->count() . ' quote in euro da saldare: le trovi nei tuoi movimenti.';
        }

        return redirect()->route('portal.shop')->with('portal_success', $messaggio);
    }

    // ── Interno ──────────────────────────────────────────────────────────────

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
