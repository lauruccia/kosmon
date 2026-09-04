<?php

namespace App\Services;

use App\Models\Contracts\FeePayment;
use App\Models\AgentCodeFeePayment;
use App\Models\AuditLog;
use App\Models\Transfer;
use App\Notifications\AgentCodeFeeCancelledNotification;
use App\Notifications\AgentCodeFeePaidNotification;
use App\Services\Fees\AbstractFeeService;
use App\Services\Fees\FeeDefinition;
use App\Services\TransferBookingService;
use Illuminate\Database\Eloquent\Model;
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
class AgentCodeFeeService extends AbstractFeeService
{
    public function __construct(
        TransferBookingService $transfers,
        private readonly RegistrationFeeService $registrationFees,
    ) {
        parent::__construct($transfers);
    }

    /**
     * Se ha gia' firmato, il codice agente ce l'ha in mano: la quota torna
     * dovuta e lui resta agente. Non lo si impedisce — l'admin puo' avere buone
     * ragioni — ma finisce nell'audit log, perche' e' l'unico modo in cui un
     * agente puo' ritrovarsi con la quota da pagare.
     */
    protected function extraCancelContext(Model&FeePayment $payment): array
    {
        return [
            // In euro e' sempre false che ci sia stato uno storno: nessun KY
            // era stato emesso, e il rimborso resta da fare a mano.
            'in_euro'        => $payment->isPaidInEuro(),
            'era_gia_agente' => $payment->user?->isMlmAgent() ?? false,
        ];
    }

    /**
     * I 480 tornano indietro, e con loro torna indietro anche la conclusione
     * che ne era derivata (02/09/2026). Chi era uscito dal percorso con la
     * quota pagata si era visto SPEGNERE quella dei privati, perche' l'ingresso
     * nel circuito risultava saldato: se adesso quei soldi gli vengono
     * restituiti, l'ingresso non e' piu' pagato e i 30 tornano dovuti. Senza
     * questo, lo storno rimetteva a posto il denaro e lasciava in piedi la
     * conseguenza — una persona nel circuito, con il conto operativo, che non
     * ha pagato niente.
     */
    protected function afterCancelled(Model&FeePayment $payment, ?string $ipAddress): void
    {
        if ($payment->user !== null) {
            $this->registrationFees->restoreAfterAgentFeeCancelled($payment->user, $ipAddress);
        }
    }

    public function definition(): FeeDefinition
    {
        return new FeeDefinition(
            paymentClass:                AgentCodeFeePayment::class,
            dueColumn:                   'agent_code_fee_due_cents',
            paidAtColumn:                'agent_code_fee_paid_at',
            allowanceColumn:             'agent_code_fee_ky_allowance_cents',
            auditPrefix:                 'agent_code_fee',
            idempotencyPrefix:           'agentcode_',
            // Le due leve, con gli stessi nomi per tutte e tre le quote
            // (04/09/2026). Prima qui c'era `emitsKyInEuro: false` e voleva
            // dire "in euro non si emette un solo KY, i 480 sono il prezzo del
            // codice". Resta vero fino a quando l'admin non scrive un numero
            // in /admin/quote: la migrazione lascia zero, e zero e'
            // esattamente il comportamento di sempre.
            kyCreditSetting:             'agent_code_fee_ky_credit_cents',
            kyCreditOverrideColumn:      'agent_code_fee_ky_credit_override_cents',
            kyAllowanceSetting:          'agent_code_fee_ky_allowance',
            kyAllowanceOverrideColumn:   'agent_code_fee_ky_allowance_override',
            paidNotification:            AgentCodeFeePaidNotification::class,
            cancelledNotification:       AgentCodeFeeCancelledNotification::class,
            kyTransferKind:              'agent_code_fee',
            kyTransferDescription:       'Quota per il codice agente KNM',
            // Usati solo se l'admin imposta una restituzione maggiore di zero:
            // sono il movimento con cui il conto di sistema emette quei KY
            // verso l'agente. A zero — il valore di partenza — non si crea
            // nessun movimento e questi due nomi non compaiono da nessuna parte.
            creditTransferKind:          'agent_code_fee_credit',
            creditTransferDescription:   'Quota per il codice agente pagata in euro',
            reversalTransferKind:        'agent_code_fee_reversal',
            reversalTransferDescription: 'Storno della quota per il codice agente',
            notDueMessage:               'La quota per il codice agente risulta già saldata.',
            retryOnCancelledMessage:     'Questa quota è stata annullata: riaprila prima di darla per saldata.',
            retryFailedMessage:          'La chiusura è fallita di nuovo: ',
            treatmentNotApplicableMessage: 'Questo profilo non ha un trattamento da impostare sulla quota del codice agente.',
        );
    }

