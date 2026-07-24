<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanPayment;
use App\Services\PlanUpgradeService;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Self-service upgrade piano: l'azienda vede i piani disponibili (definiti
 * liberamente dall'admin in /admin/piani) e puo' passare a un piano con
 * canone piu' alto pagando la differenza con Stripe, PayPal, bonifico, o KY
 * dal proprio conto (se il piano lo consente). I downgrade restano gestiti
 * solo dall'admin (vedi Admin\CompanyController::updatePlan) per evitare
 * rimborsi automatici.
 */
class PlanSubscriptionController extends Controller
{
    public function __construct(
        private readonly TransferBookingService $transferService,
        private readonly PlanUpgradeService $upgradeService,
    ) {}

    /** GET /azienda/piano */
    public function index(Request $request): View
    {
        $company = $this->authorizedCompany($request);

        $plans = Plan::where('is_active', true)->orderBy('display_order')->get();
        // Il piano attuale potrebbe essere disattivato dall'admin: mostralo comunque.
        if ($company->plan && ! $plans->contains('id', $company->plan_id)) {
            $plans->push($company->plan)->sortBy('display_order');
        }

        $recentPayments = PlanPayment::where('company_id', $company->id)
            ->with(['fromPlan', 'toPlan'])
            ->latest()
            ->take(5)
            ->get();

        return view('portal.plan.index', [
            'pageTitle'       => 'Il mio piano',
            'company'         => $company,
            'plans'           => $plans,
            'recentPayments'  => $recentPayments,
            'activeNav'       => 'plan',
        ]);
    }

    /** GET /azienda/piano/{plan}/checkout */
    public function checkout(Request $request, Plan $plan): View|RedirectResponse
    {
        $company = $this->authorizedCompany($request);

        $error = $this->validateUpgradeTarget($company, $plan);
        if ($error) {
            return redirect()->route('portal.plan.index')->with('portal_error', $error);
        }

        $amountCents = $company->upgradePriceDifference($plan);
        $account = $company->primaryBusinessAccount();

        return view('portal.plan.checkout', [
            'pageTitle'   => 'Passa al piano ' . $plan->name,
            'company'     => $company,
            'targetPlan'  => $plan,
            'amountCents' => $amountCents,
            'canPayKy'    => $plan->allow_ky_payment && $account && $account->saldoDisponibile() >= $amountCents,
            'account'     => $account,
            'activeNav'   => 'plan',
        ]);
    }

    // ── STRIPE ──────────────────────────────────────────────────────────────

