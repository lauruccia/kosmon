<?php

namespace App\Services;

use App\Exceptions\Financial\FinancialException;
use App\Exceptions\MandateException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\MandateChargedNotification;
use App\Notifications\MandateSuspendedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Il mandato di pagamento — fase 2a di PIANO_SHOP_ESTERNO.md (§5).
 *
 * Questo servizio NON è un secondo motore finanziario: il denaro lo muove
 * sempre e solo `TransferBookingService::book()`, con lo stesso `kind` degli
 * acquisti shop. Cashback, commissioni, MLM e partita doppia continuano quindi
 * a funzionare identici a oggi, senza che nessuno li riscriva.
 *
 * Qui dentro c'è soltanto la risposta a una domanda: **questo addebito si può
 * fare senza disturbare l'utente?** Se la risposta è no, quasi mai significa
 * "rifiutato" — significa "chiediglielo" (fase 2b).
 *
 * Le regole che questo file ha il compito di far rispettare, e che i test
 * verificano una per una:
 *
 *  1. la stessa `idempotency_key` non addebita due volte, mai
 *  2. mandato revocato, scaduto o sospeso non addebita
 *  3. sopra il tetto per transazione non si addebita da soli
 *  4. si addebita solo verso venditori già autorizzati dall'utente
 *  5. oltre 10 addebiti in un'ora il mandato si sospende DA SOLO e l'utente
 *     viene avvisato (antifurto, non un limite di spesa)
 *  6. il mandato non dà mai accesso al saldo né la facoltà di pagare qualcuno
 *     fuori dal circuito: l'unico beneficiario possibile è un conto del circuito
 *  7. ogni addebito lascia un AuditLog e una notifica all'utente
 */
class PaymentMandateService
{
    public function __construct(private readonly TransferBookingService $booking)
    {
    }

    // =========================================================================
    // Concessione e revoca
    // =========================================================================

    /**
     * Concede un mandato. Se ne esisteva già uno vivo per la stessa
     * applicazione, viene revocato: uno solo alla volta, così la pagina "App
     * collegate" dice sempre la verità e non ci sono permessi dimenticati.
     *
     * @param array<int, string> $authorizedSellers
     */
    public function grant(
        User $user,
        Account $account,
        string $clientId,
        int $maxPerTransaction,
        array $authorizedSellers = [],
        ?string $ip = null,
    ): PaymentMandate {
        $min = (int) config('oauth.mandate.min_max_per_transaction', 100);
        $max = (int) config('oauth.mandate.max_max_per_transaction', 100000);

        if ($maxPerTransaction < $min || $maxPerTransaction > $max) {
            throw new RuntimeException('Il tetto per acquisto non è nei limiti consentiti.');
        }

        return DB::transaction(function () use ($user, $account, $clientId, $maxPerTransaction, $authorizedSellers, $ip) {
            foreach ($this->activeMandatesFor($user, $clientId)->get() as $previous) {
                $this->revoke($previous, $ip, 'superseded');
            }

            $mandate = PaymentMandate::create([
                'user_id'             => $user->id,
                'account_id'          => $account->id,
                'client_id'           => $clientId,
                'max_per_transaction' => $maxPerTransaction,
                'authorized_sellers'  => array_values(array_unique($authorizedSellers)),
                'expires_at'          => now()->addMonths((int) config('oauth.mandate.expires_months', 12)),
                'created_ip'          => $ip,
            ]);

            $this->audit('mandate.granted', $user->id, $mandate, $ip, [
                'client_id'           => $clientId,
                'max_per_transaction' => $maxPerTransaction,
                'authorized_sellers'  => $mandate->authorized_sellers,
            ]);

            return $mandate;
        });
    }

    public function revoke(PaymentMandate $mandate, ?string $ip = null, string $reason = 'user_request'): void
    {
        if ($mandate->isRevoked()) {
            return;
        }

        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->audit('mandate.revoked', $mandate->user_id, $mandate, $ip, [
            'client_id' => $mandate->client_id,
            'reason'    => $reason,
        ]);
    }

    /**
     * Cambia il tetto per singolo acquisto. Alzarlo è un'azione sensibile:
     * la route che chiama questo metodo sta dietro allo step-up.
     */
    public function updateLimit(PaymentMandate $mandate, int $maxPerTransaction, ?string $ip = null): void
    {
        $min = (int) config('oauth.mandate.min_max_per_transaction', 100);
        $max = (int) config('oauth.mandate.max_max_per_transaction', 100000);

        if ($maxPerTransaction < $min || $maxPerTransaction > $max) {
            throw new RuntimeException('Il tetto per acquisto non è nei limiti consentiti.');
        }

        $precedente = $mandate->max_per_transaction;

        $mandate->forceFill(['max_per_transaction' => $maxPerTransaction])->save();

        $this->audit('mandate.limit_changed', $mandate->user_id, $mandate, $ip, [
            'client_id' => $mandate->client_id,
            'da'        => $precedente,
            'a'         => $maxPerTransaction,
        ]);
    }

