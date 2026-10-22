<?php

namespace App\Services;

use App\Models\Account;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\KyCardSubscription;
use App\Models\User;
use App\Notifications\KyCardSubscriptionAdminAlert;
use App\Notifications\KyCardSubscriptionPaymentFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * KYCard a rate e a rinnovo mensile, con addebito automatico con carta
 * (Stripe Subscriptions).
 *
 * LE REGOLE (decise da Laura il 22/10/2026)
 *
 *  - RATE. Il cliente paga N rate mensili dello stesso importo, senza
 *    maggiorazioni. I KY della card arrivano TUTTI con la PRIMA rata; le rate
 *    successive sono solo incassi. Anche i punti MLM e la base commissionabile
 *    si assegnano tutti alla prima rata, sul prezzo intero della card.
 *  - RINNOVO MENSILE. Ogni mese si addebita il prezzo della card e si
 *    accreditano i suoi KY, come un acquisto normale (punti compresi).
 *  - INSOLUTO. Stripe riprova per qualche giorno (impostazione nella
 *    dashboard Stripe); se la rata resta non pagata il piano si chiude come
 *    `defaulted`, l'admin riceve un alert e il CONTO NON SI BLOCCA.
 *  - Ogni fattura pagata e' UNA riga di ky_card_purchases con
 *    stripe_invoice_id UNIVOCO: Stripe rispedisce gli eventi, e il secondo
 *    arrivo non deve accreditare niente.
 *
 * L'UNICA strada di accredito e' invoice.paid. checkout.session.completed
 * serve solo a collegare l'abbonamento Stripe alla nostra riga.
 */
class KyCardSubscriptionService
{
    /**
     * Giorni dopo l'inizio dell'ultimo periodo a cui Stripe ferma da solo
     * l'abbonamento a rate: oltre i tentativi di riscossione (circa 2
     * settimane) e prima del periodo successivo (mese piu' corto = 28 gg).
     */
    private const CANCEL_AT_OFFSET_DAYS = 25;

    public function __construct(
        private readonly StripeSubscriptionGateway $stripe,
        private readonly KyCardCreditService $credit,
    ) {}

    // ── Avvio ──────────────────────────────────────────────────────────────