    public function availableMethods(): array
    {
        return $this->settings()->agentCodeFeeMethods();
    }

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
     * Il percorso agente di questa persona ha una quota SUA: dovuta, gia'
     * saldata o esonerata dall'admin. E' la domanda a cui serve rispondere per
     * decidere se sospendere i 30 dei privati (02/09/2026): l'ingresso nel
     * circuito lo sta gia' pagando il codice agente.
     *
     * Basta guardare se la colonna e' stata scritta, e copre da sola i tre
     * casi: `> 0` la deve, `0` e' esonerato, e chi ha pagato la colonna ce
     * l'ha comunque valorizzata (vedi completeEuroPayment/payWithKy). Una
     * seconda condizione su `agent_code_fee_paid_at` sarebbe ridondante — e
     * una difesa ridondante e' una difesa che non risulta mai provata.
     *
     * NULL invece vuol dire che nessuna quota lo copre (l'interruttore era
     * giu' quando e' stato approvato): allora i 30, se li deve, se li tiene,
     * altrimenti diventare agente sarebbe il modo di entrare nel circuito
     * senza pagare niente.
     */
    public function isOnFeePath(?User $user): bool
    {
        return $user !== null && $user->agent_code_fee_due_cents !== null;
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
     * IN TUTTI E DUE I CASI i checkout ancora aperti si chiudono
     * (closeOpenAttempts, 02/09/2026): senza, chi rinunciava con la scheda
     * Stripe ancora aperta poteva pagare lo stesso e farsi incassare 480 euro
     * per un codice che non avrebbe mai avuto.
     *
     * E IN TUTTI E DUE I CASI il fido aggiuntivo se ne va (revokeKyAllowance,
     * decisione di Laura del 02/09/2026): esisteva per reggere il -480 di chi
     * pagava in KY, e chiuso il percorso non ha piu' ragione. Chi aveva pagato
     * cosi' resta con il conto SOTTO il limite finche' non lo risale
     * incassando — glielo si dice a schermo, non lo deve scoprire al primo
     * pagamento rifiutato.
     *
     * @return int i centesimi di fido aggiuntivo tolti, 0 se non ce n'era
     *
     * Dopo la firma non si passa di qui: il codice agente c'e' gia' e la
     * strada e' un'altra (la revoca dell'agente, che e' un altro mestiere).
     */
    public function giveUp(User $user, ?string $ipAddress = null): int
    {
        $fidoTolto = 0;

        DB::transaction(function () use ($user, $ipAddress, &$fidoTolto): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Le due guardie stanno DENTRO il lock, e ci stanno una volta
            // sola. Fino al 02/09 questo era l'unico metodo di uscita scritto
            // con un forceFill nudo, fuori da qualunque transazione: due click
            // sul bottone lasciavano due audit log e una finestra in cui la
            // richiesta era gia' 'cancelled' e il debito ancora addosso.
            if ($locked->isMlmAgent()) {
                throw new RuntimeException('Hai già firmato la nomina: la rinuncia non passa da qui.');
            }

            if ($locked->mlm_agent_request_status !== 'approved') {
                throw new RuntimeException('Non c\'è nessun percorso da agente aperto da annullare.');
            }

            $quotaPagata  = $this->hasPaid($locked);
            $eraEsonerato = $this->isWaived($locked);

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

            $locked->forceFill($campi)->save();

            $chiusi = $this->closeOpenAttempts(
                $locked,
                'Percorso agente chiuso: l\'interessato ha rinunciato.',
                $ipAddress,
            );

            $fidoTolto = $this->revokeKyAllowance($locked, $ipAddress);

            AuditLog::create([
                'actor_user_id'  => $locked->id,
                'event'          => 'mlm.agent_request.given_up',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    // Le due cose che, fra sei mesi, spiegheranno perche' quella
                    // quota e' li' pagata addosso a un non-agente.
                    'quota_pagata'  => $quotaPagata,
                    'era_esonerato' => $eraEsonerato,
                    // Quanti checkout aperti sono stati chiusi con lui: se un
                    // pagamento arriva lo stesso, e' qui che si capisce perche'
                    // il webhook lo ha rifiutato.
                    'tentativi_chiusi' => $chiusi,
                    // Quanta capienza gli e' stata tolta insieme al percorso.
                    // Se e' maggiore di zero, questa persona resta con il conto
                    // sotto il limite: e' il numero che spiega perche'.
                    'fido_aggiuntivo_tolto' => $fidoTolto,
                ],
            ]);
        });