    /**
     * I mandati ancora vivi di un utente per una certa applicazione.
     */
    public function activeMandatesFor(User $user, string $clientId)
    {
        return PaymentMandate::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    // =========================================================================
    // Addebito
    // =========================================================================

    /**
     * Esegue un addebito automatico.
     *
     * @param array{seller_account_number: string, amount: int, external_order_uuid?: ?string, order_title?: ?string, quantity?: int, idempotency_key: string} $payload
     * @return array{charge: PaymentMandateCharge, transfer: Transfer, repeated: bool}
     *
     * @throws MandateException quando serve la conferma dell'utente o la
     *                          richiesta non è eseguibile
     */
    public function charge(PaymentMandate $mandate, array $payload, ?string $ip = null): array
    {
        $idempotencyKey = (string) $payload['idempotency_key'];

        // ── 1. Idempotenza ────────────────────────────────────────────────
        // Prima di ogni altra cosa, prima persino di guardare se il mandato è
        // ancora valido: un retry di rete su un addebito già andato a buon fine
        // deve ricevere la stessa risposta di allora, non un errore nuovo.
        $existing = PaymentMandateCharge::query()
            ->where('payment_mandate_id', $mandate->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'charge'   => $existing,
                'transfer' => $existing->transfer,
                'repeated' => true,
            ];
        }

        // ── 2. Il mandato è ancora vivo? ──────────────────────────────────
        if ($mandate->isRevoked()) {
            throw MandateException::confirmationRequired(
                'mandate_revoked',
                'Questa autorizzazione è stata revocata: chiedi all\'utente di confermare il pagamento su KMoney.'
            );
        }

        if ($mandate->isExpired()) {
            throw MandateException::confirmationRequired(
                'mandate_expired',
                'Questa autorizzazione è scaduta: chiedi all\'utente di confermare il pagamento su KMoney.'
            );
        }

        if ($mandate->isSuspended()) {
            throw MandateException::confirmationRequired(
                'mandate_suspended',
                'Questa autorizzazione è sospesa per attività insolita: chiedi all\'utente di confermare il pagamento su KMoney.'
            );
        }

        // ── 3. Antifurto ──────────────────────────────────────────────────
        // Dieci addebiti automatici in un'ora da un solo negozio non è un
        // comportamento umano: il mandato si spegne da solo e l'utente lo
        // scopre da una notifica, non da un estratto conto.
        if ($mandate->hasHitRateLimit()) {
            $this->suspendForUnusualActivity($mandate, $ip);

            throw MandateException::confirmationRequired(
                'mandate_suspended',
                'Troppi addebiti automatici in poco tempo: l\'autorizzazione è stata sospesa per sicurezza.'
            );
        }

        $amount = (int) $payload['amount'];

        if ($amount <= 0) {
            throw MandateException::badRequest('invalid_amount', 'L\'importo deve essere maggiore di zero.');
        }

        // ── 4. Il tetto ───────────────────────────────────────────────────
        // Non è un rifiuto: è il punto in cui l'utente vuole essere consultato.
        if ($amount > $mandate->max_per_transaction) {
            throw MandateException::confirmationRequired(
                'amount_above_limit',
                'Importo sopra il tetto autorizzato per singolo acquisto: serve la conferma dell\'utente.',
                ['max_per_transaction' => $mandate->max_per_transaction],
            );
        }

        // ── 5. Il venditore ───────────────────────────────────────────────
        $sellerNumber  = (string) $payload['seller_account_number'];
        $sellerAccount = $this->resolveSellerAccount($sellerNumber);

        if ($sellerAccount->id === $mandate->account_id) {
            throw MandateException::badRequest(
                'self_purchase',
                'Non si può addebitare un acquisto sul conto del venditore stesso.'
            );
        }

        // Il mandato non autorizza a pagare "chiunque": solo i venditori che
        // l'utente ha già approvato una volta. È la protezione che sostituisce
        // il plafond di periodo (§5 del piano).
        if (! $mandate->allowsSeller($sellerNumber)) {
            throw MandateException::confirmationRequired(
                'seller_not_authorized',
                'Primo acquisto da questo venditore: serve la conferma dell\'utente.',
                ['seller_account_number' => $sellerNumber],
            );
        }

        // ── 6. Il movimento vero ──────────────────────────────────────────
        // Da qui in poi comanda la banca: saldo, fido, limiti giornalieri,
        // cashback, commissioni e MLM sono esattamente quelli di sempre.
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $title    = $payload['order_title'] ?? null;

        try {
            $transfer = $this->booking->book([
                'initiated_by'        => $mandate->user_id,
                'from_account_id'     => $mandate->account_id,
                'to_account_id'       => $sellerAccount->id,
                'amount'              => $amount,
                'kind'                => 'portal_marketplace_order',
                'description'         => $this->describe($title, $quantity),
                'quantity'            => $quantity,
                'order_title'         => $title,
                'order_source'        => Transfer::ORDER_SOURCE_KSHOP,
                'external_order_uuid' => $payload['external_order_uuid'] ?? null,
                'idempotency_key'     => $this->transferIdempotencyKey($mandate, $idempotencyKey),
                'ip_address'          => $ip,
            ]);
        } catch (FinancialException $e) {
            // Saldo, fido, limiti: l'utente può comunque completare l'acquisto
            // ricaricando o confermando a mano, quindi non è un "no" definitivo.
            throw MandateException::confirmationRequired('limit_exceeded', $e->getMessage());
        } catch (RuntimeException $e) {
            throw MandateException::confirmationRequired('payment_refused', $e->getMessage());
        }

        // ── 7. La traccia ─────────────────────────────────────────────────
        $charge = DB::transaction(function () use ($mandate, $transfer, $amount, $sellerNumber, $payload, $quantity, $title, $idempotencyKey, $ip) {
            $charge = PaymentMandateCharge::create([
                'payment_mandate_id'    => $mandate->id,
                'transfer_id'           => $transfer->id,
                'amount'                => $amount,
                'seller_account_number' => $sellerNumber,
                'external_order_uuid'   => $payload['external_order_uuid'] ?? null,
                'order_title'           => $title,
                'quantity'              => $quantity,
                'idempotency_key'       => $idempotencyKey,
                'created_ip'            => $ip,
            ]);

            $mandate->forceFill([
                'charges_count' => $mandate->charges_count + 1,
                'last_used_at'  => now(),
            ])->save();

            return $charge;
        });

        $this->audit('mandate.charged', $mandate->user_id, $mandate, $ip, [
            'client_id'     => $mandate->client_id,
            'amount'        => $amount,
            'seller'        => $sellerNumber,
            'transfer_uuid' => $transfer->uuid,
            'order_title'   => $title,
        ]);

        // Ogni addebito arriva all'utente: è l'altra metà della promessa
        // "puoi revocare quando vuoi" — per revocare bisogna prima accorgersene.
        $mandate->user?->notify(new MandateChargedNotification($mandate, $charge));

        return [
            'charge'   => $charge->fresh(),
            'transfer' => $transfer,
            'repeated' => false,
        ];
    }

