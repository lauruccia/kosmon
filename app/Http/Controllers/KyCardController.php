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
            'pageTitle'  => 'Ricarica KMoney',
            'activeNav'  => 'ky-cards',
            'redirectTo' => $this->redirectTargetFromRequest($request),
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
            'card'       => $kyCard,
            'pageTitle'  => 'Acquista ' . $kyCard->name,
            'activeNav'  => 'ky-cards',
            'redirectTo' => $this->redirectTargetFromRequest($request),
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

        // redirect_to (facoltativo, hidden field nel form del checkout): pagina da
        // cui l'utente e' arrivato per saldo insufficiente (es. shop, richiesta di
        // pagamento) — vedi redirectTargetFromRequest(). La riportiamo dentro
        // success_url cosi' success() puo' riportarcelo in automatico a pagamento
        // riuscito.
        $redirectTo = $this->redirectTargetFromRequest($request);

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

            $successUrl = route('portal.ky-cards.success', ['purchase' => $purchase->uuid]) . '?session_id={CHECKOUT_SESSION_ID}';
            if ($redirectTo) {
                $successUrl .= '&redirect_to=' . urlencode($redirectTo);
            }

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [['price' => $kyCard->stripe_price_id, 'quantity' => 1]],
                'mode'                 => 'payment',
                'success_url'          => $successUrl,
                'cancel_url'           => route('portal.ky-cards.index', $redirectTo ? ['redirect_to' => $redirectTo] : []),
                'client_reference_id'  => $purchase->uuid,
                'metadata'             => ['purchase_uuid' => $purchase->uuid],
            ]);

            $purchase->update(['stripe_checkout_session_id' => $session->id]);

            return redirect($session->url);

        } catch (\Exception $e) {
            $purchase->update(['status' => 'failed']);
            Log::error('Stripe checkout error', ['error' => $e->getMessage(), 'purchase' => $purchase->uuid]);
            return redirect()->route('portal.ky-cards.index', $redirectTo ? ['redirect_to' => $redirectTo] : [])
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

        // redirect_to arriva come query string sull'URL fetch() chiamato dal
        // bottone PayPal — vedi ky-card-checkout.blade.php — non da un campo form.
        $redirectTo = $this->redirectTargetFromRequest($request);

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

            $captureReturnUrl = route('portal.ky-cards.paypal-capture', ['purchase' => $purchase->uuid])
                . ($redirectTo ? '?redirect_to=' . urlencode($redirectTo) : '');

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => ['currency_code' => 'EUR', 'value' => $amount],
                        'description' => 'KYCard: ' . $kyCard->name . ' — ' . $kyCard->ky_total . ' KY',
                        'custom_id'   => $purchase->uuid,
                    ]],
                    'application_context' => [
                        'return_url' => $captureReturnUrl,
                        'cancel_url' => route('portal.ky-cards.index', $redirectTo ? ['redirect_to' => $redirectTo] : []),
                        'brand_name' => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $purchase->update(['paypal_order_id' => $order['id']]);

            return response()->json(['id' => $order['id'], 'purchase_uuid' => $purchase->uuid, 'redirect_to' => $redirectTo]);

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

        $redirectTo = $this->redirectTargetFromRequest($request);

        return redirect()->route('portal.ky-cards.success', array_filter([
            'purchase'    => $purchase->uuid,
            'redirect_to' => $redirectTo,
        ]));
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
            // Il bonifico non accredita subito (verifica manuale entro 1-2
            // giorni lavorativi): mostriamo solo un link "torna al pagamento"
            // manuale, niente redirect automatico — i KY non ci sono ancora.
            'redirectTo'     => $this->redirectTargetFromRequest($request),
        ]);
    }

    // ── Pagamento riuscito ─────────────────────────────────────────────────

    public function success(Request $request, string $purchase): View|RedirectResponse
    {
        $purchase = KyCardPurchase::where('uuid', $purchase)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Se Stripe: verifica la sessione SALVATA SULL'ACQUISTO, mai quella che
        // arriva in ?session_id= (vedi StripeCheckoutVerifier: un session_id
        // gia' pagato, incollato su acquisti nuovi, accreditava KY all'infinito).
        // Accredita solo se la sessione risulta pagata, riferita a questo
        // acquisto e dell'importo esatto.
        // NB (01/09/2026): non `isPending()` ma "non e' ne' chiusa ne'
        // disfatta". Una riga finita `failed` — accredito andato storto, o
        // tentativo dato per abbandonato — deve poter essere ancora
        // accreditata se Stripe dice che l'incasso c'e' stato. La prova la da'
        // StripeCheckoutVerifier, non lo stato della riga.
        if (! $purchase->isCompleted() && ! $purchase->isRefunded() && $purchase->payment_method === 'stripe') {
            $pagata = app(\App\Services\StripeCheckoutVerifier::class)->isPaidFor(
                $purchase->stripe_checkout_session_id,
                (int) $purchase->price_eur_cents,
                $purchase->uuid,
                'kycard:' . $purchase->uuid,
            );

            if ($pagata) {
                $this->creditKy($purchase);
            }

            $purchase->refresh();
        }

        return view('portal.ky-card-success', [
            'purchase'       => $purchase,
            'pageTitle'      => $purchase->isCompleted() ? 'Ricarica completata!' : 'Ricarica in attesa',
            'activeNav'      => 'ky-cards',
            'currentAccount' => $purchase->account,
            'currentUser'    => $request->user(),
            // Se l'utente e' arrivato qui da un pagamento bloccato per saldo
            // insufficiente (shop, richiesta di pagamento — vedi
            // redirectTargetFromRequest()), e la ricarica e' andata a buon fine
            // subito (Stripe/PayPal), lo riportiamo li' in automatico invece di
            // lasciarlo sulla pagina "ricarica completata" a cercare la strada
            // del ritorno da solo.
            'redirectTo'     => $this->redirectTargetFromRequest($request),
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

            $verifier = app(\App\Services\StripeCheckoutVerifier::class);

            // NB (01/09/2026): la stessa tolleranza gia' adottata per la quota
            // di iscrizione vale ora per TUTTI E QUATTRO gli incassi di questo
            // endpoint. Non si guarda piu' `isPending()` ma "non e' ne' chiusa
            // ne' rimborsata": una riga finita `failed` — accredito andato
            // storto, o tentativo dato per abbandonato — veniva saltata, e chi
            // aveva pagato restava senza niente per sempre.
            //
            // NON E' UN ALLENTAMENTO DELLA DIFESA: a decidere resta
            // `sessionMatches()`, che chiede a Stripe se quella sessione e'
            // stata davvero incassata, dell'importo esatto e per QUESTO
            // pagamento. Senza quella prova non si accredita niente, qualunque
            // sia lo stato della riga. Cio' che lo stato ancora vieta e' il
            // caso in cui la risposta e' gia' stata data: `completed` (fatto)
            // e `refunded` (disfatto apposta).
            $purchase = KyCardPurchase::where('stripe_checkout_session_id', $session->id)->first();
            if ($purchase && ! $purchase->isCompleted() && ! $purchase->isRefunded()) {
                $purchase->update(['stripe_payment_intent_id' => $session->payment_intent]);

                // checkout.session.completed puo' arrivare anche NON pagata
                // (metodi asincroni): l'evento e' firmato, ma va comunque
                // controllato stato e importo prima di creare moneta.
                if ($verifier->sessionMatches($session, (int) $purchase->price_eur_cents, $purchase->uuid, 'kycard-webhook:' . $purchase->uuid)) {
                    $this->creditKy($purchase);
                }
            }

            // Stesso endpoint webhook condiviso anche per gli upgrade piano
            // (vedi PlanSubscriptionController::stripeCheckout) — un unico
            // endpoint Stripe da configurare per tutto il circuito.
            // Stessa tolleranza (01/09/2026).
            $planPayment = \App\Models\PlanPayment::where('stripe_checkout_session_id', $session->id)->first();
            if ($planPayment && ! $planPayment->isCompleted() && ! $planPayment->isCancelled()) {
                $planPayment->update(['stripe_payment_intent_id' => $session->payment_intent]);

                if ($verifier->sessionMatches($session, (int) $planPayment->amount_cents, $planPayment->uuid, 'plan-webhook:' . $planPayment->uuid)) {
                    app(\App\Services\PlanUpgradeService::class)->completePayment($planPayment);
                }
            }

            // Terzo incasso sullo stesso endpoint: la quota di iscrizione dei
            // privati (31/08/2026, vedi RegistrationFeeController). Anche qui
            // il webhook e la pagina di successo possono arrivare insieme:
            // l'accredito e' idempotente sulla idempotency_key del transfer,
            // non sul solo stato del pagamento.
            //
            // NB (01/09/2026): qui NON si guarda `isPending()` ma "non e' ne'
            // saldata ne' annullata". La differenza conta: una riga che era
            // finita `failed` — perche' l'accredito era andato storto, o
            // perche' il tentativo era stato dato per abbandonato e scaduto —
            // con `isPending()` veniva saltata, e chi aveva pagato restava
            // senza KY per sempre. Non e' un allentamento della difesa: a
            // decidere resta `sessionMatches()`, che chiede a Stripe se
            // quella sessione e' stata davvero incassata, dell'importo esatto
            // e per QUESTO pagamento. Senza quella prova non si accredita
            // niente, qualunque sia lo stato della riga.
            $feePayment = \App\Models\RegistrationFeePayment::where('stripe_checkout_session_id', $session->id)->first();
            if ($feePayment && ! $feePayment->isCompleted() && ! $feePayment->isCancelled()) {
                $feePayment->update(['stripe_payment_intent_id' => $session->payment_intent]);

                if ($verifier->sessionMatches($session, (int) $feePayment->amount_eur_cents, $feePayment->uuid, 'regfee-webhook:' . $feePayment->uuid)) {
                    app(\App\Services\RegistrationFeeService::class)->completeEuroPayment($feePayment);
                }
            }

            // Quarto incasso: la quota per il codice agente (31/08/2026).
            $agentFee = \App\Models\AgentCodeFeePayment::where('stripe_checkout_session_id', $session->id)->first();
            if ($agentFee && ! $agentFee->isCompleted() && ! $agentFee->isCancelled()) {
                $agentFee->update(['stripe_payment_intent_id' => $session->payment_intent]);

                if ($verifier->sessionMatches($session, (int) $agentFee->amount_eur_cents, $agentFee->uuid, 'agentcode-webhook:' . $agentFee->uuid)) {
                    app(\App\Services\AgentCodeFeeService::class)->completeEuroPayment($agentFee);
                }
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
            ->with('success', 'Bonifico confermato. ' . ky_format($purchase->ky_amount) . ' KY accreditati.');
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
            ? 'Accredito riuscito: +' . ky_format($purchase->ky_amount) . ' KY accreditati.'
            : 'Accredito ancora fallito. Controlla i log.';

        return redirect()->route('admin.ky-cards.orders')->with('success', $msg);
    }

    // ── Accredita KY (condiviso) ───────────────────────────────────────────

    private function creditKy(KyCardPurchase $purchase): void
    {
        // (B) Guard idempotenza: se l'accredito e' gia' avvenuto non rifare nulla.
        if ($purchase->isCompleted() || $purchase->transfer_id) {
            // I KY ci sono gia'. I punti MLM pero' potrebbero mancare: se la
            // richiesta che ha creato il transfer si e' interrotta prima di
            // assegnarli, oggi non li recupererebbe piu' nessuno (l'admin che
            // rilancia l'accredito si fermava proprio qui). La chiamata e'
            // idempotente sulla sorgente, quindi non puo' creare doppioni.
            $this->awardMlmDepositPoints($purchase);

            return;
        }

        try {
            $systemAccount = \App\Models\Account::systemAccount();
            if (!$systemAccount) {
                Log::error('KyCard credit failed: system account not found', ['purchase' => $purchase->uuid]);
                $purchase->update(['status' => 'failed']);
                return;
            }

            $amount         = (int) $purchase->ky_amount;
            $idempotencyKey = 'kycard_' . $purchase->uuid;

            // Creazione diretta (bypass check stato azienda e limiti):
            // il cliente ha gia' pagato in euro, l'accredito KY e' dovuto.

            // Vero solo per la richiesta che ha DAVVERO registrato il transfer.
            // Nella corsa webhook Stripe + pagina success entrambe entrano qui:
            // il lock sul conto madre le mette in fila e la seconda trova il
            // transfer gia' scritto, ma il suo `return` esce dalla CLOSURE, non
            // dal metodo — l'esecuzione riprendeva sotto e arrivava lo stesso
            // ai punti MLM, assegnati cosi' DUE volte (qualifica gonfiata e
            // base commissionabile pagata due volte). Fino al 28/08 non poteva
            // succedere: il webhook rispondeva 419 e la strada era una sola.
            $accreditoCreatoQui = false;

            \Illuminate\Support\Facades\DB::transaction(function () use ($systemAccount, $purchase, $amount, $idempotencyKey, &$accreditoCreatoQui) {

                // (A) Lock pessimistico: blocco prima il conto madre — questo serializza
                // tutti gli accrediti KYCard concorrenti ed evita lost-update sul saldo KNM.
                $fromAccount = \App\Models\Account::whereKey($systemAccount->id)->lockForUpdate()->first();

                // (B) Se il transfer esiste gia' (es. corsa webhook Stripe + pagina success),
                // allinea lo stato senza riaccreditare.
                $existing = \App\Models\Transfer::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $purchase->update([
                        'status'       => 'completed',
                        'transfer_id'  => $existing->id,
                        'completed_at' => $existing->booked_at ?? $existing->created_at,
                    ]);
                    return;
                }

                $toAccount = \App\Models\Account::whereKey($purchase->account_id)->lockForUpdate()->first();

                $bookedAt           = \Carbon\CarbonImmutable::now();
                $debitBalanceAfter  = $fromAccount->available_balance - $amount;
                $creditBalanceAfter = $toAccount->available_balance + $amount;

                $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
                $toAccount->forceFill(['available_balance'   => $creditBalanceAfter])->save();

                $superAdminId = User::where('is_super_admin', true)->value('id') ?? 1;

                $transfer = \App\Models\Transfer::create([
                    'initiated_by'    => $purchase->confirmed_by ?? $superAdminId,
                    'from_account_id' => $fromAccount->id,
                    'to_account_id'   => $toAccount->id,
                    'amount'          => $amount,
                    'currency_code'   => $fromAccount->currency_code ?? 'KY',
                    'status'          => 'booked',
                    'kind'            => 'kycard_topup',
                    'idempotency_key' => $idempotencyKey,
                    'description'     => 'Ricarica KYCard: ' . ($purchase->kyCard->name ?? 'Card #' . $purchase->ky_card_id),
                    'booked_at'       => $bookedAt,
                ]);

                $accreditoCreatoQui = true;

                \App\Models\LedgerEntry::create([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $fromAccount->id,
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
                    'meta'         => ['counterparty_account_id' => $fromAccount->id],
                ]);

                $purchase->update([
                    'status'       => 'completed',
                    'transfer_id'  => $transfer->id,
                    'completed_at' => $bookedAt,
                ]);

                // (D) AuditLog dell'emissione dal conto madre.
                \App\Models\AuditLog::create([
                    'actor_user_id'  => $purchase->confirmed_by ?? $superAdminId,
                    'event'          => 'kycard.credited',
                    'auditable_type' => \App\Models\Transfer::class,
                    'auditable_id'   => $transfer->id,
                    'ip_address'     => request()->ip(),
                    'context'        => [
                        'purchase_uuid'   => $purchase->uuid,
                        'ky_card_id'      => $purchase->ky_card_id,
                        'from_account_id' => $fromAccount->id,
                        'to_account_id'   => $toAccount->id,
                        'amount'          => $amount,
                        'payment_method'  => $purchase->payment_method,
                    ],
                ]);
            });

            $purchase->refresh();

            if ($accreditoCreatoQui) {
                $this->awardMlmDepositPoints($purchase);
            }

            try {
                if ($purchase->isCompleted()) {
                    $purchase->user->notify(new \App\Notifications\KyCardCredited($purchase));
                }
            } catch (\Exception) {}

        } catch (\Exception $e) {
            Log::error('KyCard credit failed', ['purchase' => $purchase->uuid, 'error' => $e->getMessage()]);
            // (B) Non sovrascrivere uno stato 'completed' gia' raggiunto da una corsa concorrente.
            if (!optional($purchase->fresh())->isCompleted()) {
                $purchase->update(['status' => 'failed']);
            }
        }
    }

    /**
     * MLM: assegna i punti deposito al cliente/agente risolto (fascia EUR).
     *
     * Isolato in try/catch proprio: l'accredito KY e' gia' avvenuto e un
     * errore qui non deve intaccare la risposta all'utente. Saltato
     * interamente se MLM e' disattivato su questa installazione
     * (config('kmoney.mlm_enabled')).
     *
     * Chiamato da UN SOLO punto per ogni accredito riuscito — chi registra il
     * transfer — piu' il recupero sugli acquisti gia' completati. La difesa
     * contro il doppione non e' pero' qui: sta in MlmPointsService (controllo
     * sulla sorgente) e nell'indice UNIQUE del database. Tre livelli, perche'
     * questo e' il solo che una modifica futura del flusso di pagamento
     * potrebbe scavalcare senza accorgersene.
     */
    private function awardMlmDepositPoints(KyCardPurchase $purchase): void
    {
        if (! config('kmoney.mlm_enabled') || ! $purchase->isCompleted()) {
            return;
        }

        try {
            app(\App\Services\MlmPointsService::class)->awardDepositPoints(
                $purchase->user,
                (int) $purchase->price_eur_cents,
                $purchase->transfer_id,
                $purchase->kyCard, // i punti sono definiti sulla card acquistata (22/07)
            );
        } catch (\Exception $mlmException) {
            Log::error('MLM points award failed', ['purchase' => $purchase->uuid, 'error' => $mlmException->getMessage()]);
        }
    }

    // ── Redirect al pagamento di partenza dopo ricarica ────────────────────

    /**
     * Legge redirect_to dalla request (query string su GET/fetch, campo
     * hidden nei form POST — Request::input() copre entrambi i casi) e lo
     * valida come path locale prima di riusarlo altrove nel flusso.
     *
     * Serve a chi arriva qui da un pagamento bloccato per saldo
     * insufficiente (es. "Ricarica ora" su un prodotto shop o su una
     * richiesta di pagamento — vedi shop-show.blade.php e
     * pay-request.blade.php) per essere riportato automaticamente li' a
     * ricarica completata, invece di dover ritrovare da solo la pagina da
     * cui era partito. Passato esplicitamente attraverso tutto il giro
     * (query string / campo hidden / success_url e return_url dei
     * provider) invece che in sessione: evita ambiguita' tra ricariche
     * concorrenti in tab diverse, stesso motivo per cui e' preferibile a
     * un semplice session()->put() globale.
     */
    private function redirectTargetFromRequest(Request $request): ?string
    {
        return $this->sanitizeLocalRedirectTarget($request->input('redirect_to'));
    }

    /**
     * Accetta solo path locali relativi (mai URL assoluti/esterni), per
     * evitare un open-redirect tramite il parametro redirect_to.
     */
    private function sanitizeLocalRedirectTarget(mixed $target): ?string
    {
        if (! is_string($target)) {
            return null;
        }

        $target = trim($target);

        if ($target === '' || ! str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '://')) {
            return null;
        }

        return $target;
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
