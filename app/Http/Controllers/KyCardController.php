<?php

namespace App\Http\Controllers;

use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\User;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class KyCardController extends PortalController
{
    public function __construct(private readonly TransferBookingService $transferService) {}

    // ── Lista card acquistabili ─────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        $cards = KyCard::active()->get();

        $recentPurchases = KyCardPurchase::where('account_id', $currentAccount->id)
            ->with('kyCard')
            ->latest()
            ->take(5)
            ->get();

        return view('portal.ky-cards', compact('currentAccount', 'currentUser', 'cards', 'recentPurchases') + [
            'pageTitle' => 'Ricarica KMoney',
            'activeNav' => 'ky-cards',
        ]);
    }



    // -- Storico acquisti (GET /ricarica/storico) ----------------------------

    public function storico(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        // Filtri
        $dal     = $request->filled('dal')    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->dal)    ? $request->dal    : null;
        $al      = $request->filled('al')     && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->al)     ? $request->al     : null;
        $stato   = in_array($request->stato,   ['completed', 'pending', 'pending_bank_transfer', 'failed'], true) ? $request->stato   : null;
        $metodo  = in_array($request->metodo,  ['stripe', 'paypal', 'bank_transfer'], true)                      ? $request->metodo  : null;
        $cardId  = $request->filled('card_id') && is_numeric($request->card_id)                                   ? (int) $request->card_id : null;

        $filters = compact('dal', 'al', 'stato', 'metodo', 'cardId');

        // Query filtrata
        $query = KyCardPurchase::where('account_id', $currentAccount->id)->with('kyCard')->latest();

        if ($dal)    { $query->whereDate('created_at', '>=', $dal); }
        if ($al)     { $query->whereDate('created_at', '<=', $al); }
        if ($stato)  { $query->where('status', $stato); }
        if ($metodo) { $query->where('payment_method', $metodo); }
        if ($cardId) { $query->where('ky_card_id', $cardId); }

        $purchases = $query->paginate(20)->withQueryString();

        // KPI lifetime (sempre, ignorano filtri)
        $totals = KyCardPurchase::where('account_id', $currentAccount->id)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as count, SUM(price_eur_cents) as eur_cents, SUM(ky_amount) as ky_total')
            ->first();

        // Lista card per il filtro
        $availableCards = KyCard::orderBy('sort_order')->get(['id', 'name']);

        return view('portal.ky-card-storico', compact('currentAccount', 'currentUser', 'purchases', 'totals', 'filters', 'availableCards') + [
            'pageTitle' => 'Storico ricariche',
            'activeNav' => 'ky-cards',
        ]);
    }

    // -- Pagina checkout dedicata (GET /ricarica/{kyCard}) ------------------

    public function checkout(Request $request, KyCard $kyCard): View|RedirectResponse
    {
        abort_unless($kyCard->is_active, 404);

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        return view('portal.ky-card-checkout', compact('currentAccount', 'currentUser') + [
            'card'      => $kyCard,
            'pageTitle' => 'Acquista ' . $kyCard->name,
            'activeNav' => 'ky-cards',
        ]);
    }

    // -- STRIPE: avvia checkout ─────────────────────────────────────────────

    public function stripeCheckout(Request $request, KyCard $kyCard): RedirectResponse
    {
        abort_unless($kyCard->is_active, 404);
        abort_unless((bool) $kyCard->stripe_price_id, 422, 'Pagamento con carta non disponibile per questa card.');
        abort_unless(config('services.stripe.secret'), 503, 'Stripe non configurato.');

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        $purchase = KyCardPurchase::create([
            'ky_card_id'      => $kyCard->id,
            'account_id'      => $currentAccount->id,
            'user_id'         => $currentUser->id,
            'price_eur_cents' => $kyCard->price_eur_cents,
            'ky_amount'       => $kyCard->ky_total,
            'status'          => 'pending',
            'payment_method'  => 'stripe',
        ]);

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [['price' => $kyCard->stripe_price_id, 'quantity' => 1]],
                'mode'                 => 'payment',
                'success_url'          => route('portal.ky-cards.success', ['purchase' => $purchase->uuid]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'           => route('portal.ky-cards.index'),
                'client_reference_id'  => $purchase->uuid,
                'metadata'             => ['purchase_uuid' => $purchase->uuid],
            ]);

            $purchase->update(['stripe_checkout_session_id' => $session->id]);

            return redirect($session->url);

        } catch (\Exception $e) {
            $purchase->update(['status' => 'failed']);
            Log::error('Stripe checkout error', ['error' => $e->getMessage(), 'purchase' => $purchase->uuid]);
            return redirect()->route('portal.ky-cards.index')
                ->with('error', 'Errore avvio pagamento Stripe. Riprova o scegli un altro metodo.');
        }
    }

    // ── PAYPAL: crea ordine (AJAX) ─────────────────────────────────────────

    public function paypalCreateOrder(Request $request, KyCard $kyCard): JsonResponse
    {
        abort_unless($kyCard->is_active, 404);
        abort_unless(config('services.paypal.client_id'), 503, 'PayPal non configurato.');

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        // Crea il purchase in pending
        $purchase = KyCardPurchase::create([
            'ky_card_id'      => $kyCard->id,
            'account_id'      => $currentAccount->id,
            'user_id'         => $currentUser->id,
            'price_eur_cents' => $kyCard->price_eur_cents,
            'ky_amount'       => $kyCard->ky_total,
            'status'          => 'pending',
            'payment_method'  => 'paypal',
        ]);

        // Crea ordine PayPal via REST API
        try {
            $accessToken = $this->getPaypalAccessToken();
            $amount      = number_format($kyCard->price_eur, 2, '.', '');

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => ['currency_code' => 'EUR', 'value' => $amount],
                        'description' => 'KYCard: ' . $kyCard->name . ' — ' . $kyCard->ky_total . ' KY',
                        'custom_id'   => $purchase->uuid,
                    ]],
                    'application_context' => [
                        'return_url' => route('portal.ky-cards.paypal-capture', ['purchase' => $purchase->uuid]),
                        'cancel_url' => route('portal.ky-cards.index'),
                        'brand_name' => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $purchase->update(['paypal_order_id' => $order['id']]);

            return response()->json(['id' => $order['id'], 'purchase_uuid' => $purchase->uuid]);

        } catch (\Exception $e) {
            $purchase->update(['status' => 'failed']);
            Log::error('PayPal create order error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Errore PayPal. Riprova.'], 500);
        }
    }

    // ── PAYPAL: cattura pagamento dopo approvazione ─────────────────────────

    public function paypalCapture(Request $request, string $purchase): RedirectResponse
    {
        $purchase = KyCardPurchase::where('uuid', $purchase)->firstOrFail();

        if (!$purchase->isPending() || $purchase->payment_method !== 'paypal') {
            return redirect()->route('portal.ky-cards.index');
        }

        try {
            $accessToken = $this->getPaypalAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders/' . $purchase->paypal_order_id . '/capture');

            $capture = $response->json();

            if ($capture['status'] === 'COMPLETED') {
                $this->creditKy($purchase);
            } else {
                $purchase->update(['status' => 'failed']);
            }

        } catch (\Exception $e) {
            Log::error('PayPal capture error', ['error' => $e->getMessage(), 'purchase' => $purchase->uuid]);
            $purchase->update(['status' => 'failed']);
        }

        $purchase->refresh();

        return redirect()->route('portal.ky-cards.success', ['purchase' => $purchase->uuid]);
    }

    // ── BONIFICO: genera istruzioni ────────────────────────────────────────

    public function bankTransfer(Request $request, KyCard $kyCard): View|RedirectResponse
    {
        abort_unless($kyCard->is_active, 404);

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(), $this->requestedCompanyId($request)
        );

        $purchase = KyCardPurchase::create([
            'ky_card_id'      => $kyCard->id,
            'account_id'      => $currentAccount->id,
            'user_id'         => $currentUser->id,
            'price_eur_cents' => $kyCard->price_eur_cents,
            'ky_amount'       => $kyCard->ky_total,
            'status'          => 'pending_bank_transfer',
            'payment_method'  => 'bank_transfer',
        ]);

        return view('portal.ky-card-bank-transfer', [
            'purchase'       => $purchase,
            'kyCard'         => $kyCard,
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'pageTitle'      => 'Istruzioni bonifico',
            'activeNav'      => 'ky-cards',
            // Dati bancari dal config (env() risiede in config/kmoney.php,
            // così i valori restano corretti anche con la config in cache)
            'bankIban'       => config('kmoney.bank_iban'),
            'bankName'       => config('kmoney.bank_name'),
            'bankBeneficiary'=> config('kmoney.bank_beneficiary'),
        ]);
    }

    // ── Pagamento riuscito ─────────────────────────────────────────────────

    public function success(Request $request, string $purchase): View|RedirectResponse
    {
        $purchase = KyCardPurchase::where('uuid', $purchase)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Se Stripe: verifica sessione se non ancora completato
        if ($purchase->isPending() && $purchase->payment_method === 'stripe' && $request->has('session_id')) {
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $session = \Stripe\Checkout\Session::retrieve($request->query('session_id'));
                if ($session->payment_status === 'paid') {
                    $this->creditKy($purchase);
                }
            } catch (\Exception $e) {
                Log::warning('Stripe success verify', ['error' => $e->getMessage()]);
            }
            $purchase->refresh();
        }

        return view('portal.ky-card-success', [
            'purchase'       => $purchase,
            'pageTitle'      => $purchase->isCompleted() ? 'Ricarica completata!' : 'Ricarica in attesa',
            'activeNav'      => 'ky-cards',
            'currentAccount' => $purchase->account,
            'currentUser'    => $request->user(),
        ]);
    }

    // ── Stripe webhook ─────────────────────────────────────────────────────

    public function stripeWebhook(Request $request): \Illuminate\Http\Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, config('services.stripe.webhook_secret'));
        } catch (\Exception $e) {
            return response('Signature error', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session  = $event->data->object;
            $purchase = KyCardPurchase::where('stripe_checkout_session_id', $session->id)->first();
            if ($purchase && $purchase->isPending()) {
                $purchase->update(['stripe_payment_intent_id' => $session->payment_intent]);
                $this->creditKy($purchase);
            }
        }

        return response('OK', 200);
    }

    // ── Admin: conferma bonifico ───────────────────────────────────────────

    public function adminConfirmBankTransfer(Request $request, KyCardPurchase $purchase): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($purchase->isPendingBankTransfer(), 422, 'Acquisto non in attesa di bonifico.');

        $request->validate(['admin_notes' => 'nullable|string|max:500']);

        $purchase->update([
            'admin_notes'  => $request->input('admin_notes'),
            'confirmed_by' => $request->user()->id,
        ]);

        $this->creditKy($purchase);

        return redirect()->route('admin.ky-cards.pending-transfers')
            ->with('success', 'Bonifico confermato. ' . $purchase->ky_amount . ' KY accreditati.');
    }

    // ── Admin: rifiuta bonifico ────────────────────────────────────────────

    public function adminRejectBankTransfer(Request $request, KyCardPurchase $purchase): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($purchase->isPendingBankTransfer(), 422);

        $request->validate(['admin_notes' => 'nullable|string|max:500']);

        $purchase->update([
            'status'       => 'failed',
            'admin_notes'  => $request->input('admin_notes') ?: 'Bonifico non ricevuto o non conforme.',
            'confirmed_by' => $request->user()->id,
        ]);

        // Notifica utente
        try {
            $purchase->user->notify(new \App\Notifications\KyCardBankTransferRejected($purchase));
        } catch (\Exception) {}

        return redirect()->route('admin.ky-cards.pending-transfers')
            ->with('success', 'Bonifico rifiutato.');
    }

    // ── Admin: lista tutti gli ordini KYCard ──────────────────────────────

    public function adminOrders(Request $request): \Illuminate\View\View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $query = \App\Models\KyCardPurchase::with(['kyCard', 'user', 'account'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('method')) {
            $query->where('payment_method', $request->method);
        }

        $orders = $query->paginate(30)->withQueryString();

        $stats = [
            'total'    => \App\Models\KyCardPurchase::count(),
            'pending'  => \App\Models\KyCardPurchase::whereIn('status', ['pending','pending_bank_transfer'])->count(),
            'completed'=> \App\Models\KyCardPurchase::where('status','completed')->count(),
            'failed'   => \App\Models\KyCardPurchase::where('status','failed')->count(),
            'ky_total' => \App\Models\KyCardPurchase::where('status','completed')->sum('ky_amount'),
            'eur_total'=> \App\Models\KyCardPurchase::where('status','completed')->sum('price_eur_cents'),
        ];

        return view('admin.ky-cards.orders', compact('orders', 'stats'));
    }

    // ── Admin: riprocessa accredito fallito ────────────────────────────────

    public function adminRetryCredit(Request $request, \App\Models\KyCardPurchase $purchase): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($purchase->isFailed(), 422, 'Solo gli ordini falliti possono essere riprocessati.');

        // Rimetti in pending_bank_transfer per i bonifici, pending per gli altri
        $purchase->update(['status' => $purchase->payment_method === 'bank_transfer' ? 'pending_bank_transfer' : 'pending']);
        $this->creditKy($purchase);
        $purchase->refresh();

        $msg = $purchase->isCompleted()
            ? 'Accredito riuscito: +' . $purchase->ky_amount . ' KY accreditati.'
            : 'Accredito ancora fallito. Controlla i log.';

        return redirect()->route('admin.ky-cards.orders')->with('success', $msg);
    }

    // ── Accredita KY (condiviso) ───────────────────────────────────────────

    private function creditKy(KyCardPurchase $purchase): void
    {
        try {
            $systemAccount = \App\Models\Account::systemAccount();
            if (!$systemAccount) {
                Log::error('KyCard credit failed: system account not found', ['purchase' => $purchase->uuid]);
                $purchase->update(['status' => 'failed']);
                return;
            }

            $toAccount = $purchase->account;
            $amount    = (int) $purchase->ky_amount;

            // Creazione diretta (bypass check stato azienda e limiti):
            // il cliente ha gia' pagato in euro, l'accredito KY e' dovuto.
            // Pattern identico a NettingService.
            \Illuminate\Support\Facades\DB::transaction(function () use ($systemAccount, $toAccount, $amount, $purchase) {

                $bookedAt           = \Carbon\CarbonImmutable::now();
                $debitBalanceAfter  = $systemAccount->available_balance - $amount;
                $creditBalanceAfter = $toAccount->available_balance + $amount;

                $systemAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
                $toAccount->forceFill(['available_balance'     => $creditBalanceAfter])->save();

                $superAdminId = User::where('is_super_admin', true)->value('id') ?? 1;

                $transfer = \App\Models\Transfer::create([
                    'initiated_by'    => $superAdminId,
                    'from_account_id' => $systemAccount->id,
                    'to_account_id'   => $toAccount->id,
                    'amount'          => $amount,
                    'currency_code'   => $systemAccount->currency_code ?? 'KY',
                    'status'          => 'booked',
                    'kind'            => 'kycard_topup',
                    'idempotency_key' => 'kycard_' . $purchase->uuid,
                    'description'     => 'Ricarica KYCard: ' . ($purchase->kyCard->name ?? 'Card #' . $purchase->ky_card_id),
                    'booked_at'       => $bookedAt,
                ]);

                \App\Models\LedgerEntry::create([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $systemAccount->id,
                    'direction'    => 'debit',
                    'amount'       => $amount,
                    'balance_after'=> $debitBalanceAfter,
                    'posted_at'    => $bookedAt,
                    'meta'         => ['counterparty_account_id' => $toAccount->id],
                ]);

                \App\Models\LedgerEntry::create([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $toAccount->id,
                    'direction'    => 'credit',
                    'amount'       => $amount,
                    'balance_after'=> $creditBalanceAfter,
                    'posted_at'    => $bookedAt,
                    'meta'         => ['counterparty_account_id' => $systemAccount->id],
                ]);

                $purchase->update([
                    'status'       => 'completed',
                    'transfer_id'  => $transfer->id,
                    'completed_at' => $bookedAt,
                ]);
            });

            try {
                $purchase->user->notify(new \App\Notifications\KyCardCredited($purchase));
            } catch (\Exception) {}

        } catch (\Exception $e) {
            Log::error('KyCard credit failed', ['purchase' => $purchase->uuid, 'error' => $e->getMessage()]);
            $purchase->update(['status' => 'failed']);
        }
    }

    // ── PayPal helpers ─────────────────────────────────────────────────────

    private function paypalApiBase(): string
    {
        return config('services.paypal.mode', 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function getPaypalAccessToken(): string
    {
        $response = \Illuminate\Support\Facades\Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post($this->paypalApiBase() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return $response->json('access_token');
    }
}
