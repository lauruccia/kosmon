<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TalksToPayPal;
use App\Models\RegistrationFeePayment;
use App\Models\User;
use App\Models\SystemSetting;
use App\Services\RegistrationFeeService;
use App\Services\StripeCheckoutVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

/**
 * Quota di iscrizione dei privati (31/08/2026).
 *
 * Quattro strade per la stessa cifra: carta, PayPal, bonifico e saldo KY.
 * Le prime tre ricalcano passo per passo l'acquisto di una KYCard — stesse
 * pagine, stessi stati, stesso webhook — perche' e' esattamente lo stesso
 * movimento di denaro. La quarta e' l'unica cosa nuova, e sta tutta in
 * RegistrationFeeService::payWithKy().
 */
class RegistrationFeeController extends Controller
{
    use TalksToPayPal;

    public function __construct(private readonly RegistrationFeeService $fees)
    {
    }

    // ── La pagina ───────────────────────────────────────────────────────────

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $this->fees->isDueFor($user)) {
            return redirect()->route('portal.dashboard');
        }

        $settings = $this->fees->settings();
        $account  = $this->fees->accountFor($user);

        return view('portal.registration-fee', [
            'pageTitle'   => 'Quota di iscrizione',
            'activeNav'   => 'registration-fee',
            'amountCents' => $this->fees->amountDueFor($user),
            'metodi'      => $settings->registrationFeeMethods(),
            'currentUser' => $user,
            'currentAccount' => $account,
            'saldo'       => (int) ($account?->available_balance ?? 0),
        ]);
    }

    // ── Saldo KY ────────────────────────────────────────────────────────────

    public function payWithKy(Request $request): RedirectResponse
    {
        try {
            $payment = $this->fees->payWithKy($request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.registration-fee.show')
                ->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.registration-fee.success', ['payment' => $payment->uuid]);
    }

    // ── Stripe ──────────────────────────────────────────────────────────────

    public function stripeCheckout(Request $request): RedirectResponse
    {
        if (! config('services.stripe.secret')) {
            // Un 503 nudo qui e' una pagina bianca senza spiegazione: meglio
            // rimandarlo indietro con il motivo e gli altri metodi sotto gli
            // occhi.
            return redirect()->route('portal.registration-fee.show')
                ->with('portal_error', 'Il pagamento con carta non è al momento disponibile. Scegli un altro metodo o riprova più tardi.');
        }

        try {
            $payment = $this->fees->startPayment($request->user(), RegistrationFeePayment::METHOD_STRIPE);
        } catch (RuntimeException $e) {
            return redirect()->route('portal.registration-fee.show')->with('portal_error', $e->getMessage());
        }

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    // Prezzo definito qui e non da uno stripe_price_id: la
                    // quota la decide l'admin dal backoffice e puo' cambiare,
                    // mentre un price_id andrebbe ricreato su Stripe a mano
                    // ogni volta (e sarebbe una seconda verita' sull'importo).
                    'price_data' => [
                        'currency'     => 'eur',
                        'unit_amount'  => (int) $payment->amount_eur_cents,
                        'product_data' => ['name' => 'Quota di iscrizione KMoney'],
                    ],
                    'quantity' => 1,
                ]],
                'mode'                => 'payment',
                'success_url'         => route('portal.registration-fee.success', ['payment' => $payment->uuid]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'          => route('portal.registration-fee.show'),
                'client_reference_id' => $payment->uuid,
                'metadata'            => ['registration_fee_uuid' => $payment->uuid],
            ]);

            $payment->update(['stripe_checkout_session_id' => $session->id]);

            if (empty($session->url)) {
                throw new RuntimeException('Stripe non ha restituito un indirizzo di pagamento.');
            }

            return redirect($session->url);
        } catch (\Throwable $e) {
            // \Throwable e NON \Exception: se la libreria Stripe non e'
            // installata sul server, la chiamata qui sopra solleva un \Error,
            // che un catch(\Exception) lascia passare. Il risultato e' quello
            // visto in produzione il 01/09/2026 — pagina di errore del server,
            // nessun messaggio, e la riga del pagamento che resta "pending"
            // per sempre perche' markFailed() non viene mai raggiunto.
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota iscrizione: avvio Stripe fallito', [
                'payment' => $payment->uuid,
                'error'   => $e->getMessage(),
                'class'   => $e::class,
            ]);

            return redirect()->route('portal.registration-fee.show')
                ->with('portal_error', 'Errore nell\'avvio del pagamento con carta. Riprova o scegli un altro metodo.');
        }
    }

    // ── PayPal ──────────────────────────────────────────────────────────────

    public function paypalCreateOrder(Request $request): JsonResponse
    {
        abort_unless(config('services.paypal.client_id'), 503, 'PayPal non configurato.');

        try {
            $payment = $this->fees->startPayment($request->user(), RegistrationFeePayment::METHOD_PAYPAL);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        try {
            $accessToken = $this->getPaypalAccessToken();

            $response = Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => ['currency_code' => 'EUR', 'value' => number_format($payment->amount_eur, 2, '.', '')],
                        'description' => 'Quota di iscrizione KMoney',
                        'custom_id'   => $payment->uuid,
                    ]],
                    'application_context' => [
                        'return_url'  => route('portal.registration-fee.paypal-capture', ['payment' => $payment->uuid]),
                        'cancel_url'  => route('portal.registration-fee.show'),
                        'brand_name'  => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $payment->update(['paypal_order_id' => $order['id'] ?? null]);

            return response()->json(['id' => $order['id'] ?? null, 'payment_uuid' => $payment->uuid]);
        } catch (\Exception $e) {
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota iscrizione: creazione ordine PayPal fallita', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Errore PayPal. Riprova.'], 500);
        }
    }

    public function paypalCapture(Request $request, string $payment): RedirectResponse
    {
        $payment = RegistrationFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $payment->isPending() || $payment->payment_method !== RegistrationFeePayment::METHOD_PAYPAL) {
            return redirect()->route('portal.registration-fee.success', ['payment' => $payment->uuid]);
        }

        try {
            $accessToken = $this->getPaypalAccessToken();

            $capture = Http::withToken($accessToken)
                ->post($this->paypalApiBase() . '/v2/checkout/orders/' . $payment->paypal_order_id . '/capture')
                ->json();

            if (($capture['status'] ?? null) === 'COMPLETED') {
                $this->fees->completeEuroPayment($payment);
            } else {
                $this->fees->markFailed($payment, 'PayPal: stato ' . ($capture['status'] ?? 'sconosciuto'));
            }
        } catch (\Exception $e) {
            Log::error('Quota iscrizione: capture PayPal fallita', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            $this->fees->markFailed($payment, $e->getMessage());
        }

        return redirect()->route('portal.registration-fee.success', ['payment' => $payment->fresh()->uuid]);
    }

    // ── Bonifico ────────────────────────────────────────────────────────────

    public function bankTransfer(Request $request): View|RedirectResponse
    {
        try {
            $payment = $this->fees->startPayment($request->user(), RegistrationFeePayment::METHOD_BANK_TRANSFER);
        } catch (RuntimeException $e) {
            return redirect()->route('portal.registration-fee.show')->with('portal_error', $e->getMessage());
        }

        return view('portal.registration-fee-bank-transfer', [
            'pageTitle'       => 'Istruzioni per il bonifico',
            'activeNav'       => 'registration-fee',
            'payment'         => $payment,
            'currentUser'     => $request->user(),
            'currentAccount'  => $this->fees->accountFor($request->user()),
            'bankIban'        => config('kmoney.bank_iban'),
            'bankName'        => config('kmoney.bank_name'),
            'bankBeneficiary' => config('kmoney.bank_beneficiary'),
        ]);
    }

    // ── Esito ───────────────────────────────────────────────────────────────

    public function success(Request $request, string $payment): View
    {
        $payment = RegistrationFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Stripe: la sessione da verificare e' quella SALVATA sul pagamento,
        // mai quella che arriva in ?session_id= — vedi StripeCheckoutVerifier
        // e il riuso del link di pagamento chiuso il 28/08.
        if ($payment->isPending() && $payment->payment_method === RegistrationFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'regfee:' . $payment->uuid,
            );

            if ($pagata) {
                $this->fees->completeEuroPayment($payment);
            }

            $payment->refresh();
        }

        return view('portal.registration-fee-success', [
            'pageTitle'      => $payment->isCompleted() ? 'Quota saldata' : 'Quota in attesa',
            'activeNav'      => 'registration-fee',
            'payment'        => $payment,
            'currentUser'    => $request->user(),
            'currentAccount' => $this->fees->accountFor($request->user()),
        ]);
    }

    // ── Backoffice ──────────────────────────────────────────────────────────

    public function adminIndex(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $stato = $request->string('stato')->toString();

        $payments = RegistrationFeePayment::query()
            ->with(['user', 'confirmer'])
            ->when($stato !== '', fn ($q) => $q->where('status', $stato))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.registration-fees', [
            'pageTitle' => 'Quote di iscrizione',
            'activeNav' => 'registration-fees',
            'payments'  => $payments,
            'stato'     => $stato,
            'settings'  => SystemSetting::userLimitDefaults(),
        ]);
    }

    /**
     * Le impostazioni della quota vivono su una rotta propria e non nel form
     * dei limiti di default: salvare quello riscrive i limiti di TUTTI gli
     * utenti (vedi CreditLimitController::updateLimitDefaults, che li fissa
     * uno per uno), e cambiare l'importo della quota non deve trascinarsi
     * dietro una migrazione di massa.
     */
    public function adminUpdateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        if ($request->filled('registration_fee_amount')) {
            $request->merge(['registration_fee_amount' => str_replace(',', '.', (string) $request->input('registration_fee_amount'))]);
        }

        $validated = $request->validate([
            'registration_fee_enabled'               => ['nullable', 'boolean'],
            'registration_fee_amount'                => ['required', 'numeric', 'min:0'],
            'registration_fee_stripe_enabled'        => ['nullable', 'boolean'],
            'registration_fee_paypal_enabled'        => ['nullable', 'boolean'],
            'registration_fee_bank_transfer_enabled' => ['nullable', 'boolean'],
            'registration_fee_ky_enabled'            => ['nullable', 'boolean'],
        ]);

        $importo = ky_to_cents($validated['registration_fee_amount']);
        $attiva  = $request->boolean('registration_fee_enabled');

        // Accendere la quota lasciando tutti i metodi spenti significa
        // bloccare ogni nuovo iscritto su una pagina senza bottoni: e' un
        // errore di configurazione, non una scelta, e va fermato qui.
        $metodiAccesi = $request->boolean('registration_fee_stripe_enabled')
            || $request->boolean('registration_fee_paypal_enabled')
            || $request->boolean('registration_fee_bank_transfer_enabled')
            || $request->boolean('registration_fee_ky_enabled');

        if ($attiva && ! $metodiAccesi) {
            return back()->withInput()->withErrors([
                'registration_fee_enabled' => 'Per attivare la quota serve almeno un metodo di pagamento acceso.',
            ]);
        }

        if ($attiva && $importo <= 0) {
            return back()->withInput()->withErrors([
                'registration_fee_amount' => "Per attivare la quota serve un importo maggiore di zero.",
            ]);
        }

        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_enabled'               => $attiva,
            'registration_fee_amount_cents'          => $importo,
            'registration_fee_stripe_enabled'        => $request->boolean('registration_fee_stripe_enabled'),
            'registration_fee_paypal_enabled'        => $request->boolean('registration_fee_paypal_enabled'),
            'registration_fee_bank_transfer_enabled' => $request->boolean('registration_fee_bank_transfer_enabled'),
            'registration_fee_ky_enabled'            => $request->boolean('registration_fee_ky_enabled'),
        ])->save();

        return back()->with('portal_success', 'Impostazioni della quota di iscrizione aggiornate.');
    }

    public function adminConfirmBankTransfer(Request $request, RegistrationFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $this->fees->completeEuroPayment($payment, $request->user()->id);

        return redirect()->route('admin.registration-fees.index')
            ->with('portal_success', 'Bonifico confermato: i KY sono stati accreditati.');
    }

    /**
     * Annulla una quota gia' saldata: storno, quota di nuovo dovuta, fido
     * aggiuntivo tolto. Vive qui e non nella cancellazione dei movimenti
     * perche' quella ripristina i saldi e nient'altro — vedi il commento in
     * RegistrationFeeService::cancel().
     */
    public function adminCancel(Request $request, RegistrationFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->fees->cancel(
                $payment,
                $request->user(),
                $validated['admin_notes'] ?? null,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('admin.registration-fees.index')->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.registration-fees.index')
            ->with('portal_success', 'Quota annullata: movimento stornato, quota di nuovo da saldare e fido aggiuntivo rimosso.');
    }

    /**
     * Mette la quota in carico a un utente che non l'ha pagata, compresi i
     * privati iscritti prima che la quota esistesse.
     */
    public function adminRequest(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        try {
            $importo = $this->fees->requestFrom($user, $request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.users.show', $user)->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', 'Quota di ' . ky_format($importo) . ' KY richiesta a ' . $user->name . ': è stato avvisato per email e in notifica.');
    }

    public function adminRejectBankTransfer(Request $request, RegistrationFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->fees->markFailed($payment, $validated['admin_notes'] ?? 'Bonifico non ricevuto.');

        return redirect()->route('admin.registration-fees.index')
            ->with('portal_success', 'Pagamento rifiutato.');
    }
}
