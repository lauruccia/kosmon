<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AgentCodeFeePayment;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\Transfer;
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
    /**
     * ESONERO (01/09/2026). `agent_code_fee_due_cents` a ZERO vuol dire che
     * l'admin ha concesso di non pagare: l'aspirante agente puo' firmare
     * senza che nessun euro e nessun KY si muova, e senza un pagamento finto
     * in tabella a raccontare un incasso che non c'e' stato.
     *
     * ATTENZIONE, LO ZERO NON SIGNIFICA LA STESSA COSA NELLE DUE QUOTE. Nella
     * quota dei privati (RegistrationFeeService::SOSPESA) lo zero vuol dire
     * "sospesa, si riaccende se lascia il percorso agente". Qui vuol dire
     * "condonata". Stesso valore, due significati: prima di copiare una riga
     * da un servizio all'altro, guardare quale delle due si sta leggendo.
     *
     * I tre significati della colonna, per intero:
     *   NULL = non deve niente e non dovra' mai (tutti gli agenti di prima)
     *   0    = ESONERATO dall'admin
     *   > 0  = la deve, di quella cifra (lo scatto dell'approvazione)
     */
    public const ESONERATA = 0;

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

    /** L'utente ha gia' saldato la quota per il codice agente. */
    public function hasPaid(?User $user): bool
    {
        return $user !== null && $user->agent_code_fee_paid_at !== null;
    }

    /** L'admin gli ha concesso di non pagare (quota a zero, mai saldata). */
    public function isWaived(?User $user): bool
    {
        return $user !== null
            && $user->agent_code_fee_due_cents !== null
            && (int) $user->agent_code_fee_due_cents === self::ESONERATA
            && $user->agent_code_fee_paid_at === null;
    }

    /**
     * "Non voglio piu' diventare agente."
     *
     * IL PRESUPPOSTO NON E' LA QUOTA, E' IL PERCORSO. Prima (31/08) si poteva
     * rinunciare solo con una quota da pagare in sospeso: chi aveva gia'
     * pagato, o chi era stato esonerato, restava legato a un percorso che non
     * voleva piu' e senza nessun bottone per uscirne. Ora si rinuncia a una
     * richiesta APPROVATA, in qualunque stato sia la quota.
     *
     * DUE CASI, e la differenza e' tutta nei soldi (decisione di Laura,
     * 01/09/2026):
     *
     *   · quota NON pagata (dovuta o esonerata): il debito si cancella e
     *     l'utente torna cliente come se non fosse mai stato approvato;
     *   · quota GIA' PAGATA: la richiesta si chiude lo stesso, ma QUI NON SI
     *     MUOVE UN CENTESIMO. La quota resta segnata come saldata e i 480
     *     restano incassati. Se vanno restituiti, e' l'admin ad annullare la
     *     quota da /admin/quote-codice-agente — che storna, rimette la quota
     *     dovuta e toglie il fido, tutto insieme. Un rimborso non lo puo'
     *     decidere chi rinuncia, e cancellare qui la quota saldata vorrebbe
     *     dire perdere la traccia di 480 euro incassati.
     *
     * Dopo la firma non si passa di qui: il codice agente c'e' gia' e la
     * strada e' un'altra (la revoca dell'agente, che e' un altro mestiere).
     */
    public function giveUp(User $user, ?string $ipAddress = null): void
    {
        if ($user->isMlmAgent()) {
            throw new RuntimeException('Hai già firmato la nomina: la rinuncia non passa da qui.');
        }

        if ($user->mlm_agent_request_status !== 'approved') {
            throw new RuntimeException('Non c\'è nessun percorso da agente aperto da annullare.');
        }

        $quotaPagata = $this->hasPaid($user);
        $eraEsonerato = $this->isWaived($user);

        $campi = [
            'mlm_agent_request_status'   => 'cancelled',
            'mlm_agent_rejection_reason' => $quotaPagata
                ? 'Rinuncia dell\'interessato a quota codice già saldata: la quota resta pagata, l\'eventuale rimborso si decide dal backoffice.'
                : 'Rinuncia dell\'interessato prima del pagamento della quota codice.',
        ];

        if (! $quotaPagata) {
            $campi['agent_code_fee_due_cents'] = null;
            $campi['agent_code_fee_paid_at']   = null;
        }

        $user->forceFill($campi)->save();

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_request.given_up',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $ipAddress,
            'context'        => [
                // Le due cose che, fra sei mesi, spiegheranno perche' quella
                // quota e' li' pagata addosso a un non-agente.
                'quota_pagata'  => $quotaPagata,
                'era_esonerato' => $eraEsonerato,
            ],
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

    // ── Annullamento di una quota gia' saldata (01/09/2026) ─────────────────

    /**
     * Gemello di RegistrationFeeService::cancel(), e nasce dalla stessa
     * ragione: una quota pagata vive in TRE posti — il movimento, la quota
     * segnata come saldata, il fido aggiuntivo che regge il -480 — e la
     * cancellazione del movimento dalla pagina Movimenti ne rimette a posto
     * UNO. Per questo i movimenti di quota non sono piu' eliminabili da li'
     * (AdminController::MOVIMENTI_DI_QUOTA) e l'unica strada e' questa.
     *
     * LO STORNO SI FA SOLO SE IL MOVIMENTO C'E' ANCORA. Chi lo ha gia' visto
     * cancellare a mano i KY li ha gia' riavuti: stornare in base allo stato
     * del pagamento («risulta completed, quindi restituisco 480») glieli
     * regalerebbe una seconda volta. Qui si guarda il MOVIMENTO.
     *
     * IN EURO NON C'E' NIENTE DA STORNARE, ed e' la differenza vera con la
     * quota dei privati: i 480 in euro non hanno mai accreditato un KY
     * (completeEuroPayment non muove nessun conto). L'annullamento rimette la
     * quota dovuta e basta, e **i soldi non tornano da soli**: il rimborso e'
     * a mano su Stripe/PayPal o con un bonifico. Lo dicono il pannello a chi
     * annulla e la mail a chi la subisce.
     *
     * @throws RuntimeException
     */
    public function cancel(AgentCodeFeePayment $payment, User $admin, ?string $reason = null, ?string $ipAddress = null): AgentCodeFeePayment
    {
        // NB: la guardia "e' saldata?" sta UNA VOLTA SOLA, dentro il lock qui
        // sotto. Una copia qui fuori sarebbe la solita coppia di difese
        // ridondanti che si nascondono a vicenda: spegnendo quella esterna la
        // suite resta verde, e nessun test dice piu' niente su quella vera.
        //
        // Se ha gia' firmato, il codice agente ce l'ha in mano: la quota
        // torna dovuta e lui resta agente. Non lo impedisco — l'admin puo'
        // avere buone ragioni — ma finisce nell'audit log, perche' e' l'unico
        // modo in cui un agente puo' ritrovarsi con la quota da pagare.
        $eraGiaAgente = $payment->user?->isMlmAgent() ?? false;

        DB::transaction(function () use ($payment, $admin, $reason, $ipAddress, $eraGiaAgente): void {
            $locked = AgentCodeFeePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw new RuntimeException($locked->isCancelled()
                    ? 'Questa quota è già stata annullata.'
                    : 'Si può annullare solo una quota già saldata.');
            }

            $originale = $locked->transfer_id !== null
                ? Transfer::whereKey($locked->transfer_id)->where('status', 'booked')->first()
                : null;

            $stornoId = null;

            if ($originale !== null) {
                $superAdminId = User::where('is_super_admin', true)->value('id');
                if ($superAdminId === null) {
                    throw new RuntimeException('Nessun super admin configurato: lo storno non può essere emesso.');
                }

                // Storno per inversione dei conti: l'utente aveva pagato il
                // sistema, il sistema ora ripaga lui. Nessun ramo per il
                // verso: e' lo stesso movimento letto al contrario.
                $storno = $this->transfers->book([
                    'initiated_by'    => $superAdminId,
                    'from_account_id' => $originale->to_account_id,
                    'to_account_id'   => $originale->from_account_id,
                    'amount'          => (int) $originale->amount,
                    'kind'            => 'agent_code_fee_reversal',
                    'description'     => 'Storno della quota per il codice agente',
                    'idempotency_key' => 'agentcode_storno_' . $locked->uuid,
                    'ip_address'      => $ipAddress,
                ]);

                $stornoId = $storno->id;
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->first();

            if ($user !== null) {
                $user->forceFill([
                    // Torna dovuto l'importo DI QUESTO pagamento, non quello
                    // di oggi in impostazioni: chi era stato approvato a 480
                    // deve 480 anche se la quota nel frattempo e' passata a
                    // 600. `?:` e non `??`: cosi' vale anche per lo zero
                    // dell'esonero, che altrimenti resterebbe zero e la quota
                    // non tornerebbe dovuta pur essendo stata annullata.
                    'agent_code_fee_due_cents'          => $user->agent_code_fee_due_cents ?: (int) $locked->amount_eur_cents,
                    'agent_code_fee_paid_at'            => null,
                    // Il fido aggiuntivo se ne va con la quota che lo aveva
                    // motivato: era li' solo per reggere il -480.
                    'agent_code_fee_ky_allowance_cents' => 0,
                ])->save();
            }

            $locked->update([
                'status'       => AgentCodeFeePayment::STATUS_CANCELLED,
                'admin_notes'  => $reason ?? 'Quota annullata dal backoffice.',
                'confirmed_by' => $admin->id,
            ]);

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'agent_code_fee.cancelled',
                'auditable_type' => AgentCodeFeePayment::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'uuid'                 => $locked->uuid,
                    'user_id'              => $locked->user_id,
                    'amount'               => (int) $locked->amount_eur_cents,
                    'payment_method'       => $locked->payment_method,
                    'original_transfer_id' => $locked->transfer_id,
                    'reversal_transfer_id' => $stornoId,
                    // false = il movimento era gia' stato cancellato a mano e
                    // i KY erano gia' tornati indietro. In euro e' sempre
                    // false: non c'era nessun movimento da stornare, e il
                    // rimborso resta da fare a mano.
                    'reversal_booked'      => $stornoId !== null,
                    'in_euro'              => $locked->isPaidInEuro(),
                    'era_gia_agente'       => $eraGiaAgente,
                    'reason'               => $reason,
                ],
            ]);
        });

        $payment->refresh();

        if ($payment->user !== null) {
            $payment->user->notify(new \App\Notifications\AgentCodeFeeCancelledNotification($payment));
        }

        return $payment;
    }

    // ── Esonero: l'admin concede di non pagare (01/09/2026) ─────────────────

    /**
     * Richiesta di Laura: deve poter dire "questo agente non paga".
     *
     * NON E' UN PAGAMENTO E NON DEVE SOMIGLIARGLI. Nessun movimento, nessuna
     * riga in agent_code_fee_payments, nessun fido aggiuntivo: la quota va a
     * zero (self::ESONERATA) e l'interessato passa alla firma. Segnarla
     * "pagata" con un pagamento finto avrebbe sporcato gli incassi con 480
     * euro mai entrati, e la differenza fra un esonero e un incasso sarebbe
     * sparita per sempre.
     *
     * IL MOTIVO E' OBBLIGATORIO: e' l'unica cosa che, fra un anno, distingue
     * un esonero deciso da un errore.
     *
     * @throws RuntimeException
     */
    public function waive(User $user, User $admin, string $reason, ?string $ipAddress = null): void
    {
        // TUTTE le guardie stanno dentro il lock, e ci stanno UNA VOLTA SOLA.
        // La versione con le stesse tre condizioni anche qui fuori era la
        // solita coppia che si nasconde a vicenda: spegnendo quelle esterne
        // restava tutto verde.
        $importoPrima = 0;

        DB::transaction(function () use ($user, $admin, $reason, $ipAddress, &$importoPrima): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->agent_code_fee_paid_at !== null) {
                throw new RuntimeException('Ha già saldato la quota: se i soldi vanno restituiti, annullala dalla riga del pagamento.');
            }

            if ($locked->agent_code_fee_due_cents === null) {
                throw new RuntimeException('Non ha nessuna quota per il codice agente da esonerare.');
            }

            if ((int) $locked->agent_code_fee_due_cents === self::ESONERATA) {
                throw new RuntimeException('Questo utente è già esonerato dalla quota.');
            }

            $importoPrima = (int) $locked->agent_code_fee_due_cents;

            $locked->forceFill(['agent_code_fee_due_cents' => self::ESONERATA])->save();

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'agent_code_fee.waived',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    // Quanto stava per pagare: e' l'importo che torna in
                    // carico se l'esonero viene revocato, e dopo lo zero non
                    // resta scritto da nessun'altra parte.
                    'amount_before' => $importoPrima,
                    'reason'        => $reason,
                ],
            ]);
        });

        $user->refresh();
        $user->notify(new \App\Notifications\AgentCodeFeeWaiverNotification($importoPrima, false, $reason));
    }

    /**
     * Revoca dell'esonero: la quota torna dovuta, dello stesso importo di
     * prima (letto dall'audit log dell'esonero — dopo lo zero non esiste
     * nessun altro posto dove sia rimasto scritto).
     *
     * SOLO FINCHE' NON HA FIRMATO. Dopo la firma il codice agente c'e' gia' e
     * rimettergli in carico la quota vorrebbe dire un agente in giro con il
     * conto bloccato da una quota per una cosa che ha gia' ottenuto: se
     * proprio deve pagarla, e' una decisione da prendere di persona, non un
     * bottone.
     *
     * @throws RuntimeException
     */
    public function revokeWaiver(User $user, User $admin, ?string $ipAddress = null): int
    {
        $importo = 0;

        DB::transaction(function () use ($user, $admin, $ipAddress, &$importo): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->agent_code_fee_paid_at !== null
                || $locked->agent_code_fee_due_cents === null
                || (int) $locked->agent_code_fee_due_cents !== self::ESONERATA) {
                throw new RuntimeException('Questo utente non è esonerato dalla quota.');
            }

            if ($locked->isMlmAgent()) {
                throw new RuntimeException('Ha già firmato la nomina con l\'esonero: la quota non si rimette in carico da qui.');
            }

            // L'importo di prima sta nell'audit log dell'esonero: dopo lo zero
            // non esiste nessun altro posto dove sia rimasto scritto. Il
            // ripiego sulle impostazioni serve solo se quella riga non c'e'
            // (esonero scritto a mano sul database).
            $ultimoEsonero = AuditLog::where('event', 'agent_code_fee.waived')
                ->where('auditable_type', User::class)
                ->where('auditable_id', $locked->id)
                ->latest('id')
                ->first();

            $importo = (int) (($ultimoEsonero?->context['amount_before'] ?? 0) ?: $this->settings()->agentCodeFeeAmount());

            if ($importo <= 0) {
                throw new RuntimeException('Non risulta nessun importo da rimettere in carico.');
            }

            $locked->forceFill(['agent_code_fee_due_cents' => $importo])->save();

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'agent_code_fee.waiver_revoked',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => ['amount' => $importo],
            ]);
        });

        $user->refresh();
        $user->notify(new \App\Notifications\AgentCodeFeeWaiverNotification($importo, true, null));

        return $importo;
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