    public function stripeCheckout(Request $request, Plan $plan): RedirectResponse
    {
        $company = $this->authorizedCompany($request);
        abort_unless(config('services.stripe.secret'), 503, 'Stripe non configurato.');

        $error = $this->validateUpgradeTarget($company, $plan);
        if ($error) {
            return redirect()->route('portal.plan.index')->with('portal_error', $error);
        }

        $amountCents = $company->upgradePriceDifference($plan);
        $payment = $this->createPayment($request, $company, $plan, $amountCents, 'stripe', 'pending');

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => 'eur',
                        'unit_amount'  => $amountCents,
                        'product_data' => ['name' => 'Upgrade piano KMoney: ' . $plan->name],
                    ],
                    'quantity' => 1,
                ]],
                'mode'                 => 'payment',
                'success_url'          => route('portal.plan.success', ['payment' => $payment->uuid]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'           => route('portal.plan.index'),
                'client_reference_id'  => $payment->uuid,
                'metadata'             => ['plan_payment_uuid' => $payment->uuid],
            ]);

            $payment->update(['stripe_checkout_session_id' => $session->id]);

            return redirect($session->url);
        } catch (\Exception $e) {
            $this->upgradeService->markFailed($payment, $e->getMessage());
            Log::error('Plan upgrade Stripe checkout error', ['error' => $e->getMessage(), 'payment' => $payment->uuid]);

            return redirect()->route('portal.plan.index')
                ->with('portal_error', 'Errore avvio pagamento Stripe. Riprova o scegli un altro metodo.');
        }
    }

    // ── PAYPAL ──────────────────────────────────────────────────────────────

    public function paypalCreateOrder(Request $request, Plan $plan): JsonResponse
    {
        $company = $this->authorizedCompany($request);
        abort_unless(config('services.paypal.client_id'), 503, 'PayPal non configurato.');

        $error = $this->validateUpgradeTarget($company, $plan);
        if ($error) {
            return response()->json(['error' => $error], 422);
        }

        $amountCents = $company->upgradePriceDifference($plan);
        $payment = $this->createPayment($request, $company, $plan, $amountCents, 'paypal', 'pending');

        try {
            $accessToken = $this->getPaypalAccessToken();
            $amount      = number_format($amountCents / 100, 2, '.', '');

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => ['currency_code' => 'EUR', 'value' => $amount],
                        'description' => 'Upgrade piano KMoney: ' . $plan->name,
                        'custom_id'   => $payment->uuid,
                    ]],
                    'application_context' => [
                        'return_url' => route('portal.plan.paypal-capture', ['payment' => $payment->uuid]),
                        'cancel_url' => route('portal.plan.index'),
                        'brand_name' => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $payment->update(['paypal_order_id' => $order['id'] ?? null]);

            return response()->json(['id' => $order['id'] ?? null, 'payment_uuid' => $payment->uuid]);
        } catch (\Exception $e) {
            $this->upgradeService->markFailed($payment, $e->getMessage());
            Log::error('Plan upgrade PayPal create order error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Errore PayPal. Riprova.'], 500);
        }
    }

    public function paypalCapture(Request $request, string $payment): RedirectResponse
    {
        $payment = PlanPayment::where('uuid', $payment)->firstOrFail();

        if (! $payment->isPending() || $payment->payment_method !== 'paypal') {
            return redirect()->route('portal.plan.index');
        }

        try {
            $accessToken = $this->getPaypalAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders/' . $payment->paypal_order_id . '/capture');

            $capture = $response->json();

            if (($capture['status'] ?? null) === 'COMPLETED') {
                $this->upgradeService->completePayment($payment);
            } else {
                $this->upgradeService->markFailed($payment, 'PayPal capture non completata.');
            }
        } catch (\Exception $e) {
            Log::error('Plan upgrade PayPal capture error', ['error' => $e->getMessage(), 'payment' => $payment->uuid]);
            $this->upgradeService->markFailed($payment, $e->getMessage());
        }

        return redirect()->route('portal.plan.success', ['payment' => $payment->fresh()->uuid]);
    }

    // ── BONIFICO ────────────────────────────────────────────────────────────

    public function bankTransfer(Request $request, Plan $plan): View|RedirectResponse
    {
        $company = $this->authorizedCompany($request);

        $error = $this->validateUpgradeTarget($company, $plan);
        if ($error) {
            return redirect()->route('portal.plan.index')->with('portal_error', $error);
        }

        $amountCents = $company->upgradePriceDifference($plan);
        $payment = $this->createPayment($request, $company, $plan, $amountCents, 'bank_transfer', 'pending_bank_transfer');

        return view('portal.plan.bank-transfer', [
            'pageTitle'      => 'Istruzioni bonifico',
            'payment'        => $payment,
            'targetPlan'     => $plan,
            'activeNav'      => 'plan',
            'bankIban'       => config('kmoney.bank_iban'),
            'bankName'       => config('kmoney.bank_name'),
            'bankBeneficiary'=> config('kmoney.bank_beneficiary'),
        ]);
    }

    // ── KY (interno al circuito) ───────────────────────────────────────────

    public function payWithKy(Request $request, Plan $plan): RedirectResponse
    {
        $company = $this->authorizedCompany($request);

        $error = $this->validateUpgradeTarget($company, $plan);
        if ($error) {
            return redirect()->route('portal.plan.index')->with('portal_error', $error);
        }

        abort_unless($plan->allow_ky_payment, 422, 'Questo piano non è pagabile in KY.');

        $account = $company->primaryBusinessAccount();
        abort_unless($account, 422, 'Nessun conto attivo trovato per la tua azienda.');

        $amountCents = $company->upgradePriceDifference($plan);
        $systemAccount = \App\Models\Account::systemAccount();
        abort_unless($systemAccount, 500, 'Conto di sistema non disponibile.');

        $payment = $this->createPayment($request, $company, $plan, $amountCents, 'ky', 'pending');

        try {
            $transfer = $this->transferService->book([
                'initiated_by'    => $request->user()->id,
                'from_account_id' => $account->id,
                'to_account_id'   => $systemAccount->id,
                'amount'          => $amountCents,
                'kind'            => 'portal_plan_upgrade',
                'description'     => 'Upgrade piano: ' . ($company->plan?->name ?? '—') . ' → ' . $plan->name,
                'idempotency_key' => 'planupgrade_' . $payment->uuid,
                'ip_address'      => $request->ip(),
            ]);

            $payment->update(['ky_transfer_id' => $transfer->id]);
            $this->upgradeService->completePayment($payment);

            return redirect()->route('portal.plan.success', ['payment' => $payment->fresh()->uuid]);
        } catch (\RuntimeException $e) {
            $this->upgradeService->markFailed($payment, $e->getMessage());

            return redirect()->route('portal.plan.index')->with('portal_error', $e->getMessage());
        }
    }

    // ── Esito ───────────────────────────────────────────────────────────────

    public function success(Request $request, string $payment): View
    {
        $payment = PlanPayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->with(['fromPlan', 'toPlan'])
            ->firstOrFail();

        if ($payment->isPending() && $payment->payment_method === 'stripe' && $request->has('session_id')) {
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $session = \Stripe\Checkout\Session::retrieve($request->query('session_id'));
                if ($session->payment_status === 'paid') {
                    $this->upgradeService->completePayment($payment);
                }
            } catch (\Exception $e) {
                Log::warning('Plan upgrade Stripe success verify', ['error' => $e->getMessage()]);
            }
            $payment->refresh();
        }

        return view('portal.plan.success', [
            'pageTitle' => $payment->isCompleted() ? 'Upgrade completato!' : 'Upgrade in attesa',
            'payment'   => $payment,
            'activeNav' => 'plan',
        ]);
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function authorizedCompany(Request $request): \App\Models\Company
    {
        $user = $request->user();
        $company = $user->company;
        abort_unless($company, 403, 'Nessuna azienda associata al tuo profilo.');
        abort_unless($user->canAccessMarketplace() || $user->is_super_admin, 403);

        return $company;
    }

    private function validateUpgradeTarget(\App\Models\Company $company, Plan $plan): ?string
    {
        if (! $plan->is_active) {
            return 'Questo piano non è più disponibile.';
        }
        if ($company->plan_id === $plan->id) {
            return 'Hai già questo piano.';
        }
        if ($plan->price_cents <= ($company->plan?->price_cents ?? 0)) {
            return 'Per passare a un piano di livello inferiore contatta l\'assistenza: i downgrade sono gestiti dall\'amministrazione.';
        }

        return null;
    }

    private function createPayment(Request $request, \App\Models\Company $company, Plan $plan, int $amountCents, string $method, string $status): PlanPayment
    {
        return PlanPayment::create([
            'company_id'    => $company->id,
            'user_id'       => $request->user()->id,
            'from_plan_id'  => $company->plan_id,
            'to_plan_id'    => $plan->id,
            'amount_cents'  => $amountCents,
            'status'        => $status,
            'payment_method'=> $method,
        ]);
    }

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