        // Chi era entrato dalla porta dell'agente aveva la quota dei privati
        // SOSPESA (01/09/2026): rinunciando torna un privato come tutti gli
        // altri e quella quota si accende. Senza questa riga il portale
        // dell'agente sarebbe il modo per entrare nel circuito senza pagare
        // niente: ci si fa registrare, si rinuncia, e non si deve piu' nulla.
        $this->registrationFees->resumeAfterAgentPath($user->refresh(), $ipAddress);

        return $fidoTolto;
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

    /**
     * Toglie il fido aggiuntivo concesso per reggere il -480, perche' il
     * percorso agente si e' chiuso (decisione di Laura, 02/09/2026).
     *
     * COSA COMPORTA, ED E' VOLUTO. Quel fido esisteva per una ragione sola:
     * permettere a chi pagava la quota in KY di andare sotto di 480 senza
     * mangiarsi il proprio. Chiusa la ragione, chiusa la capienza. Chi aveva
     * pagato in KY resta quindi con il saldo a -480 e il massimale tornato al
     * suo fido: **il conto va SOTTO il limite**, e finche' non lo risale non
     * puo' piu' inviare KY (incassare si', ed e' cosi' che lo risale). Non e'
     * un blocco del conto: e' il motore che rifiuta le uscite, come per
     * chiunque sia oltre il proprio fido.
     *
     * VALE ANCHE PER IL RIFIUTO DELL'ADMIN, non solo per la rinuncia (scelta
     * di Laura): stessa regola nei due casi, altrimenti converrebbe aspettare
     * di farsi rifiutare invece di rinunciare.
     *
     * NON RESTITUISCE I 480. La quota resta pagata e i KY restano al conto di
     * sistema: qui si toglie solo la capienza in piu'. Se i soldi vanno
     * restituiti, si annulla la quota dal backoffice, che storna il movimento
     * (e riporta il saldo a zero) tutto insieme.
     *
     * @return int i centesimi di capienza tolti, 0 se non ce n'era
     */
    public function revokeKyAllowance(User $user, ?string $ipAddress = null): int
    {
        $tolto = 0;

        DB::transaction(function () use ($user, $ipAddress, &$tolto): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $tolto = max(0, (int) ($locked->agent_code_fee_ky_allowance_cents ?? 0));

            if ($tolto === 0) {
                return;
            }

            $locked->forceFill(['agent_code_fee_ky_allowance_cents' => 0])->save();

            AuditLog::create([
                'actor_user_id'  => $locked->id,
                'event'          => 'agent_code_fee.ky_allowance_revoked',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'amount' => $tolto,
                    // Il saldo con cui resta: e' il numero che serve a
                    // rispondere, fra sei mesi, a «perche' non riesco a
                    // pagare?».
                    'saldo_dopo' => (int) ($this->accountFor($locked)?->available_balance ?? 0),
                ],
            ]);
        });

        return $tolto;
    }

    /**
     * Chiude i tentativi di pagamento ancora aperti di questa persona, perche'
     * il percorso agente si e' chiuso: rinuncia sua (giveUp) o rifiuto
     * dell'admin (Admin\MlmAgentRequestController::reject).
     *
     * IL BUCO CHE CHIUDE (02/09/2026), ed e' il piu' caro dei due trovati.
     * Ogni click su "paga con carta" apre una riga `pending` e una sessione
     * Stripe che resta valida per ore. Dal 01/09 il webhook accredita
     * QUALUNQUE riga che non sia gia' `completed` o `cancelled` — tolleranza
     * voluta, serve a ripescare chi ha pagato davvero. Ma nessuno chiudeva le
     * righe quando il percorso si chiudeva, e allora:
     *
     *   1. apre il checkout, la scheda resta li';
     *   2. ci ripensa e rinuncia — quota cancellata, richiesta 'cancelled',
     *      e i 30 dei privati che si riaccendono;
     *   3. torna sulla scheda e paga lo stesso;
     *   4. il webhook trova una riga `pending`, Stripe conferma l'incasso, e
     *      la quota risulta SALDATA.
     *
     * Risultato: 480 euro incassati, nessun codice agente, nessuna mail (la
     * ricevuta della quota agente non esiste ancora) e i 30 da pagare. Lo
     * stesso vale per il rifiuto dell'admin.
     *
     * `cancelled` E NON `failed`, ed e' tutta la differenza: `failed` e'
     * VOLUTO che resti ripescabile — e' lo stato in cui finisce un accredito
     * andato storto o un tentativo dato per abbandonato, e webhook e pagina di
     * esito devono poterlo riaprire. `cancelled` e' l'unico stato che significa
     * "risposta gia' data, non tornarci sopra".
     *
     * SI CHIUDE ANCHE A CHI HA GIA' PAGATO, e non e' una svista: chi ha saldato
     * in KY puo' avere lasciato indietro un checkout con carta abbandonato, e
     * quella riga incasserebbe una seconda volta 480 euro senza nemmeno il
     * `Log::warning` del doppio incasso che esiste sui privati.
     *
     * @return int quante righe sono state chiuse
     */
    public function closeOpenAttempts(User $user, string $reason, ?string $ipAddress = null): int
    {
        $aperti = AgentCodeFeePayment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                AgentCodeFeePayment::STATUS_PENDING,
                AgentCodeFeePayment::STATUS_PENDING_BANK_TRANSFER,
            ])
            ->get();

        $chiusi = 0;

        foreach ($aperti as $aperto) {
            $statoPrima = $aperto->status;

            $aperto->update([
                'status'      => AgentCodeFeePayment::STATUS_CANCELLED,
                'admin_notes' => $reason,
            ]);

            AuditLog::create([
                'actor_user_id'  => $user->id,
                'event'          => 'agent_code_fee.attempt_closed',
                'auditable_type' => AgentCodeFeePayment::class,
                'auditable_id'   => $aperto->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'uuid'           => $aperto->uuid,
                    'payment_method' => $aperto->payment_method,
                    'stato_prima'    => $statoPrima,
                    'reason'         => $reason,
                ],
            ]);

            $chiusi++;
        }

        return $chiusi;
    }

    // ── Apertura di un tentativo di pagamento ───────────────────────────────

    // ── Bonifico gia' richiesto (02/09/2026) ────────────────────────────────

    // ── Pagamento in KY ─────────────────────────────────────────────────────

    // ── Pagamento in euro: NESSUN KY viene emesso ───────────────────────────

    // ── Ripescaggio di un incasso in euro (02/09/2026) ──────────────────────

    // ── Annullamento di una quota gia' saldata (01/09/2026) ─────────────────

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

}