    // =========================================================================

    private function resolveSellerAccount(string $accountNumber): Account
    {
        $account = Account::query()
            ->with('company')
            ->where('uuid', $accountNumber)
            ->where('is_system_account', false)
            ->first();

        if (! $account) {
            throw MandateException::badRequest('seller_unknown', 'Numero di conto del venditore non trovato.');
        }

        if ($account->owner_type !== 'company') {
            throw MandateException::badRequest('seller_not_a_company', 'Il venditore deve essere un conto aziendale del circuito.');
        }

        return $account;
    }

    private function suspendForUnusualActivity(PaymentMandate $mandate, ?string $ip): void
    {
        $mandate->forceFill(['suspended_at' => now()])->save();

        $this->audit('mandate.suspended', $mandate->user_id, $mandate, $ip, [
            'client_id'      => $mandate->client_id,
            'recent_charges' => $mandate->recentChargesCount(),
        ]);

        $mandate->user?->notify(new MandateSuspendedNotification($mandate));
    }

    private function describe(?string $title, int $quantity): string
    {
        $descrizione = 'Acquisto Kosmoshop';

        if ($title) {
            $descrizione .= ': ' . $title;
        }

        return $descrizione . ($quantity > 1 ? " (x{$quantity})" : '');
    }

    /**
     * La chiave che vede la banca è derivata da quella del negozio ma legata a
     * questo mandato: due mandati diversi non possono collidere per sbaglio
     * riusando la stessa stringa.
     */
    private function transferIdempotencyKey(PaymentMandate $mandate, string $key): string
    {
        return 'mandate:' . $mandate->uuid . ':' . Str::limit($key, 64, '');
    }

    private function audit(string $event, ?int $userId, object $subject, ?string $ip, array $context = []): void
    {
        AuditLog::create([
            'actor_user_id'  => $userId,
            'event'          => $event,
            'auditable_type' => $subject::class,
            'auditable_id'   => $subject->getKey(),
            'ip_address'     => $ip,
            'context'        => $context,
        ]);
    }
}
