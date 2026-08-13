<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Flusso di pagamento EUR (quota non-KY) di un ordine shop, dopo che
 * ListingController::buy() ha già registrato l'addebito KY e creato il
 * MarketplaceOrderPayment corrispondente in stato "pending".
 *
 * L'acquirente sceglie qui il metodo tra quelli attivi configurati
 * dall'azienda venditrice (Stripe/PayPal/Bonifico — vedi PaymentGateway) e
 * viene reindirizzato al provider (Stripe/PayPal) o alle istruzioni di
 * bonifico. Il denaro arriva SEMPRE sul conto proprio dell'azienda: Kosmopay
 * non lo intermedia mai.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $gateways)
    {
    }

    /** Verifica che l'utente sia l'acquirente di questo pagamento. */
    private function authorizeBuyer(Request $request, MarketplaceOrderPayment $payment): void
    {
        $user = $request->user();

        abort_unless($this->isBuyer($user, $payment) || $user->is_super_admin, 403);
    }

    private function isBuyer($user, MarketplaceOrderPayment $payment): bool
    {
        $buyerAccount = $payment->transfer?->fromAccount;

        return (bool) ($buyerAccount && (
            $buyerAccount->owner_user_id === $user->id
            || ($buyerAccount->company_id && $buyerAccount->company_id === $user->company_id)
        ));
    }

    /**
     * L'azienda venditrice del prodotto — con permesso marketplace, stessa
     * regola usata in confirmBankTransfer() per confermare un bonifico.
     */
    private function isSeller($user, MarketplaceOrderPayment $payment): bool
    {
        return $payment->company_id === $user->company_id
            && ($user->canAccessMarketplace() || $user->is_super_admin);
    }

    /**
     * Verifica che l'utente possa VEDERE questo ordine: acquirente o
     * venditore. Le azioni che avviano/verificano un pagamento restano
     * riservate al solo acquirente (vedi authorizeBuyer()) — questo metodo
     * serve solo per la pagina di riepilogo/stato, da cui il venditore deve
     * poter arrivare per confermare un bonifico ricevuto (2026-08-13,
     * altrimenti non aveva alcun modo di raggiungere quel bottone: la mail
     * di notifica non linkava qui e questa show() dava 403 a chiunque non
     * fosse l'acquirente).
     */
    private function authorizeViewer(Request $request, MarketplaceOrderPayment $payment): void
    {
        $user = $request->user();

        abort_unless(
            $this->isBuyer($user, $payment) || $this->isSeller($user, $payment) || $user->is_super_admin,
            403
        );
    }

    /** GET /shop/ordini/{payment} */
    public function show(Request $request, MarketplaceOrderPayment $payment): View|RedirectResponse
    {
        $this->authorizeViewer($request, $payment);

        if ($payment->isPaid()) {
            return redirect()->route('portal.shop.show', $payment->listing_id ?? 0)
                ->with('portal_success', 'Questo ordine è già stato pagato.');
        }

        $payment->load(['listing', 'company', 'paymentGateway']);

        $activeGateways = PaymentGateway::query()
            ->where('company_id', $payment->company_id)
            ->active()
            ->get()
            ->filter(fn (PaymentGateway $g) => $g->is_configured)
            ->keyBy('provider');

        return view('portal.shop-order-pay', [
            'pageTitle'       => 'Completa il pagamento',
            'payment'         => $payment,
            'activeGateways'  => $activeGateways,
            'providers'       => PaymentGateway::PROVIDERS,
            'activeNav'       => 'shop',
            'isSeller'        => $this->isSeller($request->user(), $payment),
        ]);
    }

    /** POST /shop/ordini/{payment}/stripe */
    public function initiateStripe(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        return $this->initiate($request, $payment, PaymentGateway::PROVIDER_STRIPE);
    }

    /** GET /shop/ordini/{payment}/stripe/ritorno */
    public function stripeReturn(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        return $this->verify($request, $payment, PaymentGateway::PROVIDER_STRIPE);
    }

    /** POST /shop/ordini/{payment}/paypal */
    public function initiatePaypal(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        return $this->initiate($request, $payment, PaymentGateway::PROVIDER_PAYPAL);
    }

    /** GET /shop/ordini/{payment}/paypal/ritorno */
    public function paypalReturn(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        return $this->verify($request, $payment, PaymentGateway::PROVIDER_PAYPAL);
    }

    /** POST /shop/ordini/{payment}/bonifico */
    public function initiateBankTransfer(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        $this->authorizeBuyer($request, $payment);
        abort_if($payment->isPaid(), 400, 'Ordine già pagato.');

        $gateway = PaymentGateway::query()
            ->where('company_id', $payment->company_id)
            ->where('provider', PaymentGateway::PROVIDER_BANK_TRANSFER)
            ->active()
            ->first();

        abort_unless($gateway && $gateway->is_configured, 404, 'Bonifico non disponibile per questo venditore.');

        $this->gateways->forGateway($gateway)->initiate($gateway, $payment, '', '');

        return redirect()->route('portal.shop.orders.pay', $payment)
            ->with('portal_success', 'Ecco le coordinate per il bonifico.');
    }

    /**
     * L'azienda venditrice conferma di aver ricevuto il bonifico — scelta
     * esplicita di Laura ("L'azienda venditrice conferma") invece di una
     * verifica automatica lato admin.
     *
     * POST /shop/ordini/{payment}/conferma-bonifico
     */
    public function confirmBankTransfer(Request $request, MarketplaceOrderPayment $payment): RedirectResponse
    {
        $user = $request->user();

        abort_unless($this->isSeller($user, $payment) || $user->canAccessBackoffice(), 403);

        abort_unless($payment->provider === PaymentGateway::PROVIDER_BANK_TRANSFER, 400);

        if (! $payment->isPaid()) {
            $payment->update([
                'status'               => MarketplaceOrderPayment::STATUS_PAID,
                'paid_at'              => now(),
                'confirmed_by_user_id' => $user->id,
            ]);
        }

        return back()->with('portal_success', 'Bonifico confermato come ricevuto.');
    }

    private function initiate(Request $request, MarketplaceOrderPayment $payment, string $provider): RedirectResponse
    {
        $this->authorizeBuyer($request, $payment);
        abort_if($payment->isPaid(), 400, 'Ordine già pagato.');

        $gateway = PaymentGateway::query()
            ->where('company_id', $payment->company_id)
            ->where('provider', $provider)
            ->active()
            ->first();

        if (! $gateway || ! $gateway->is_configured) {
            return redirect()->route('portal.shop.orders.pay', $payment)
                ->with('portal_error', 'Questo metodo di pagamento non è al momento disponibile per questo venditore.');
        }

        $successUrl = route('portal.shop.orders.pay.' . ($provider === PaymentGateway::PROVIDER_STRIPE ? 'stripe' : 'paypal') . '.return', $payment);
        $cancelUrl  = route('portal.shop.orders.pay', $payment);

        $redirectUrl = $this->gateways->forGateway($gateway)->initiate($gateway, $payment, $successUrl, $cancelUrl);

        if (! $redirectUrl) {
            return redirect()->route('portal.shop.orders.pay', $payment)
                ->with('portal_error', 'Non è stato possibile avviare il pagamento. Riprova o scegli un altro metodo.');
        }

        return redirect()->away($redirectUrl);
    }

    private function verify(Request $request, MarketplaceOrderPayment $payment, string $provider): RedirectResponse
    {
        $this->authorizeBuyer($request, $payment);

        $gateway = $payment->paymentGateway;

        if (! $gateway) {
            return redirect()->route('portal.shop.orders.pay', $payment)
                ->with('portal_error', 'Impossibile verificare il pagamento.');
        }

        try {
            $paid = $this->gateways->forGateway($gateway)->verify($gateway, $payment, $request);
        } catch (\Throwable $e) {
            Log::error('payment_gateway.verify_failed', [
                'payment_id' => $payment->id,
                'provider'   => $provider,
                'error'      => $e->getMessage(),
            ]);
            $paid = false;
        }

        return redirect()->route('portal.shop.orders.pay', $payment)
            ->with($paid ? 'portal_success' : 'portal_error', $paid
                ? 'Pagamento ricevuto, grazie!'
                : 'Il pagamento non risulta ancora confermato. Se hai completato il pagamento, attendi qualche istante e ricarica la pagina.');
    }
}
