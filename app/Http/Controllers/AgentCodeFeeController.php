<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TalksToPayPal;
use App\Models\AgentCodeFeePayment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AgentCodeFeeService;
use App\Services\PayPalOrderVerifier;
use App\Services\StripeCheckoutVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

/**
 * Quota per il codice agente (31/08/2026): la pagina dove chi ha la richiesta
 * approvata paga prima di poter firmare il contratto di nomina.
 *
 * Ricalca RegistrationFeeController — stessi stati, stesso webhook, stessa
 * verifica Stripe — con due differenze: c'e' la rinuncia, e il pagamento in
 * euro non accredita nessun KY (vedi AgentCodeFeeService).
 */
class AgentCodeFeeController extends Controller
{
    use TalksToPayPal;

    public function __construct(private readonly AgentCodeFeeService $fees)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $this->fees->isDueFor($user)) {
            // Quota saldata (o mai dovuta): se deve ancora firmare, la sua
            // strada e' il contratto, non questa pagina.
            return $user->mlmAgentAwaitingContract()
                ? redirect()->route('portal.mlm.agent-contract.show')
                : redirect()->route('portal.dashboard');
        }

        $settings = $this->fees->settings();
        $account  = $this->fees->accountFor($user);

        return view('portal.mlm.agent-code-fee', [
            'pageTitle'      => 'Quota per il codice agente',
            'activeNav'      => 'mlm-agent-request',
            'amountCents'    => $this->fees->amountDueFor($user),
            'metodi'         => $settings->agentCodeFeeMethods(),
            'currentUser'    => $user,
            'currentAccount' => $account,
            'saldo'          => (int) ($account?->available_balance ?? 0),
            // Chi ha gia' chiesto il bonifico non deve ritrovare i quattro
            // bottoni come se non avesse fatto niente: vede il bonifico in
            // corso, e da li' o rivede i dati o cambia metodo (02/09/2026).
            'bonifico'       => $this->fees->pendingBankTransferFor($user),
        ]);
    }

    // ── Rinuncia ────────────────────────────────────────────────────────────

    public function giveUp(Request $request): RedirectResponse
    {
        try {
            $this->fees->giveUp($request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.dashboard')->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.dashboard')
            ->with('portal_success', 'Hai rinunciato a diventare agente. Il tuo conto è tornato pienamente operativo e potrai ricandidarti quando vorrai.');
    }

    // ── Saldo KY ────────────────────────────────────────────────────────────

    public function payWithKy(Request $request): RedirectResponse
    {
        try {
            $payment = $this->fees->payWithKy($request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.mlm.agent-code-fee.show')
                ->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.mlm.agent-code-fee.success', ['payment' => $payment->uuid]);
    }

    // ── Stripe ──────────────────────────────────────────────────────────────

    public function stripeCheckout(Request $request): RedirectResponse
    {
        abort_unless(config('services.stripe.secret'), 503, 'Stripe non configurato.');

        try {
            $payment = $this->fees->startPayment($request->user(), AgentCodeFeePayment::METHOD_STRIPE);
        } catch (RuntimeException $e) {
            return redirect()->route('portal.mlm.agent-code-fee.show')->with('portal_error', $e->getMessage());
        }

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => 'eur',
                        'unit_amount'  => (int) $payment->amount_eur_cents,
                        'product_data' => ['name' => 'Codice agente KNM'],
                    ],
                    'quantity' => 1,
                ]],
                'mode'                => 'payment',
                'success_url'         => route('portal.mlm.agent-code-fee.success', ['payment' => $payment->uuid]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'          => route('portal.mlm.agent-code-fee.show'),
                'client_reference_id' => $payment->uuid,
                'metadata'            => ['agent_code_fee_uuid' => $payment->uuid],
            ]);

            $payment->update(['stripe_checkout_session_id' => $session->id]);

            return redirect($session->url);
        } catch (\Exception $e) {
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota codice agente: avvio Stripe fallito', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);

            return redirect()->route('portal.mlm.agent-code-fee.show')
                ->with('portal_error', 'Errore nell\'avvio del pagamento con carta. Riprova o scegli un altro metodo.');
        }
    }

    // ── PayPal ──────────────────────────────────────────────────────────────

    public function paypalCreateOrder(Request $request): JsonResponse
    {
        abort_unless(config('services.paypal.client_id'), 503, 'PayPal non configurato.');

        try {
            $payment = $this->fees->startPayment($request->user(), AgentCodeFeePayment::METHOD_PAYPAL);
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
                        'description' => 'Codice agente KNM',
                        'custom_id'   => $payment->uuid,
                    ]],
                    'application_context' => [
                        'return_url'  => route('portal.mlm.agent-code-fee.paypal-capture', ['payment' => $payment->uuid]),
                        'cancel_url'  => route('portal.mlm.agent-code-fee.show'),
                        'brand_name'  => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $payment->update(['paypal_order_id' => $order['id'] ?? null]);

            return response()->json(['id' => $order['id'] ?? null, 'payment_uuid' => $payment->uuid]);
        } catch (\Exception $e) {
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota codice agente: creazione ordine PayPal fallita', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Errore PayPal. Riprova.'], 500);
        }
    }

    public function paypalCapture(Request $request, string $payment): RedirectResponse
    {
        $payment = AgentCodeFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $payment->isPending() || $payment->payment_method !== AgentCodeFeePayment::METHOD_PAYPAL) {
            return redirect()->route('portal.mlm.agent-code-fee.success', ['payment' => $payment->uuid]);
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
            Log::error('Quota codice agente: capture PayPal fallita', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            $this->fees->markFailed($payment, $e->getMessage());
        }

        return redirect()->route('portal.mlm.agent-code-fee.success', ['payment' => $payment->fresh()->uuid]);
    }

    // ── Bonifico ────────────────────────────────────────────────────────────

    public function bankTransfer(Request $request): View|RedirectResponse
    {
        try {
            // RIPRENDE quello gia' aperto invece di aprirne un altro: la
            // causale contiene l'uuid, e cambiarla a ogni visita vuol dire
            // ritrovarsi bonifici veri che non si ricollegano a niente.
            $payment = $this->fees->startOrResumeBankTransfer($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.mlm.agent-code-fee.show')->with('portal_error', $e->getMessage());
        }

        return view('portal.mlm.agent-code-fee-bank-transfer', [
            'pageTitle'       => 'Istruzioni per il bonifico',
            'activeNav'       => 'mlm-agent-request',
            'payment'         => $payment,
            'currentUser'     => $request->user(),
            'currentAccount'  => $this->fees->accountFor($request->user()),
            'bankIban'        => config('kmoney.bank_iban'),
            'bankName'        => config('kmoney.bank_name'),
            'bankBeneficiary' => config('kmoney.bank_beneficiary'),
        ]);
    }

    /**
     * "Cambia metodo di pagamento": chiude la richiesta di bonifico aperta e
     * riporta l'utente alla scelta.
     */
    public function abandonBankTransfer(Request $request): RedirectResponse
    {
        $chiuso = $this->fees->abandonBankTransfer($request->user());

        return redirect()->route('portal.mlm.agent-code-fee.show')
            ->with('portal_success', $chiuso
                ? 'Richiesta di bonifico annullata: scegli pure un altro metodo.'
                : 'Non risulta nessun bonifico in attesa.');
    }

    // ── Esito ───────────────────────────────────────────────────────────────

    public function success(Request $request, string $payment): View
    {
        $payment = AgentCodeFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // NB (01/09/2026): non `isPending()` ma "non e' ne' chiusa ne'
        // disfatta". Una riga finita `failed` — accredito andato storto, o
        // tentativo dato per abbandonato — deve poter essere ancora
        // accreditata se Stripe dice che l'incasso c'e' stato. La prova la da'
        // StripeCheckoutVerifier, non lo stato della riga.
        $daChiudere = ! $payment->isCompleted() && ! $payment->isCancelled();

        if ($daChiudere && $payment->payment_method === AgentCodeFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'agentcode:' . $payment->uuid,
            );

            if ($pagata) {
                $this->fees->completeEuroPayment($payment);
            }

            $payment->refresh();
        }

        // PAYPAL, DAL 02/09/2026. Prima questa pagina guardava solo Stripe, e
        // per PayPal non esiste nessun webhook: l'unica strada che accreditava
        // era la `capture` sincrona al ritorno. Chi chiudeva la scheda un
        // istante prima, o incappava in una scrittura fallita, aveva pagato
        // 480 euro e nessun processo lo recuperava.
        if ($daChiudere && $payment->payment_method === AgentCodeFeePayment::METHOD_PAYPAL) {
            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'agentcode:' . $payment->uuid,
            );

            if ($pagata) {
                $this->fees->completeEuroPayment($payment);
            }

            $payment->refresh();
        }

        return view('portal.mlm.agent-code-fee-success', [
            'pageTitle'      => $payment->isCompleted() ? 'Codice agente attivato' : 'Pagamento in attesa',
            'activeNav'      => 'mlm-agent-request',
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

        $payments = AgentCodeFeePayment::query()
            ->with(['user', 'confirmer'])
            ->when($stato !== '', fn ($q) => $q->where('status', $stato))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.agent-code-fees', [
            'pageTitle' => 'Quote codice agente',
            'activeNav' => 'agent-code-fees',
            'payments'  => $payments,
            'stato'     => $stato,
            'settings'  => SystemSetting::userLimitDefaults(),
        ]);
    }

    public function adminUpdateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        if ($request->filled('agent_code_fee_amount')) {
            $request->merge(['agent_code_fee_amount' => str_replace(',', '.', (string) $request->input('agent_code_fee_amount'))]);
        }

        $request->validate([
            'agent_code_fee_enabled'               => ['nullable', 'boolean'],
            'agent_code_fee_amount'                => ['required', 'numeric', 'min:0'],
            'agent_code_fee_stripe_enabled'        => ['nullable', 'boolean'],
            'agent_code_fee_paypal_enabled'        => ['nullable', 'boolean'],
            'agent_code_fee_bank_transfer_enabled' => ['nullable', 'boolean'],
            'agent_code_fee_ky_enabled'            => ['nullable', 'boolean'],
        ]);

        $importo = ky_to_cents($request->input('agent_code_fee_amount'));
        $attiva  = $request->boolean('agent_code_fee_enabled');

        $metodiAccesi = $request->boolean('agent_code_fee_stripe_enabled')
            || $request->boolean('agent_code_fee_paypal_enabled')
            || $request->boolean('agent_code_fee_bank_transfer_enabled')
            || $request->boolean('agent_code_fee_ky_enabled');

        if ($attiva && ! $metodiAccesi) {
            return back()->withInput()->withErrors([
                'agent_code_fee_enabled' => 'Per attivare la quota serve almeno un metodo di pagamento acceso.',
            ]);
        }

        if ($attiva && $importo <= 0) {
            return back()->withInput()->withErrors([
                'agent_code_fee_amount' => 'Per attivare la quota serve un importo maggiore di zero.',
            ]);
        }

        SystemSetting::userLimitDefaults()->forceFill([
            'agent_code_fee_enabled'               => $attiva,
            'agent_code_fee_amount_cents'          => $importo,
            'agent_code_fee_stripe_enabled'        => $request->boolean('agent_code_fee_stripe_enabled'),
            'agent_code_fee_paypal_enabled'        => $request->boolean('agent_code_fee_paypal_enabled'),
            'agent_code_fee_bank_transfer_enabled' => $request->boolean('agent_code_fee_bank_transfer_enabled'),
            'agent_code_fee_ky_enabled'            => $request->boolean('agent_code_fee_ky_enabled'),
        ])->save();

        return back()->with('portal_success', 'Impostazioni della quota codice agente aggiornate.');
    }

    public function adminConfirmBankTransfer(Request $request, AgentCodeFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $this->fees->completeEuroPayment($payment, $request->user()->id);

        return redirect()->route('admin.agent-code-fees.index')
            ->with('portal_success', 'Bonifico confermato: l\'agente può ora firmare il contratto di nomina.');
    }

    /**
     * Annulla una quota gia' saldata: storno (solo in KY, e solo se il
     * movimento c'e' ancora), quota di nuovo dovuta, fido aggiuntivo tolto.
     * Vive qui e non nella cancellazione dei movimenti perche' quella
     * ripristina i saldi e nient'altro — vedi AgentCodeFeeService::cancel().
     */
    public function adminCancel(Request $request, AgentCodeFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $inEuro = $payment->isPaidInEuro();

        try {
            $this->fees->cancel(
                $payment,
                $request->user(),
                $validated['admin_notes'] ?? null,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('admin.agent-code-fees.index')->with('portal_error', $e->getMessage());
        }

        // Due messaggi diversi perche' sono due situazioni diverse, e la
        // seconda lascia una cosa da fare a mano: dirlo qui e' l'unico
        // momento in cui chi ha annullato ci sta pensando.
        return redirect()->route('admin.agent-code-fees.index')->with(
            'portal_success',
            $inEuro
                ? 'Quota annullata: è di nuovo da saldare. Il pagamento era in euro, quindi NESSUN rimborso è partito: se va restituito, va disposto a mano su Stripe/PayPal o con un bonifico.'
                : 'Quota annullata: movimento stornato, quota di nuovo da saldare e fido aggiuntivo rimosso.'
        );
    }

    /**
     * ESONERO: "questo agente non paga". Azzera la quota senza muovere
     * denaro e senza scrivere un pagamento che non c'e' stato.
     */
    public function adminWaive(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'motivo']);

        try {
            $this->fees->waive($user, $request->user(), $validated['reason'], $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.users.show', $user)->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', $user->name . ' è esonerato dalla quota per il codice agente: può firmare il contratto di nomina senza pagare. È stato avvisato.');
    }

    /** Revoca dell'esonero: la quota torna dovuta. Solo prima della firma. */
    public function adminRevokeWaiver(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        try {
            $importo = $this->fees->revokeWaiver($user, $request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.users.show', $user)->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', 'Esonero revocato: ' . $user->name . ' deve di nuovo saldare ' . number_format($importo / 100, 2, ',', '.') . ' € prima di firmare.');
    }

    /**
     * «Verifica e salda»: ripesca un pagamento in euro finito `failed` quando i
     * soldi, in realta', sono stati incassati (02/09/2026).
     *
     * QUI SI RACCOGLIE LA PROVA, e il servizio si limita a chiudere la riga. La
     * differenza non e' formale: senza prova questo bottone sarebbe un modo per
     * regalare il codice agente premendolo su una riga qualsiasi.
     *
     *   - Stripe: la sessione SALVATA sul pagamento viene richiesta al server
     *     di Stripe e deve risultare pagata, dell'importo esatto e riferita a
     *     questo pagamento.
     *   - PayPal: l'ordine SALVATO deve risultare COMPLETED, dell'importo
     *     esatto e riferito a questo pagamento.
     *   - Bonifico: non esiste nessun server da interrogare, la prova e' l'admin
     *     che ha visto i soldi sul conto. Percio' li' il form deve portare una
     *     conferma esplicita (`bonifico_ricevuto`), che una POST generica non
     *     ha: e' un atto con un nome sopra, e l'audit log lo registra come tale.
     */
    public function adminRetryCredit(Request $request, AgentCodeFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $indietro = redirect()->route('admin.agent-code-fees.index');

        if ($payment->isCompleted()) {
            return $indietro->with('portal_error', 'Questa quota risulta già saldata.');
        }

        // Niente controllo su "e' un pagamento in euro?" qui: quello vive in
        // AgentCodeFeeService::retryEuroCredit(), dove protegge qualunque
        // chiamante e non solo questa rotta. Scriverlo anche qui vorrebbe dire
        // due difese che si nascondono a vicenda dal mutation testing.
        $metodo = $payment->payment_method;

        if ($metodo === AgentCodeFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'agentcode-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'Stripe non risulta aver incassato questo pagamento: non è stato saldato niente.');
            }
        } elseif ($metodo === AgentCodeFeePayment::METHOD_PAYPAL) {
            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'agentcode-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'PayPal non risulta aver incassato questo pagamento per questo importo: non è stato saldato niente. Il motivo esatto è nei log.');
            }
        } elseif ($metodo === AgentCodeFeePayment::METHOD_BANK_TRANSFER) {
            if (! $request->boolean('bonifico_ricevuto')) {
                return $indietro->with('portal_error', 'Per dare per saldato un bonifico devi confermare di averlo ricevuto.');
            }
        } else {
            return $indietro->with('portal_error', 'Su questo pagamento non c\'è nessun incasso in euro da verificare.');
        }

        try {
            $this->fees->retryEuroCredit($payment, $request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return $indietro->with('portal_error', $e->getMessage());
        }

        return $indietro->with('portal_success', 'Incasso verificato: la quota risulta saldata e l\'interessato può firmare il contratto di nomina.');
    }

    public function adminRejectBankTransfer(Request $request, AgentCodeFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->fees->markFailed($payment, $validated['admin_notes'] ?? 'Bonifico non ricevuto.');

        return redirect()->route('admin.agent-code-fees.index')
            ->with('portal_success', 'Pagamento rifiutato.');
    }
}
