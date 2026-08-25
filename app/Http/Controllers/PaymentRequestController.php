<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceived;
use App\Models\Account;
use App\Models\MandatePaymentRequest;
use App\Models\PaymentRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use App\Services\ClientWebhookService;
use App\Services\PaymentMandateService;
use App\Services\TransferBookingService;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Events\PaymentRequestUpdated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * PaymentRequestController
 *
 * Gestisce il lato pagatore delle PaymentRequest (QR dinamico).
 *
 *   GET  /pay/{token}  -> pagina di pagamento (auth required)
 *   POST /pay/{token}  -> esegue il pagamento
 */
class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly PaymentMandateService $mandates,
        private readonly ClientWebhookService $clientWebhooks,
    ) {
    }

    // =========================================================================
    // Gli ordini delle applicazioni del circuito (fase 2b)
    //
    // Questa pagina esisteva già e continua a fare quello che ha sempre fatto:
    // qualcuno chiede dei KY, tu li paghi. Gli ordini di kshop la riusano
    // invece di avere una pagina propria — stesso saldo, stesso "Ricarica ora"
    // con ritorno automatico, stesse verifiche sul conto, stesso webhook. Le
    // differenze sono tre, tutte raccolte qui sotto per non lasciarle sparse
    // dentro un metodo che muove denaro:
    //
    //   1. il movimento nasce come acquisto shop, non come pagamento QR, così
    //      cashback, commissioni e MLM restano identici all'acquisto in un clic;
    //   2. la chiave di idempotenza è QUELLA DI KSHOP, così la conferma a mano
    //      e il retry automatico non possono pagare lo stesso ordine due volte;
    //   3. può pagare solo il proprietario del mandato: un link di conferma non
    //      è un QR da esporre sul bancone.
    // =========================================================================

    private function mandateOrderFor(PaymentRequest $pr): ?MandatePaymentRequest
    {
        if (! $pr->isKshopOrder()) {
            return null;
        }

        return MandatePaymentRequest::query()
            ->where('payment_request_id', $pr->id)
            ->with('mandate')
            ->first();
    }

    private function isMandateOwner(MandatePaymentRequest $ordine, User $user): bool
    {
        return $ordine->mandate !== null
            && (int) $ordine->mandate->user_id === (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function mandateOrderBooking(
        MandatePaymentRequest $ordine,
        PaymentRequest $pr,
        int $userId,
        int $fromAccountId,
        ?string $ip,
    ): array {
        return [
            'initiated_by'        => $userId,
            'from_account_id'     => $fromAccountId,
            'to_account_id'       => $pr->to_account_id,
            'amount'              => $pr->amount,
            'kind'                => 'portal_marketplace_order',
            'description'         => $pr->description ?? 'Acquisto Kosmoshop',
            'quantity'            => (int) $ordine->quantity,
            'order_title'         => $ordine->order_title,
            'order_source'        => Transfer::ORDER_SOURCE_KSHOP,
            'external_order_uuid' => $ordine->external_order_uuid,
            'idempotency_key'     => $this->mandates->transferKeyFor(
                $ordine->mandate,
                $ordine->idempotency_key,
            ),
            'ip_address'          => $ip,
        ];
    }

    private function appName(string $clientId): string
    {
        foreach ((array) config('oauth.clients', []) as $client) {
            if (($client['client_id'] ?? null) === $clientId && $clientId !== '') {
                return (string) ($client['name'] ?? $clientId);
            }
        }

        return $clientId;
    }

    /** Mostra la pagina di pagamento. */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard')
                ->with('portal_error', 'Gli amministratori non possono effettuare pagamenti dal portale.');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('token', $token)
            ->firstOrFail();

        // Aggiorna scaduta on-the-fly
        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
            $pr->refresh();
        }

        $fromAccount = $this->resolveAccount($user);

        // Non puoi pagare te stesso
        if ($fromAccount->id === $pr->to_account_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto. (Conto pagatore ' . $fromAccount->account_number . ' = conto destinatario ' . $pr->toAccount?->account_number . ')');
        }

        $ordine = $this->mandateOrderFor($pr);

        if ($ordine !== null && ! $this->isMandateOwner($ordine, $user)) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa conferma di acquisto è intestata a un altro utente.');
        }

        return view('portal.pay-request', [
            'pageTitle'   => $ordine !== null ? 'Conferma acquisto' : 'Richiesta di pagamento',
            'pr'          => $pr,
            'fromAccount' => $fromAccount,
            'activeNav'   => 'conto',

            // Presente solo per gli ordini di un'applicazione del circuito: è
            // quello che trasforma la pagina da "qualcuno ti chiede dei soldi"
            // a "Kosmoshop vuole addebitarti questo, e perché te lo sto
            // chiedendo invece di farlo in automatico".
            'mandateOrder' => $ordine,
            'appName'      => $ordine !== null ? $this->appName($ordine->client_id) : null,
        ]);
    }

    /**
     * POST /pay/{token}/cambia-utente
     *
     * "Non sei tu?" (stile PayPal, discreto): l'utente loggato non e' chi
     * deve pagare questa richiesta. Esce dall'account corrente e lo manda al
     * login; al prossimo accesso riuscito AuthController::login() lo riporta
     * automaticamente qui tramite il normale meccanismo "intended URL" di
     * Laravel (redirect()->intended(), gia' usato per ogni altro login) -
     * nessuna modifica necessaria al flusso di login stesso.
     */
    public function switchUser(Request $request, string $token): RedirectResponse
    {
        PaymentRequest::where('token', $token)->firstOrFail();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Impostato DOPO invalidate(): invalidate() svuota la sessione, quindi
        // qualsiasi valore scritto prima andrebbe perso.
        $request->session()->put('url.intended', route('portal.pay-request.show', $token));

        return redirect()->route('login');
    }

    /** Esegue il pagamento. */
    public function pay(
        Request $request,
        string $token,
        TransferBookingService $bookingService,
        WebhookService $webhookService
    ): RedirectResponse {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('token', $token)
            ->firstOrFail();

        // Verifica che il conto destinatario (merchant) sia ancora attivo al momento
        // del pagamento. Potrebbe essere stato sospeso dopo la creazione del QR.
        if ($pr->toAccount === null || $pr->toAccount->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il conto del destinatario non è più attivo. Pagamento annullato.');
        }

        // Validazioni stato
        if ($pr->isPaid()) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' gia\' stata saldata.');
        }

        if ($pr->isExpired()) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' scaduta. Chiedi un nuovo QR al commerciante.');
        }

        if ($pr->status === 'cancelled') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' stata annullata.');
        }

        $fromAccount = $this->resolveAccount($user);

        if ($fromAccount->id === $pr->to_account_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto. (Conto pagatore ' . $fromAccount->account_number . ' = conto destinatario ' . $pr->toAccount?->account_number . ')');
        }

        if ($fromAccount->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non e\' attivo. Impossibile eseguire il pagamento.');
        }

        // Un ordine di un'applicazione del circuito non è pagabile da chiunque
        // abbia il link: è la conferma di un addebito su UN mandato, e il
        // mandato ha un proprietario. Una richiesta QR resta invece pagabile da
        // chi si presenta, che è tutto il senso di un QR.
        $ordine = $this->mandateOrderFor($pr);

        if ($ordine !== null && ! $this->isMandateOwner($ordine, $user)) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa conferma di acquisto è intestata a un altro utente.');
        }

        // Esegui il trasferimento
        try {
            $transfer = $bookingService->book(
                $ordine !== null
                    ? $this->mandateOrderBooking($ordine, $pr, $user->id, $fromAccount->id, $request->ip())
                    : [
                        'initiated_by'    => $user->id,
                        'from_account_id' => $fromAccount->id,
                        'to_account_id'   => $pr->to_account_id,
                        'amount'          => $pr->amount,
                        'description'     => $pr->description ?? 'Pagamento QR KMoney',
                        'kind'            => 'portal_qr_payment',
                        'idempotency_key' => 'pr_' . $pr->uuid,
                        'ip_address'      => $request->ip(),
                    ]
            );
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        // L'addebito va registrato sul mandato PRIMA di tutto il resto: è
        // quello che fa tornare 200 al primo retry di kshop, ed è anche il
        // momento in cui il venditore entra fra quelli autorizzati.
        if ($ordine !== null) {
            $this->mandates->recordConfirmedCharge(
                $ordine,
                $transfer,
                $request->boolean('authorize_seller'),
                $request->ip(),
            );
        }

        // Segna la richiesta come pagata
        $pr->update([
            'status'          => 'paid',
            'paid_at'         => now(),
            'transfer_id'     => $transfer->id,
            'from_account_id' => $fromAccount->id,
        ]);

        // Broadcast real-time al merchant (aggiorna UI senza polling)
        $prFresh = $pr->fresh();
        broadcast(new PaymentRequestUpdated($prFresh))->toOthers();

        // Webhook al commerciante (destinatario): usato dalle integrazioni e-commerce
        // (WooCommerce/Magento) per confermare l'ordine in modo asincrono e autorevole.
        // Non deve mai bloccare o far fallire il pagamento già eseguito.
        try {
            $toCompany = $pr->toAccount?->company;
            if ($toCompany) {
                $webhookService->dispatch('payment_request.paid', [
                    'uuid'                => $pr->uuid,
                    'token'                => $pr->token,
                    'kind'                 => $pr->kind,
                    'external_reference'   => $pr->external_reference,
                    'amount'               => (int) $pr->amount,
                    'currency'             => 'KY',
                    'description'          => $pr->description,
                    'status'               => 'paid',
                    'paid_at'              => $prFresh->paid_at?->toIso8601String(),
                    'transfer_uuid'        => $transfer->uuid,
                    'payer_account_number' => $fromAccount->account_number,
                ], $toCompany);
            }
        } catch (\Throwable $e) {
            Log::error('webhook.payment_request_paid_dispatch_failed', [
                'payment_request_id' => $pr->id,
                'error'               => $e->getMessage(),
            ]);
        }

        // E all'APPLICAZIONE che ha creato l'ordine. Il webhook qui sopra va al
        // venditore; questo va a kshop, che il venditore non è. Serve perché
        // l'utente può chiudere la finestra subito dopo aver pagato: senza,
        // l'ordine resterebbe "in attesa" su kshop fino al primo retry.
        if ($ordine !== null) {
            try {
                $this->clientWebhooks->dispatch($ordine->client_id, 'payment_request.paid', [
                    'payment_request_uuid' => $pr->uuid,
                    'external_order_uuid'  => $ordine->external_order_uuid,
                    'idempotency_key'      => $ordine->idempotency_key,
                    'mandate_uuid'         => $ordine->mandate?->uuid,
                    'amount'               => (int) $pr->amount,
                    'currency'             => 'KY',
                    'status'               => 'paid',
                    'confirmed_by_user'    => true,
                    'paid_at'              => $prFresh->paid_at?->toIso8601String(),
                    'transfer_uuid'        => $transfer->uuid,
                    'seller_account_number' => $ordine->seller_account_number,
                    'payer_account_number' => $fromAccount->account_number,
                ]);
            } catch (\Throwable $e) {
                Log::error('client_webhook.payment_request_paid_dispatch_failed', [
                    'payment_request_id' => $pr->id,
                    'error'              => $e->getMessage(),
                ]);
            }
        }

        // Notifica al commerciante (destinatario)
        $toAccount  = $pr->toAccount;
        $toOwner    = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();

        if ($toOwner) {
            Mail::to($toOwner->email)->queue(
                new PaymentReceived(
                    recipient:    $toOwner,
                    transfer:     $transfer,
                    fromAccount:  $fromAccount,
                    toAccount:    $toAccount,
                    balanceAfter: (int) $toAccount->fresh()->available_balance,
                )
            );
            $toOwner->notify(new PaymentReceivedNotification(
                transfer:    $transfer,
                fromAccount: $fromAccount,
                toAccount:   $toAccount,
            ));
        }

        // Se la richiesta è stata creata via API e-commerce con un return_url,
        // riporta il cliente sul sito del negoziante invece che sulla dashboard KMoney.
        if ($pr->return_url) {
            $separator = str_contains($pr->return_url, '?') ? '&' : '?';

            return redirect()->away($pr->return_url . $separator . http_build_query([
                'kmoney_status'      => 'paid',
                'kmoney_pr_uuid'     => $pr->uuid,
                'kmoney_transfer_uuid' => $transfer->uuid,
            ]));
        }

        return redirect()->route('portal.dashboard')
            ->with('portal_success', 'Pagamento di ' . ky_format($pr->amount) . ' KY eseguito con successo!');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with(['company', 'ownerUser', 'parentAccount'])
                ->findOrFail($user->managed_account_id);
            return $sub->parentAccount ?? $sub;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->firstOrFail();
    }
}
