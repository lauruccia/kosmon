<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TalksToPayPal;
use App\Models\RegistrationFeePayment;
use App\Models\User;
use App\Models\SystemSetting;
use App\Services\RegistrationFeeService;
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
            // Quanti KY riceve pagando in euro (04/09/2026): fino a ieri
            // erano sempre pari all'importo e la pagina poteva darlo per
            // scontato, adesso lo decide l'admin e puo' essere anche zero.
            'kyCredit'    => $this->fees->kyCreditFor($user),
            'metodi'      => $settings->registrationFeeMethods(),
            'currentUser' => $user,
            'currentAccount' => $account,
            'saldo'       => (int) ($account?->available_balance ?? 0),
            // Chi ha gia' chiesto il bonifico non deve ritrovare i quattro
            // bottoni come se non avesse fatto niente: vede il bonifico in
            // corso, e da li' o rivede i dati o cambia metodo.
            'bonifico'    => $this->fees->pendingBankTransferFor($user),
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
            $payment = $this->fees->startOrResumeBankTransfer($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('portal.registration-fee.show')->with('portal_error', $e->getMessage());
        }

        return view('portal.registration-fee-bank-transfer', [
            'pageTitle'       => 'Istruzioni per il bonifico',
            'activeNav'       => 'registration-fee',
            'payment'         => $payment,
            'currentUser'     => $request->user(),
            'currentAccount'  => $this->fees->accountFor($request->user()),
            'kyCredit'        => $this->fees->kyCreditFor($request->user()),
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

        return redirect()->route('portal.registration-fee.show')
            ->with('portal_success', $chiuso
                ? 'Richiesta di bonifico annullata: scegli pure un altro metodo.'
                : 'Non risulta nessun bonifico in attesa.');
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
        // "Non saldata e non annullata" e non "in attesa": una riga finita
        // `failed` (accredito andato storto, o tentativo scaduto dal comando
        // notturno) deve poter essere ancora accreditata se Stripe dice che
        // l'incasso c'e' stato. La prova la da' StripeCheckoutVerifier, non
        // lo stato della riga.
        $daChiudere = ! $payment->isCompleted() && ! $payment->isCancelled();

        if ($daChiudere && $payment->payment_method === RegistrationFeePayment::METHOD_STRIPE) {
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

        // PAYPAL, DAL 02/09/2026. Prima questa pagina aveva un solo ramo,
        // Stripe: per PayPal l'unica strada che accreditava era la `capture`
        // sincrona al ritorno, e non esiste nessun webhook a fare da rete. Chi
        // pagava e chiudeva la scheda un istante prima del ritorno, o
        // incappava in una scrittura fallita, restava senza KY e senza nessun
        // processo che lo recuperasse.
        //
        // E' anche cio' che rende innocua la guardia di paypalCapture(), che
        // sulle righe non piu' `pending` rimanda qui senza catturare: un
        // ordine gia' catturato non si cattura due volte, ma lo si puo'
        // CHIEDERE — ed e' quello che si fa qui.
        if ($daChiudere && $payment->payment_method === RegistrationFeePayment::METHOD_PAYPAL) {
            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
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
            'kyCredit'       => $this->fees->kyCreditFor($request->user()),
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
     * utenti (vedi CreditLimitController::updateLimitDefaults, che li fissa
     * uno per uno), e cambiare l'importo della quota non deve trascinarsi
     * dietro una migrazione di massa.
     */
    public function adminUpdateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        foreach (['registration_fee_amount', 'registration_fee_ky_credit'] as $campo) {
            if ($request->filled($campo)) {
                $request->merge([$campo => str_replace(',', '.', (string) $request->input($campo))]);
            }
        }

        $validated = $request->validate([
            'registration_fee_enabled'               => ['nullable', 'boolean'],
            'registration_fee_amount'                => ['required', 'numeric', 'min:0'],
            'registration_fee_stripe_enabled'        => ['nullable', 'boolean'],
            'registration_fee_paypal_enabled'        => ['nullable', 'boolean'],
            'registration_fee_bank_transfer_enabled' => ['nullable', 'boolean'],
            'registration_fee_ky_enabled'            => ['nullable', 'boolean'],
            // Le due leve (04/09/2026). La restituzione non ha un tetto
            // legato alla quota: puo' essere piu' alta, ed e' una scelta
            // commerciale, non un resto.
            'registration_fee_ky_credit'             => ['nullable', 'numeric', 'min:0'],
            'registration_fee_ky_allowance'          => ['nullable', 'boolean'],
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

        // LE DUE LEVE SI SCRIVONO SOLO SE IL FORM LE PORTA (04/09/2026).
        //
        // Il marcatore `<prefisso>_form` lo manda la pagina /admin/quote, che
        // e' l'unica a mostrare questi due campi. Senza questa guardia una
        // richiesta che non li contiene — un vecchio segnalibro, uno script,
        // un test scritto prima — spegnerebbe il fido: una casella non spuntata
        // e una casella assente arrivano identiche, e boolean() risponde false
        // a tutte e due. Spegnere il fido a tradimento vuol dire che il
        // prossimo che paga in KY si vede rifiutare l'addebito.
        $leve = [];

        if ($request->boolean('registration_fee_form') || $request->has('registration_fee_ky_credit')) {
            $leve['registration_fee_ky_credit_cents'] = ky_to_cents($request->input('registration_fee_ky_credit') ?: 0);
            $leve['registration_fee_ky_allowance']    = $request->boolean('registration_fee_ky_allowance');
        }

        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_enabled'               => $attiva,
            'registration_fee_amount_cents'          => $importo,
            'registration_fee_stripe_enabled'        => $request->boolean('registration_fee_stripe_enabled'),
            'registration_fee_paypal_enabled'        => $request->boolean('registration_fee_paypal_enabled'),
            'registration_fee_bank_transfer_enabled' => $request->boolean('registration_fee_bank_transfer_enabled'),
            'registration_fee_ky_enabled'            => $request->boolean('registration_fee_ky_enabled'),
        ] + $leve)->save();

        return back()->with('portal_success', 'Impostazioni della quota di iscrizione aggiornate.');
    }

    /**
     * IL TRATTAMENTO DI QUESTA PERSONA (04/09/2026): quanti KY le vengono
     * restituiti se paga in euro, e se pagando in KY riceve il fido aggiuntivo.
     * Scavalca i due default della pagina /admin/quote.
     *
     * TRE VALORI, NON DUE. Campo vuoto e «come da impostazioni» vogliono dire
     * «segui il pannello» e arrivano al servizio come `null`; 0 e «no» sono
     * decisioni prese per questa persona e restano ferme anche se domani il
     * default cambia. E' l'unico motivo per cui esistono due colonne di
     * ripiego invece di scrivere direttamente il numero.
     */
    public function adminSetTreatment(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        if ($request->filled('ky_credit')) {
            $request->merge(['ky_credit' => str_replace(',', '.', (string) $request->input('ky_credit'))]);
        }

        $validated = $request->validate([
            'ky_credit'    => ['nullable', 'numeric', 'min:0'],
            'ky_allowance' => ['nullable', 'in:0,1'],
        ]);

        $credito = ! isset($validated['ky_credit']) || ($validated['ky_credit'] ?? '') === ''
            ? null
            : ky_to_cents($validated['ky_credit']);

        $fido = ! isset($validated['ky_allowance']) || ($validated['ky_allowance'] ?? '') === ''
            ? null
            : (bool) $validated['ky_allowance'];

        try {
            $this->fees->setTreatment($user, $request->user(), $credito, $fido, $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.users.show', $user)->with('portal_error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', 'Trattamento della quota aggiornato per ' . $user->name . '.');
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
            ->with('portal_success', 'Quota di ' . ky_format($importo) . ' € richiesta a ' . $user->name . ': è stato avvisato per email e in notifica.');
    }

    /**
     * «Sospendi la quota di iscrizione» dalla scheda utente (02/09/2026).
     *
     * Serve per gli ARRETRATI: chi era stato approvato come agente prima che
     * l'approvazione sospendesse la quota da sola si e' ritrovato addosso
     * tutte e due — 30 e 480 — e per lui non esisteva nessun bottone. Non e'
     * un'azione di massa: una persona alla volta, con un nome e un audit log
     * dietro, come per la richiesta della quota qui sopra.
     *
     * L'unica guardia che serve qui e' che sia davvero sul percorso agente:
     * fuori di li' «sospendere» vorrebbe dire condonare la quota in silenzio,
     * e per condonare c'e' l'annullamento, che almeno storna e lascia scritto
     * quanto. Le altre condizioni (gia' pagata, gia' sospesa, non dovuta) le
     * conosce il servizio, dentro il suo lock, e non si ricopiano qui.
     */
    public function adminSuspendForAgentPath(Request $request, User $user): RedirectResponse
    {
        // NIENTE abort_unless(canAccessBackoffice()) qui, ed e' voluto: la
        // rotta ha gia' il middleware `backoffice`, e le due difese si
        // nasconderebbero a vicenda — togliendo questa i test restavano
        // verdi, che e' il modo in cui una guardia smette di essere provata
        // senza che nessuno se ne accorga (undicesima volta in questo
        // progetto). La porta e' una sola, ed e' provata da un test che la
        // bussa da utente qualunque.
        if (! app(\App\Services\AgentCodeFeeService::class)->isOnFeePath($user)) {
            return redirect()->route('admin.users.show', $user)
                ->with('portal_error', 'Questo utente non è sul percorso agente: la quota di iscrizione si sospende solo a chi al suo posto paga il codice agente.');
        }

        $sospesi = $this->fees->suspendOnAgentApproval($user, $request->ip(), $request->user());

        if ($sospesi === 0) {
            return redirect()->route('admin.users.show', $user)
                ->with('portal_error', 'Non c\'era niente da sospendere: la quota risulta già sospesa, già saldata, o non dovuta.');
        }

        return redirect()->route('admin.users.show', $user)
            ->with('portal_success', 'Quota di iscrizione sospesa: ' . $user->name . ' paga solo il codice agente. Tornerà dovuta se rinuncia o se la richiesta viene rifiutata.');
    }

    /**
     * «Verifica e accredita»: ripesca un pagamento in euro finito `failed`
     * quando i soldi, in realta', sono stati incassati.
     *
     * QUI SI RACCOGLIE LA PROVA, e il servizio si limita ad accreditare. La
     * differenza non e' formale: senza prova questo bottone sarebbe un modo
     * per creare KY dal nulla premendolo su una riga qualsiasi.
     *
     *   - Stripe: la sessione SALVATA sul pagamento viene richiesta al server
     *     di Stripe e deve risultare pagata, dell'importo esatto e riferita a
     *     questo pagamento (StripeCheckoutVerifier, la stessa verifica della
     *     pagina di successo e del webhook).
     *   - PayPal: si rilegge l'ordine e deve essere COMPLETED, cioe'
     *     catturato davvero.
     *   - Bonifico: non esiste nessun server da interrogare, la prova e'
     *     l'admin che ha visto i soldi sul conto. Percio' li' il form deve
     *     portare una conferma esplicita (`bonifico_ricevuto`), che una POST
     *     generica di ripescaggio non ha: e' un atto con un nome sopra, e
     *     l'audit log lo registra come tale.
     */
    public function adminRetryCredit(Request $request, RegistrationFeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $indietro = redirect()->route('admin.registration-fees.index');

        if ($payment->isCompleted()) {
            return $indietro->with('portal_error', 'Questa quota risulta già saldata.');
        }

        // Niente controllo su "e' un pagamento in euro?" qui: quello vive in
        // RegistrationFeeService::retryEuroCredit(), dove protegge qualunque
        // chiamante e non solo questa rotta. Scriverlo anche qui vorrebbe dire
        // due difese che si nascondono a vicenda dal mutation testing —
        // spegnendone una i test restano verdi e nessuna delle due risulta
        // piu' provata. Un pagamento in KY finisce nel ramo finale qui sotto.
        $metodo = $payment->payment_method;

        if ($metodo === RegistrationFeePayment::METHOD_STRIPE) {
            $pagata = app(StripeCheckoutVerifier::class)->isPaidFor(
                $payment->stripe_checkout_session_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'regfee-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'Stripe non risulta aver incassato questo pagamento: non è stato accreditato niente.');
            }
        } elseif ($metodo === RegistrationFeePayment::METHOD_PAYPAL) {
            if (empty($payment->paypal_order_id)) {
                return $indietro->with('portal_error', 'Questo tentativo PayPal non è mai arrivato a creare un ordine: non c\'è niente da verificare.');
            }

            // Dal 02/09/2026 la prova la raccoglie PayPalOrderVerifier e non
            // piu' due righe scritte qui: cosi' e' la STESSA prova che usano
            // la pagina di esito e il ripescaggio della quota agente, ed e'
            // piu' severa di prima — non basta piu' che l'ordine risulti
            // COMPLETED, deve anche essere di questo importo e riferito a
            // questo pagamento.
            $pagata = app(PayPalOrderVerifier::class)->isCompletedFor(
                $payment->paypal_order_id,
                (int) $payment->amount_eur_cents,
                $payment->uuid,
                'regfee-retry:' . $payment->uuid,
            );

            if (! $pagata) {
                return $indietro->with('portal_error', 'PayPal non risulta aver incassato questo pagamento per questo importo: non è stato accreditato niente. Il motivo esatto è nei log.');
            }
        } elseif ($metodo === RegistrationFeePayment::METHOD_BANK_TRANSFER) {
            // Nessun server da interrogare: la prova e' l'admin. Una spunta
            // obbligatoria, cosi' non ci si arriva per inerzia premendo il
            // bottone su una riga rifiutata per sbaglio.
            if (! $request->boolean('bonifico_ricevuto')) {
                return $indietro->with('portal_error', 'Per accreditare un bonifico devi confermare di averlo ricevuto.');
            }
        } else {
            return $indietro->with('portal_error', 'Su questo pagamento non c\'è nessun incasso in euro da verificare.');
        }

        try {
            $this->fees->retryEuroCredit($payment, $request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return $indietro->with('portal_error', $e->getMessage());
        }

        return $indietro->with('portal_success', 'Incasso verificato: i ' . ky_format($payment->fresh()->ky_amount) . ' KY sono stati accreditati.');
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
