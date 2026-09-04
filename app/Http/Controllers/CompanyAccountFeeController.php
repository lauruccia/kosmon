<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TalksToPayPal;
use App\Models\CompanyAccountFeePayment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CompanyAccountFeeService;
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
 * Quota di apertura conto delle aziende (03/09/2026).
 *
 * Quattro strade per la stessa cifra: carta, PayPal, bonifico e — se l'admin
 * lo accende — saldo KY. Il ciclo di vita del pagamento e' quello comune
 * (AbstractFeeService); qui c'e' solo l'andirivieni con i provider e il
 * backoffice, gemello dei due controller delle altre quote.
 *
 * DIFFERENZA DA TENERE A MENTE nel copiare da quelli: qui in euro NON si
 * accredita nessun KY, quindi nessun messaggio deve dire "i KY sono stati
 * accreditati" — sarebbe falso, e su 600 euro e' il genere di frase che
 * genera una telefonata.
 */
class CompanyAccountFeeController extends Controller
{
    use TalksToPayPal;

    public function __construct(private readonly CompanyAccountFeeService $fees)
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

        return view('portal.company-account-fee', [
            'pageTitle'      => 'Quota di apertura conto',
            'activeNav'      => 'company-account-fee',
            'amountCents'    => $this->fees->amountDueFor($user),
            'metodi'         => $settings->companyAccountFeeMethods(),
            'currentUser'    => $user,
            'currentAccount' => $account,
            'saldo'          => (int) ($account?->available_balance ?? 0),
            // Cosa riceve in cambio, per non farglielo scoprire dopo aver
            // pagato: i KY se paga in euro, il fido se paga in KY.
            'kyCredit'       => $this->fees->kyCreditFor($user),
            'kyAllowance'    => $this->fees->kyAllowanceEnabledFor($user),
            // Chi ha gia' chiesto il bonifico non deve ritrovare i bottoni
            // come se non avesse fatto niente: vede il bonifico in corso, e da
            // li' o rivede i dati o cambia metodo.
            'bonifico'       => $this->fees->pendingBankTransferFor($user),
        ]);
    }

    // ── Saldo KY ────────────────────────────────────────────────────────────

    public function payWithKy(Request $request): RedirectResponse
    {
        try {
            $payment = $this->fees->payWithKy($request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.company-account-fee.show')
                ->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.company-account-fee.success', ['payment' => $payment->uuid]);
    }

    // ── Stripe ──────────────────────────────────────────────────────────────

    public function stripeCheckout(Request $request): RedirectResponse
    {
        if (! config('services.stripe.secret')) {
            return redirect()->route('portal.company-account-fee.show')
                ->with('portal_error', 'Il pagamento con carta non è al momento disponibile. Scegli un altro metodo o riprova più tardi.');
        }

        try {
            $payment = $this->fees->startPayment($request->user(), CompanyAccountFeePayment::METHOD_STRIPE);
        } catch (RuntimeException $e) {
            return redirect()->route('portal.company-account-fee.show')->with('portal_error', $e->getMessage());
        }

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    // Prezzo definito qui e non da uno stripe_price_id: la
                    // quota la decide l'admin dal backoffice e puo' cambiare.
                    'price_data' => [
                        'currency'     => 'eur',
                        'unit_amount'  => (int) $payment->amount_eur_cents,
                        'product_data' => ['name' => 'Quota di apertura conto KMoney'],
                    ],
                    'quantity' => 1,
                ]],
                'mode'                => 'payment',
                'success_url'         => route('portal.company-account-fee.success', ['payment' => $payment->uuid]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'          => route('portal.company-account-fee.show'),
                'client_reference_id' => $payment->uuid,
                'metadata'            => ['company_account_fee_uuid' => $payment->uuid],
            ]);

            $payment->update(['stripe_checkout_session_id' => $session->id]);

            if (empty($session->url)) {
                throw new RuntimeException('Stripe non ha restituito un indirizzo di pagamento.');
            }

            return redirect($session->url);
        } catch (\Throwable $e) {
            // \Throwable e NON \Exception: se la libreria Stripe non e'
            // installata sul server la chiamata qui sopra solleva un \Error,
            // che un catch(\Exception) lascia passare — e' il guasto visto in
            // produzione il 01/09/2026, con la riga rimasta "pending" per
            // sempre perche' markFailed() non veniva mai raggiunto.
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota apertura conto: avvio Stripe fallito', [
                'payment' => $payment->uuid,
                'error'   => $e->getMessage(),
                'class'   => $e::class,
            ]);

            return redirect()->route('portal.company-account-fee.show')
                ->with('portal_error', 'Errore nell\'avvio del pagamento con carta. Riprova o scegli un altro metodo.');
        }
    }

    // ── PayPal ──────────────────────────────────────────────────────────────

    public function paypalCreateOrder(Request $request): JsonResponse
    {
        abort_unless(config('services.paypal.client_id'), 503, 'PayPal non configurato.');

        try {
            $payment = $this->fees->startPayment($request->user(), CompanyAccountFeePayment::METHOD_PAYPAL);
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
                        'description' => 'Quota di apertura conto KMoney',
                        'custom_id'   => $payment->uuid,
                    ]],
                    'application_context' => [
                        'return_url'  => route('portal.company-account-fee.paypal-capture', ['payment' => $payment->uuid]),
                        'cancel_url'  => route('portal.company-account-fee.show'),
                        'brand_name'  => 'KMoney',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            $order = $response->json();
            $payment->update(['paypal_order_id' => $order['id'] ?? null]);

            return response()->json(['id' => $order['id'] ?? null, 'payment_uuid' => $payment->uuid]);
        } catch (\Exception $e) {
            $this->fees->markFailed($payment, $e->getMessage());
            Log::error('Quota apertura conto: creazione ordine PayPal fallita', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Errore PayPal. Riprova.'], 500);
        }
    }

    public function paypalCapture(Request $request, string $payment): RedirectResponse
    {
        $payment = CompanyAccountFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $payment->isPending() || $payment->payment_method !== CompanyAccountFeePayment::METHOD_PAYPAL) {
            return redirect()->route('portal.company-account-fee.success', ['payment' => $payment->uuid]);
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
            Log::error('Quota apertura conto: capture PayPal fallita', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            $this->fees->markFailed($payment, $e->getMessage());
        }

        return redirect()->route('portal.company-account-fee.success', ['payment' => $payment->fresh()->uuid]);
    }

    // ── Bonifico ────────────────────────────────────────────────────────────

    public function bankTransfer(Request $request): View|RedirectResponse
    {
        try {
            $payment = $this->fees->startOrResumeBankTransfer($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.company-account-fee.show')->with('portal_error', $e->getMessage());
        }

        return view('portal.company-account-fee-bank-transfer', [
            'pageTitle'       => 'Istruzioni per il bonifico',
            'activeNav'       => 'company-account-fee',
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

        return redirect()->route('portal.company-account-fee.show')
            ->with('portal_success', $chiuso
                ? 'Richiesta di bonifico annullata: scegli pure un altro metodo.'
                : 'Non risulta nessun bonifico in attesa.');
    }

    // ── Esito ───────────────────────────────────────────────────────────────

    public function success(Request $request, string $payment): View
    {
        $payment = CompanyAccountFeePayment::where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // "Non saldata e non annullata" e non "in attesa": una riga finita
        // `failed` (chiusura andata storta, o tentativo scaduto dal comando
        // notturno) deve poter essere ancora chiusa se il provider dice che
        // l'incasso c'e' stato. La prova la danno i due verifier, non lo stato
        // della riga.
        $daChiudere = ! $payment->isCompleted() && ! $payment->isCancelled();

        if ($daChiudere && $payment->payment_method === CompanyAccountFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'aperturaconto:' . $payment->uuid,
            );

            if ($pagata) {
                $this->fees->completeEuroPayment($payment);
            }

            $payment->refresh();
        }

        // PayPal: senza questo ramo l'unica strada che chiude sarebbe la
        // `capture` sincrona al ritorno, e chi paga e chiude la scheda un
        // istante prima resta con l'incasso preso e la quota da pagare.
        if ($daChiudere && $payment->payment_method === CompanyAccountFeePayment::METHOD_PAYPAL) {
            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'aperturaconto:' . $payment->uuid,
            );

            if ($pagata) {
                $this->fees->completeEuroPayment($payment);
            }

            $payment->refresh();
        }

        return view('portal.company-account-fee-success', [
            'pageTitle'      => $payment->isCompleted() ? 'Quota saldata' : 'Quota in attesa',
            'activeNav'      => 'company-account-fee',
            'payment'        => $payment,
            'currentUser'    => $request->user(),
            'currentAccount' => $this->fees->accountFor($request->user()),
        ]);
    }

    // ── Backoffice ──────────────────────────────────────────────────────────

    /**
     * L'elenco dei pagamenti di questa quota adesso sta nella pagina unica
     * /admin/quote, in una delle tre schede (04/09/2026): tre pagine identiche
     * in tutto tranne i nomi erano tre posti dove correggere la stessa cosa.
     * Vedi QuoteAdminController.
     */
    /**
     * Le impostazioni della quota vivono su una rotta propria e non nel form
     * dei limiti di default: salvare quello riscrive i limiti di TUTTI gli
     * utenti, e cambiare l'importo della quota non deve trascinarsi dietro una
     * migrazione di massa. Stessa scelta delle altre due quote.
     */
    public function adminUpdateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        foreach (['company_account_fee_amount', 'company_account_fee_ky_credit'] as $campo) {
            if ($request->filled($campo)) {
                $request->merge([$campo => str_replace(',', '.', (string) $request->input($campo))]);
            }
        }

        $validated = $request->validate([
            'company_account_fee_enabled'               => ['nullable', 'boolean'],
            'company_account_fee_amount'                => ['required', 'numeric', 'min:0'],
            'company_account_fee_stripe_enabled'        => ['nullable', 'boolean'],
            'company_account_fee_paypal_enabled'        => ['nullable', 'boolean'],
            'company_account_fee_bank_transfer_enabled' => ['nullable', 'boolean'],
            'company_account_fee_ky_enabled'            => ['nullable', 'boolean'],
            // Le due leve (04/09/2026). L'accredito non ha un tetto legato
            // alla quota: puo' essere piu' alto, ed e' una scelta commerciale.
            'company_account_fee_ky_credit'             => ['nullable', 'numeric', 'min:0'],
            'company_account_fee_ky_allowance'          => ['nullable', 'boolean'],
        ]);

        $importo = ky_to_cents($validated['company_account_fee_amount']);
        $attiva  = $request->boolean('company_account_fee_enabled');

        // Accendere la quota lasciando tutti i metodi spenti significa mandare
        // ogni nuova azienda su una pagina senza bottoni: e' un errore di
        // configurazione, non una scelta, e va fermato qui.
        $metodiAccesi = $request->boolean('company_account_fee_stripe_enabled')
            || $request->boolean('company_account_fee_paypal_enabled')
            || $request->boolean('company_account_fee_bank_transfer_enabled')
            || $request->boolean('company_account_fee_ky_enabled');

        if ($attiva && ! $metodiAccesi) {
            return back()->withInput()->withErrors([
                'company_account_fee_enabled' => 'Per attivare la quota serve almeno un metodo di pagamento acceso.',
            ]);
        }

        if ($attiva && $importo <= 0) {
            return back()->withInput()->withErrors([
                'company_account_fee_amount' => "Per attivare la quota serve un importo maggiore di zero.",
            ]);
        }

        // LE DUE LEVE SI SCRIVONO SOLO SE IL FORM LE PORTA (04/09/2026),
        // stessa regola delle altre due quote. Una casella non spuntata e una
        // casella assente arrivano identiche, e boolean() risponde false a
        // tutte e due: senza questa guardia una richiesta che non porta i due
        // campi spegnerebbe il fido, e il prossimo che paga in KY si vedrebbe
        // rifiutare l'addebito.
        $leve = [];

        if ($request->boolean('company_account_fee_form') || $request->has('company_account_fee_ky_credit')) {
            $leve['company_account_fee_ky_credit_cents'] = ky_to_cents($request->input('company_account_fee_ky_credit') ?: 0);
            $leve['company_account_fee_ky_allowance']    = $request->boolean('company_account_fee_ky_allowance');
        }

        SystemSetting::userLimitDefaults()->forceFill([
            'company_account_fee_enabled'               => $attiva,
            'company_account_fee_amount_cents'          => $importo,
            'company_account_fee_stripe_enabled'        => $request->boolean('company_account_fee_stripe_enabled'),
            'company_account_fee_paypal_enabled'        => $request->boolean('company_account_fee_paypal_enabled'),
            'company_account_fee_bank_transfer_enabled' => $request->boolean('company_account_fee_bank_transfer_enabled'),
            'company_account_fee_ky_enabled'            => $request->boolean('company_account_fee_ky_enabled'),
        ] + $leve)->save();

        return back()->with('portal_success', 'Impostazioni della quota di apertura conto aggiornate.');
    }

    public function adminConfirmBankTransfer(Request $request, CompanyAccountFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $this->fees->completeEuroPayment($payment, $request->user()->id);

        return redirect()->route('admin.company-account-fees.index')
            ->with('portal_success', 'Bonifico confermato: la quota risulta saldata.');
    }

    /**
     * Annulla una quota gia' saldata: la quota torna dovuta e, se era stata
     * pagata in KY, il movimento viene stornato e il fido aggiuntivo tolto. In
     * euro non c'e' niente da stornare — nessun KY era stato emesso — e il
     * rimborso resta da fare a mano su Stripe, PayPal o in banca.
     */
    public function adminCancel(Request $request, CompanyAccountFeePayment $payment): RedirectResponse
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
            return redirect()->route('admin.company-account-fees.index')->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.company-account-fees.index')
            ->with('portal_success', 'Quota annullata: torna da saldare. In euro il rimborso va fatto a mano.');
    }

    /**
     * Mette la quota in carico a un'azienda che non la deve: le anagrafiche
     * importate dal vecchio sito, o chi si e' registrato mentre la quota era
     * spenta.
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
            ->with('portal_success', 'Quota di apertura conto di ' . ky_format($importo) . ' € richiesta a ' . $user->name . ': è stato avvisato per email e in notifica.');
    }

    /**
     * Il trattamento di UNA azienda: quanti KY riceve pagando in euro, e se
     * pagando in KY ha il fido aggiuntivo. Campo vuoto e «come da impostazioni»
     * riportano al default del pannello, e non e' la stessa cosa di scrivere
     * zero o «no» — quelli restano fermi anche se domani il default cambia.
     */
    public function adminSetTreatment(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        if ($request->filled('ky_credit')) {
            $request->merge(['ky_credit' => str_replace(',', '.', (string) $request->input('ky_credit'))]);
        }

        $validated = $request->validate([
            'ky_credit'    => ['nullable', 'numeric', 'min:0'],
            // Tre valori, non due: '' = segui il pannello, 1 = si', 0 = no.
            'ky_allowance' => ['nullable', 'in:0,1'],
        ]);

        $credito = ($validated['ky_credit'] ?? '') === '' || ! isset($validated['ky_credit'])
            ? null
            : ky_to_cents($validated['ky_credit']);

        $fido = ($validated['ky_allowance'] ?? '') === '' || ! isset($validated['ky_allowance'])
            ? null
            : (bool) $validated['ky_allowance'];

        try {
            $this->fees->setTreatment($user, $request->user(), $credito, $fido, $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.users.show', $user)->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', 'Trattamento della quota di apertura conto aggiornato per ' . $user->name . '.');
    }

    /**
     * «Verifica e salda»: ripesca un pagamento in euro finito `failed` quando i
     * soldi, in realta', sono stati incassati.
     *
     * QUI SI RACCOGLIE LA PROVA, e il servizio si limita a chiudere. Senza
     * prova questo bottone sarebbe un modo per dare per pagata una quota da
     * 600 euro premendolo su una riga qualsiasi.
     */
    public function adminRetryCredit(Request $request, CompanyAccountFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $indietro = redirect()->route('admin.company-account-fees.index');

        if ($payment->isCompleted()) {
            return $indietro->with('portal_error', 'Questa quota risulta già saldata.');
        }

        // Niente controllo su "e' un pagamento in euro?" qui: quello vive in
        // AbstractFeeService::retryEuroCredit(), dove protegge qualunque
        // chiamante e non solo questa rotta. Un pagamento in KY finisce nel
        // ramo finale qui sotto.
        $metodo = $payment->payment_method;

        if ($metodo === CompanyAccountFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'aperturaconto-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'Stripe non risulta aver incassato questo pagamento: la quota resta da saldare.');
            }
        } elseif ($metodo === CompanyAccountFeePayment::METHOD_PAYPAL) {
            if (empty($payment->paypal_order_id)) {
                return $indietro->with('portal_error', 'Questo tentativo PayPal non è mai arrivato a creare un ordine: non c\'è niente da verificare.');
            }

            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'aperturaconto-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'PayPal non risulta aver incassato questo pagamento per questo importo: la quota resta da saldare. Il motivo esatto è nei log.');
            }
        } elseif ($metodo === CompanyAccountFeePayment::METHOD_BANK_TRANSFER) {
            // Nessun server da interrogare: la prova e' l'admin. Una spunta
            // obbligatoria, cosi' non ci si arriva per inerzia premendo il
            // bottone su una riga rifiutata per sbaglio.
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

        return $indietro->with('portal_success', 'Incasso verificato: la quota risulta saldata.');
    }

    public function adminRejectBankTransfer(Request $request, CompanyAccountFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        abort_unless($payment->isPendingBankTransfer(), 422, 'Questo pagamento non è in attesa di bonifico.');

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->fees->markFailed($payment, $validated['admin_notes'] ?? 'Bonifico non ricevuto.');

        return redirect()->route('admin.company-account-fees.index')
            ->with('portal_success', 'Pagamento rifiutato.');
    }
}