    /**
     * Crea la riga `pending` e la sessione Stripe Checkout in modalita'
     * abbonamento. Ritorna [abbonamento, url di pagamento].
     *
     * @return array{0: KyCardSubscription, 1: string}
     */
    public function startCheckout(
        KyCard $card,
        Account $account,
        User $user,
        string $kind,
        ?int $installments,
        string $successUrl,
        string $cancelUrl,
    ): array {
        abort_unless($card->is_active, 404);

        if ($kind === KyCardSubscription::KIND_INSTALLMENTS) {
            abort_unless(
                $installments !== null && in_array($installments, $card->availableInstallmentOptions(), true),
                422,
                'Numero di rate non disponibile per questa card.'
            );
            $amountCents = intdiv((int) $card->price_eur_cents, $installments);
        } elseif ($kind === KyCardSubscription::KIND_RECURRING) {
            abort_unless($card->recurring_enabled, 422, 'Rinnovo mensile non disponibile per questa card.');
            $installments = null;
            $amountCents  = (int) $card->price_eur_cents;
        } else {
            abort(422, 'Tipo di pagamento non valido.');
        }

        $sub = KyCardSubscription::create([
            'ky_card_id'         => $card->id,
            'account_id'         => $account->id,
            'user_id'            => $user->id,
            'kind'               => $kind,
            'installments_total' => $installments,
            'installments_paid'  => 0,
            'amount_cents'       => $amountCents,
            'card_price_cents'   => (int) $card->price_eur_cents,
            'ky_total'           => (int) $card->ky_total,
            'status'             => KyCardSubscription::PENDING,
        ]);

        $productName = $kind === KyCardSubscription::KIND_INSTALLMENTS
            ? $card->name . ' — ' . $installments . ' rate mensili'
            : $card->name . ' — rinnovo mensile';

        try {
            $session = $this->stripe->createCheckoutSession([
                'mode'                 => 'subscription',
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'    => 'eur',
                        'unit_amount' => $amountCents,
                        'recurring'   => ['interval' => 'month'],
                        'product_data' => ['name' => $productName],
                    ],
                ]],
                'client_reference_id'  => $sub->uuid,
                'metadata'             => ['ky_sub_uuid' => $sub->uuid],
                'subscription_data'    => ['metadata' => ['ky_sub_uuid' => $sub->uuid]],
                'customer_email'       => $user->email,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
            ]);

            $sub->update(['stripe_checkout_session_id' => $session->id]);

            return [$sub, (string) $session->url];
        } catch (\Throwable $e) {
            $sub->update(['status' => KyCardSubscription::CANCELLED, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::error('KyCard subscription checkout error', ['sub' => $sub->uuid, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ── Webhook: checkout.session.completed ────────────────────────────────

    /** Collega cliente e abbonamento Stripe alla nostra riga. Non accredita nulla. */
    public function handleCheckoutCompleted(object $session): bool
    {
        $sessionId = (string) data_get($session, 'id');
        $sub = $sessionId !== ''
            ? KyCardSubscription::where('stripe_checkout_session_id', $sessionId)->first()
            : null;

        if (! $sub) {
            return false;
        }

        $update = [];
        if (! $sub->stripe_subscription_id && ($id = $this->idOf(data_get($session, 'subscription')))) {
            $update['stripe_subscription_id'] = $id;
        }
        if (! $sub->stripe_customer_id && ($id = $this->idOf(data_get($session, 'customer')))) {
            $update['stripe_customer_id'] = $id;
        }
        if ($update) {
            $sub->update($update);
        }

        return true;
    }

    // ── Webhook: invoice.paid ──────────────────────────────────────────────

    public function handleInvoicePaid(object $invoice): void
    {
        $invoiceId = (string) data_get($invoice, 'id');
        $stripeSubId = $this->subscriptionIdOfInvoice($invoice);

        if ($invoiceId === '' || ! $stripeSubId) {
            return; // fattura non di un abbonamento: non e' roba nostra
        }

        $sub = $this->resolveSubscription($stripeSubId);
        if (! $sub) {
            return; // abbonamento di un altro prodotto (piani, quote...): non e' roba nostra
        }

        // Evento gia' visto: non si riaccredita, ma se la volta scorsa ci
        // siamo fermati a meta' (riga pending/failed) si RIPROVA — creditKy e'
        // idempotente sul transfer.
        $existing = KyCardPurchase::where('stripe_invoice_id', $invoiceId)->first();
        if ($existing) {
            $this->finalize($existing->loadMissing('subscription'));

            return;
        }

        // Si controlla la fattura prima di creare moneta: pagata, in euro,
        // dell'importo esatto della rata.
        if (data_get($invoice, 'status') !== 'paid'
            || strtolower((string) data_get($invoice, 'currency')) !== 'eur'
            || (int) data_get($invoice, 'amount_paid') !== (int) $sub->amount_cents) {
            Log::error('KyCard subscription: fattura non conforme, nessun accredito', [
                'sub'       => $sub->uuid,
                'invoice'   => $invoiceId,
                'status'    => data_get($invoice, 'status'),
                'currency'  => data_get($invoice, 'currency'),
                'pagato'    => data_get($invoice, 'amount_paid'),
                'atteso'    => $sub->amount_cents,
            ]);
            $this->alertAdmins('Fattura KYCard non conforme: nessun accredito', [
                'Abbonamento ' . $sub->uuid . ' — fattura ' . $invoiceId . '.',
                'Importo pagato ' . (int) data_get($invoice, 'amount_paid') . ' centesimi, atteso ' . (int) $sub->amount_cents . '.',
                'Controllare su Stripe: se il cliente ha pagato, accreditare a mano.',
            ]);

            return;
        }

        $purchase = null;
        $isFirst  = false;
        $isLast   = false;

        try {
            DB::transaction(function () use ($sub, $invoice, $invoiceId, $stripeSubId, &$purchase, &$isFirst, &$isLast) {
                $locked = KyCardSubscription::whereKey($sub->id)->lockForUpdate()->first();

                if (KyCardPurchase::where('stripe_invoice_id', $invoiceId)->exists()) {
                    return;
                }

                // Pagamento in piu' di quelli previsti (rate gia' tutte pagate):
                // soldi incassati che non corrispondono a niente. Non si
                // accredita, si avvisa l'admin che deve rimborsare.
                if ($locked->isInstallments() && $locked->installments_paid >= (int) $locked->installments_total) {
                    $this->alertAdmins('Addebito in eccesso su KYCard a rate', [
                        'Abbonamento ' . $locked->uuid . ': tutte le ' . $locked->installments_total . ' rate risultano gia\' pagate.',
                        'Fattura ' . $invoiceId . ' incassata in piu\': nessun KY accreditato, da rimborsare su Stripe.',
                    ]);

                    return;
                }

                $n       = $locked->installments_paid + 1;
                $isFirst = $n === 1;
                $isLast  = $locked->isInstallments() && $n >= (int) $locked->installments_total;

                // RATE: tutti i KY con la prima rata, zero con le altre.
                // RINNOVO: i KY della card a ogni addebito.
                $kySlice = $locked->isInstallments()
                    ? ($isFirst ? (int) $locked->ky_total : 0)
                    : (int) $locked->ky_total;

                $purchase = KyCardPurchase::create([
                    'ky_card_id'               => $locked->ky_card_id,
                    'account_id'               => $locked->account_id,
                    'user_id'                  => $locked->user_id,
                    'price_eur_cents'          => (int) $locked->amount_cents,
                    'ky_amount'                => $kySlice,
                    'status'                   => 'pending',
                    'payment_method'           => 'stripe',
                    'ky_card_subscription_id'  => $locked->id,
                    'installment_no'           => $n,
                    'stripe_invoice_id'        => $invoiceId,
                    'stripe_payment_intent_id' => $this->idOf(data_get($invoice, 'payment_intent')),
                ]);

                $periodEnd = data_get($invoice, 'lines.data.0.period.end');

                $locked->forceFill([
                    'installments_paid'      => $n,
                    'stripe_subscription_id' => $locked->stripe_subscription_id ?: $stripeSubId,
                    'stripe_customer_id'     => $locked->stripe_customer_id ?: $this->idOf(data_get($invoice, 'customer')),
                    'started_at'             => $locked->started_at ?: now(),
                    'next_billing_at'        => (! $isLast && is_numeric($periodEnd)) ? CarbonImmutable::createFromTimestamp((int) $periodEnd) : null,
                    'status'                 => $isLast
                        ? KyCardSubscription::COMPLETED
                        : ($locked->isClosed() || $locked->status === KyCardSubscription::CANCELLING ? $locked->status : KyCardSubscription::ACTIVE),
                    'ended_at'               => $isLast ? now() : $locked->ended_at,
                    'last_error'             => null,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            return; // due consegne dello stesso evento in corsa: l'altra ha vinto
        }

        if (! $purchase) {
            return;
        }

        $this->finalize($purchase->load('subscription'));

        // ── Tetto alle rate, lato Stripe ──
        if ($isLast) {
            $this->safely(fn () => $this->stripe->cancelNow($stripeSubId), 'cancelNow dopo ultima rata', $sub);
        } elseif ($isFirst && $sub->isInstallments()) {
            $periodStart = data_get($invoice, 'lines.data.0.period.start') ?? data_get($invoice, 'period_start');
            $start = is_numeric($periodStart) ? CarbonImmutable::createFromTimestamp((int) $periodStart) : CarbonImmutable::now();
            $cancelAt = $start
                ->addMonthsNoOverflow(max(0, (int) $sub->installments_total - 1))
                ->addDays(self::CANCEL_AT_OFFSET_DAYS)
                ->getTimestamp();

            $this->safely(fn () => $this->stripe->setCancelAt($stripeSubId, $cancelAt), 'cancel_at dopo prima rata', $sub);
        }
    }

    /**
     * Porta a termine una riga nata da una fattura pagata: accredita i KY (se
     * ne porta) con la base MLM giusta, oppure la chiude come incasso senza KY.
     * Idempotente: puo' girare piu' volte sulla stessa riga.
     */
    public function finalize(KyCardPurchase $purchase): void
    {
        if ($purchase->isCompleted() || $purchase->isRefunded()) {
            return;
        }

        if ((int) $purchase->ky_amount > 0) {
            $sub = $purchase->subscription;

            // Base MLM: solo la prima rata di un acquisto a rate usa il prezzo
            // intero della card. Negli altri casi vale l'importo incassato.
            $mlmBase = ($sub && $sub->isInstallments() && (int) $purchase->installment_no === 1)
                ? (int) $sub->card_price_cents
                : null;

            $this->credit->credit($purchase, $mlmBase);

            return;
        }

        // Rata senza KY (le successive alla prima): e' solo un incasso.
        $purchase->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /** «Riprova» dell'admin su un accredito fallito di una fattura gia' pagata. */
    public function retryCredit(KyCardPurchase $purchase): void
    {
        abort_unless($purchase->stripe_invoice_id && $purchase->ky_card_subscription_id, 422);

        if ($purchase->isFailed()) {
            $purchase->update(['status' => 'pending']);
        }

        $this->finalize($purchase->fresh('subscription'));
    }

    // ── Webhook: invoice.payment_failed ────────────────────────────────────

    public function handleInvoicePaymentFailed(object $invoice): void
    {
        $stripeSubId = $this->subscriptionIdOfInvoice($invoice);
        $sub = $stripeSubId ? $this->resolveSubscription($stripeSubId) : null;

        if (! $sub || $sub->isClosed()) {
            return;
        }

        $attempt = (int) data_get($invoice, 'attempt_count', 1);
        $next    = data_get($invoice, 'next_payment_attempt');

        $sub->update([
            'status'     => $sub->status === KyCardSubscription::CANCELLING ? $sub->status : KyCardSubscription::PAST_DUE,
            'last_error' => mb_substr((string) (data_get($invoice, 'last_finalization_error.message') ?: 'Pagamento rifiutato'), 0, 500),
        ]);

        // Al cliente solo al primo tentativo: Stripe riprova piu' volte e non
        // si vuole una mail a ogni riprova.
        if ($attempt <= 1) {
            $this->safely(fn () => $sub->user->notify(new KyCardSubscriptionPaymentFailed($sub->loadMissing('kyCard'))), 'notifica cliente', $sub);
        }

        // Nessun altro tentativo in programma: la rata e' persa.
        if ($next === null) {
            $this->markUnpaid($sub, $stripeSubId);
        }
    }

    // ── Webhook: customer.subscription.deleted ─────────────────────────────

    public function handleSubscriptionDeleted(object $stripeSubscription): void
    {
        $sub = $this->resolveSubscription((string) data_get($stripeSubscription, 'id'));

        if (! $sub || $sub->isClosed()) {
            return;
        }

        if ($sub->isInstallments() && $sub->installments_paid >= (int) $sub->installments_total) {
            $sub->update(['status' => KyCardSubscription::COMPLETED, 'ended_at' => $sub->ended_at ?: now()]);

            return;
        }

        $this->markUnpaid($sub, null);
    }

    // ── Webhook: charge.dispute.created ────────────────────────────────────

    /**
     * Il titolare della carta ha contestato un addebito. Non si tocca nessun
     * saldo: si avvisa il backoffice, che ha pochi giorni per rispondere a
     * Stripe con le prove.
     */
    public function handleDispute(object $dispute): void
    {
        $paymentIntent = $this->idOf(data_get($dispute, 'payment_intent'));
        $purchase = $paymentIntent
            ? KyCardPurchase::where('stripe_payment_intent_id', $paymentIntent)->first()
            : null;

        $lines = [
            'Contestazione ' . data_get($dispute, 'id') . ' — importo ' . number_format(((int) data_get($dispute, 'amount')) / 100, 2, ',', '.') . ' ' . strtoupper((string) data_get($dispute, 'currency', 'eur')) . '.',
            'Motivo: ' . (data_get($dispute, 'reason') ?: 'non indicato') . '. Charge: ' . (data_get($dispute, 'charge') ?: '—') . '.',
            $purchase
                ? 'Corrisponde all\'acquisto KYCard ' . $purchase->uuid . ' (conto #' . $purchase->account_id . '). I KY accreditati NON sono stati toccati.'
                : 'Nessun acquisto KYCard collegato: puo\' riguardare un altro incasso Stripe.',
            'Rispondere su Stripe entro la scadenza indicata nella contestazione.',
        ];

        Log::warning('Stripe dispute creata', ['dispute' => data_get($dispute, 'id'), 'purchase' => $purchase?->uuid]);
        $this->alertAdmins('Contestazione (chargeback) su un pagamento con carta', $lines);
    }

    // ── Gestione manuale ───────────────────────────────────────────────────

    /** Disdetta del cliente: solo il rinnovo mensile, e vale a fine periodo. */
    public function cancelByCustomer(KyCardSubscription $sub): void
    {
        abort_unless($sub->isRecurring(), 403, 'Un pagamento a rate non si puo\' disdire.');
        abort_if($sub->isClosed() || $sub->status === KyCardSubscription::CANCELLING, 422);

        if ($sub->stripe_subscription_id) {
            $this->stripe->cancelAtPeriodEnd($sub->stripe_subscription_id);
        }

        $sub->update(['status' => KyCardSubscription::CANCELLING]);
    }

    /** Annullamento dell'admin: ferma subito ogni addebito futuro. */
    public function cancelByAdmin(KyCardSubscription $sub): void
    {
        abort_if($sub->isClosed(), 422);

        if ($sub->stripe_subscription_id) {
            $this->stripe->cancelNow($sub->stripe_subscription_id);
        }

        $sub->update(['status' => KyCardSubscription::CANCELLED, 'ended_at' => now()]);
    }

    // ── Interni ────────────────────────────────────────────────────────────

    private function markUnpaid(KyCardSubscription $sub, ?string $stripeSubId): void
    {
        $wasInstallments = $sub->isInstallments();

        $sub->update([
            'status'   => $wasInstallments ? KyCardSubscription::DEFAULTED : KyCardSubscription::CANCELLED,
            'ended_at' => now(),
        ]);

        // Si ferma anche lato Stripe: nessun altro tentativo.
        if ($stripeSubId) {
            $this->safely(fn () => $this->stripe->cancelNow($stripeSubId), 'cancelNow dopo insoluto', $sub);
        }

        $this->alertAdmins(
            $wasInstallments ? 'Rata KYCard non pagata' : 'Rinnovo KYCard non pagato: abbonamento chiuso',
            array_filter([
                'Abbonamento ' . $sub->uuid . ' — conto #' . $sub->account_id . ' — utente #' . $sub->user_id . '.',
                $wasInstallments
                    ? 'Rate pagate: ' . $sub->installments_paid . ' su ' . $sub->installments_total . '. I KY della card sono gia\' stati accreditati con la prima rata.'
                    : 'Rinnovi pagati: ' . $sub->installments_paid . '.',
                'Il conto NON e\' stato bloccato. Stripe ha esaurito i tentativi.',
            ])
        );
    }

    private function resolveSubscription(string $stripeSubscriptionId): ?KyCardSubscription
    {
        if ($stripeSubscriptionId === '') {
            return null;
        }

        $sub = KyCardSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
        if ($sub) {
            return $sub;
        }

        // Il primo invoice.paid puo' arrivare PRIMA di checkout.session.completed:
        // la riga non ha ancora l'id Stripe. Lo si legge dalla metadata che
        // abbiamo messo noi sull'abbonamento.
        try {
            $stripeSub = $this->stripe->retrieveSubscription($stripeSubscriptionId);
            $uuid = data_get($stripeSub, 'metadata.ky_sub_uuid');
            if (! $uuid) {
                return null;
            }

            $sub = KyCardSubscription::where('uuid', $uuid)->first();
            if ($sub && ! $sub->stripe_subscription_id) {
                $sub->update(['stripe_subscription_id' => $stripeSubscriptionId]);
            }

            return $sub;
        } catch (\Throwable $e) {
            Log::warning('KyCard subscription: abbonamento Stripe non recuperabile', [
                'stripe_subscription' => $stripeSubscriptionId,
                'error'               => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** L'id dell'abbonamento, sia con la forma vecchia dell'API sia con quella nuova. */
    private function subscriptionIdOfInvoice(object $invoice): ?string
    {
        return $this->idOf(data_get($invoice, 'subscription'))
            ?: $this->idOf(data_get($invoice, 'parent.subscription_details.subscription'));
    }

    /** Stripe a volte da' l'id come stringa, a volte come oggetto espanso. */
    private function idOf(mixed $value): ?string
    {
        if (is_object($value)) {
            $value = data_get($value, 'id');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Un passo accessorio che non deve mai far fallire il webhook, ma neppure passare sotto silenzio. */
    private function safely(callable $fn, string $what, KyCardSubscription $sub): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('KyCard subscription: ' . $what . ' fallito', ['sub' => $sub->uuid, 'error' => $e->getMessage()]);
            $sub->update(['last_error' => mb_substr($what . ': ' . $e->getMessage(), 0, 500)]);

            if (str_contains($what, 'cancel_at') || str_contains($what, 'cancelNow')) {
                $this->alertAdmins('Controllo manuale su un abbonamento KYCard', [
                    'Abbonamento ' . $sub->uuid . ': non sono riuscito a completare "' . $what . '" su Stripe.',
                    'Verificare su Stripe che non partano addebiti oltre il previsto.',
                    'Errore: ' . $e->getMessage(),
                ]);
            }
        }
    }

    private function alertAdmins(string $title, array $lines): void
    {
        try {
            User::query()
                ->where('is_super_admin', true)
                ->whereNotNull('email')
                ->get()
                ->each(fn (User $admin) => $admin->notify(new KyCardSubscriptionAdminAlert($title, $lines)));
        } catch (\Throwable $e) {
            Log::error('KyCard subscription: alert admin fallito', ['title' => $title, 'error' => $e->getMessage()]);
        }
    }
}
