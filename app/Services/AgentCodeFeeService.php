<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AgentCodeFeePayment;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Quota per il CODICE AGENTE (richiesta di Laura del 31/08/2026).
 *
 * DOVE SI INCASTRA. Il percorso agente e': richiesta -> l'admin approva
 * (mlm_agent_request_status = 'approved') -> firma del contratto di nomina
 * con OTP -> mlm_role diventa 'agente'. La quota si mette FRA l'approvazione
 * e la firma: chi non ha pagato non arriva alla firma, e siccome si diventa
 * agente solo firmando, non esiste un istante in cui qualcuno opera da agente
 * senza aver pagato. Non serve un secondo blocco a valle.
 *
 * DUE DIFFERENZE VERE dalla quota di iscrizione dei privati — il resto della
 * meccanica e' volutamente identico:
 *
 *   1. In EURO non si ricevono KY (decisione di Laura). I 480 sono il prezzo
 *      del codice, non una ricarica: KNM incassa e basta, l'agente non parte
 *      con 480 KY di plafond coniati dal circuito. E' il motivo per cui qui
 *      non esiste nessun completeEuroPayment che emette moneta: il pagamento
 *      in euro si limita a saldare la quota.
 *   2. In KY invece si va sotto, come per i 30, con il fido aggiuntivo pari
 *      alla quota. ATTENZIONE: 480 KY di scoperto sono sedici volte 30, e
 *      sono moneta creata dal circuito. L'admin puo' spegnere il solo metodo
 *      KY per gli agenti lasciandolo acceso per i privati.
 *
 * QUANDO NASCE IL DEBITO. All'approvazione, da tutte e tre le porte che
 * portano ad 'approved' (approvazione admin, promozione admin, e l'agente che
 * ne registra uno sotto di se'). L'importo e' uno SCATTO: se domani l'admin
 * porta la quota da 480 a 600, chi e' stato approvato a 480 deve 480.
 */
class AgentCodeFeeService
{
    public function __construct(
        private readonly TransferBookingService $transfers,
        private readonly RegistrationFeeService $registrationFees,
    ) {
    }

    public function settings(): SystemSetting
    {
        return SystemSetting::userLimitDefaults();
    }

    // ── Nascita del debito ──────────────────────────────────────────────────

    /**
     * Chiamato dalle tre porte che approvano una richiesta agente. Non
     * bloccante: se qualcosa va storto qui, l'approvazione deve comunque
     * riuscire — una quota da segnare a mano e' un problema recuperabile,
     * un'approvazione persa a meta' no.
     */
    public function markDueOnApproval(User $user): void
    {
        try {
            $settings = $this->settings();

            if (! $settings->agentCodeFeeEnabled()) {
                return;
            }

            // Chi e' gia' agente non deve niente: la quota si paga per
            // DIVENTARLO. Vale per gli agenti che esistono gia' oggi.
            if ($user->isMlmAgent()) {
                return;
            }

            // Gia' segnata (o gia' pagata) da un'approvazione precedente:
            // riapprovare non raddoppia il debito ne' lo azzera.
            if ($user->agent_code_fee_due_cents !== null) {
                return;
            }

            $user->forceFill([
                'agent_code_fee_due_cents' => $settings->agentCodeFeeAmount(),
                'agent_code_fee_paid_at'   => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Quota codice agente: impossibile marcare il debito', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Stato ───────────────────────────────────────────────────────────────

    public function isDueFor(?User $user): bool
    {
        return $user !== null
            && $user->agent_code_fee_due_cents !== null
            && (int) $user->agent_code_fee_due_cents > 0
            && $user->agent_code_fee_paid_at === null;
    }

    public function amountDueFor(User $user): int
    {
        return max(0, (int) ($user->agent_code_fee_due_cents ?? 0));
    }

    public function accountFor(User $user): ?Account
    {
        return Account::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();
    }

    // ── Rinuncia ────────────────────────────────────────────────────────────

    /**
     * "Non voglio piu' diventare agente." Annulla la richiesta approvata e
     * cancella il debito: l'utente torna cliente normale e riprende a usare
     * il conto. Potra' sempre ricandidarsi (canRequestMlmAgent() guarda
     * proprio mlm_agent_request_status).
     *
     * Non tocca il fido aggiuntivo: se aveva gia' pagato in KY questa strada
     * e' chiusa (isDueFor sarebbe falso e il controller non arriva qui), e
     * chi non ha pagato non ha nessun fido aggiuntivo da togliere.
     */
    public function giveUp(User $user, ?string $ipAddress = null): void
    {
        if (! $this->isDueFor($user)) {
            throw new RuntimeException('Non c\'è nessuna quota da annullare.');
        }

        $user->forceFill([
            'mlm_agent_request_status'   => 'cancelled',
            'mlm_agent_rejection_reason' => 'Rinuncia dell\'interessato prima del pagamento della quota codice.',
            'agent_code_fee_due_cents'   => null,
            'agent_code_fee_paid_at'     => null,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_request.given_up',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $ipAddress,
            'context'        => [],
        ]);

        // Chi era entrato dalla porta dell'agente aveva la quota dei privati
        // SOSPESA (01/09/2026): rinunciando torna un privato come tutti gli
        // altri e quella quota si accende. Senza questa riga il portale
        // dell'agente sarebbe il modo per entrare nel circuito senza pagare
        // niente: ci si fa registrare, si rinuncia, e non si deve piu' nulla.
        $this->registrationFees->resumeAfterAgentPath($user->refresh(), $ipAddress);
    }

    /**
     * Cancella il debito del codice agente quando NON e' piu' dovuto perche'
     * il percorso si e' chiuso dall'altra parte: l'admin ha rifiutato la
     * richiesta (Admin\MlmAgentRequestController::reject).
     *
     * Senza questo, un rifiuto dopo l'approvazione lasciava addosso una quota
     * da 480 per un codice che non arrivera' mai — con il conto bloccato e
     * una pagina che invita a pagarla.
     *
     * Non tocca una quota gia' PAGATA: li' ci sono soldi veri incassati e la
     * decisione (rimborso? codice comunque?) non la puo' prendere una riga di
     * codice dentro un rifiuto.
     *
     * @return bool se c'era davvero un debito da cancellare
     */
    public function dropUnpaidDebt(User $user, ?string $ipAddress = null): bool
    {
        if (! $this->isDueFor($user)) {
            return false;
        }

        $user->forceFill([
            'agent_code_fee_due_cents' => null,
            'agent_code_fee_paid_at'   => null,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'agent_code_fee.dropped',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $ipAddress,
            'context'        => [],
        ]);

        return true;
    }

    // ── Apertura di un tentativo di pagamento ───────────────────────────────

    /** @throws RuntimeException */
    public function startPayment(User $user, string $method): AgentCodeFeePayment
    {
        if (! $this->isDueFor($user)) {
            throw new RuntimeException('La quota per il codice agente risulta già saldata.');
        }

        if (! array_key_exists($method, $this->settings()->agentCodeFeeMethods())) {
            throw new RuntimeException('Metodo di pagamento non disponibile.');
        }

        $amount = $this->amountDueFor($user);

        return AgentCodeFeePayment::create([
            'user_id'          => $user->id,
            'account_id'       => $this->accountFor($user)?->id,
            'amount_eur_cents' => $amount,
            'ky_amount'        => $amount,
            'status'           => $method === AgentCodeFeePayment::METHOD_BANK_TRANSFER
                ? AgentCodeFeePayment::STATUS_PENDING_BANK_TRANSFER
                : AgentCodeFeePayment::STATUS_PENDING,
            'payment_method'   => $method,
        ]);
    }

    // ── Pagamento in KY ─────────────────────────────────────────────────────

    /** @throws RuntimeException */
    public function payWithKy(User $user, ?string $ipAddress = null): AgentCodeFeePayment
    {
        $payment = $this->startPayment($user, AgentCodeFeePayment::METHOD_KY);

        $account = $this->accountFor($user);
        if ($account === null) {
            $this->markFailed($payment, 'Nessun conto attivo trovato.');
            throw new RuntimeException('Nessun conto attivo trovato per il tuo profilo.');
        }

        $systemAccount = Account::systemAccount();
        if ($systemAccount === null) {
            $this->markFailed($payment, 'Conto di sistema non disponibile.');
            throw new RuntimeException('Conto di sistema non disponibile: riprova più tardi.');
        }

        $amount = (int) $payment->ky_amount;

        try {
            DB::transaction(function () use ($user, $payment, $account, $systemAccount, $amount, $ipAddress): void {
                $locked = User::whereKey($user->id)->lockForUpdate()->first();

                if (! $this->isDueFor($locked)) {
                    throw new RuntimeException('La quota per il codice agente risulta già saldata.');
                }

                // Il fido aggiuntivo PRIMA dell'addebito: senza, il motore
                // rifiuterebbe di portare a -480 un conto con fido zero.
                $locked->forceFill([
                    'agent_code_fee_ky_allowance_cents' => $amount,
                ])->save();

                $transfer = $this->transfers->book([
                    'initiated_by'    => $user->id,
                    'from_account_id' => $account->id,
                    'to_account_id'   => $systemAccount->id,
                    'amount'          => $amount,
                    'kind'            => 'agent_code_fee',
                    'description'     => 'Quota per il codice agente KNM',
                    'idempotency_key' => 'agentcode_' . $payment->uuid,
                    'ip_address'      => $ipAddress,
                ]);

                $locked->forceFill(['agent_code_fee_paid_at' => now()])->save();

                $payment->update([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $account->id,
                    'status'       => AgentCodeFeePayment::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                AuditLog::create([
                    'actor_user_id'  => $user->id,
                    'event'          => 'agent_code_fee.paid_in_ky',
                    'auditable_type' => AgentCodeFeePayment::class,
                    'auditable_id'   => $payment->id,
                    'ip_address'     => $ipAddress,
                    'context'        => [
                        'uuid'        => $payment->uuid,
                        'amount'      => $amount,
                        'transfer_id' => $transfer->id,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            $this->markFailed($payment, $e->getMessage());
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $user->refresh();

        return $payment->refresh();
    }

    // ── Pagamento in euro: NESSUN KY viene emesso ───────────────────────────

    /**
     * Chiamato quando un pagamento in euro risulta incassato (Stripe, PayPal,
     * o bonifico confermato dall'admin).
     *
     * Qui NON si muove un solo KY, ed e' la differenza sostanziale con la
     * quota dei privati: i 480 euro sono il prezzo del codice, non una
     * ricarica. Il conto dell'agente non viene toccato affatto.
     *
     * Idempotente sullo stato del pagamento, sotto lock: senza un transfer da
     * scrivere non c'e' nessuna idempotency_key a fare da seconda difesa, e
     * questa e' l'unica che c'e' — motivo per cui il lock non e' facoltativo.
     * La corsa vera esiste eccome: webhook Stripe e pagina di successo
     * possono arrivare insieme (e' quello che il 31/08 ha fatto assegnare i
     * punti MLM due volte).
     */
    public function completeEuroPayment(AgentCodeFeePayment $payment, ?int $confirmedBy = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $user = $payment->user;
        if ($user === null) {
            Log::error('Quota codice agente: utente mancante', ['payment' => $payment->uuid]);
            $this->markFailed($payment, 'Utente non disponibile.');

            return;
        }

        try {
            DB::transaction(function () use ($payment, $user, $confirmedBy): void {
                $locked = AgentCodeFeePayment::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked === null || $locked->isCompleted()) {
                    return;
                }

                $locked->update([
                    'status'       => AgentCodeFeePayment::STATUS_COMPLETED,
                    'confirmed_by' => $confirmedBy,
                    'completed_at' => now(),
                ]);

                User::whereKey($user->id)
                    ->whereNull('agent_code_fee_paid_at')
                    ->update(['agent_code_fee_paid_at' => now()]);

                AuditLog::create([
                    'actor_user_id'  => $confirmedBy ?? $user->id,
                    'event'          => 'agent_code_fee.paid_in_eur',
                    'auditable_type' => AgentCodeFeePayment::class,
                    'auditable_id'   => $locked->id,
                    'context'        => [
                        'uuid'           => $locked->uuid,
                        'amount'         => (int) $locked->amount_eur_cents,
                        'payment_method' => $locked->payment_method,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Quota codice agente: chiusura del pagamento fallita', [
                'payment' => $payment->uuid,
                'error'   => $e->getMessage(),
            ]);
            $this->markFailed($payment, $e->getMessage());

            return;
        }

        $payment->refresh();
    }

    public function markFailed(AgentCodeFeePayment $payment, ?string $reason = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $payment->update([
            'status'      => AgentCodeFeePayment::STATUS_FAILED,
            'admin_notes' => $reason ?? $payment->admin_notes,
        ]);
    }
}
