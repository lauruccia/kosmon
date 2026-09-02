<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\RegistrationFeePayment;
use App\Notifications\RegistrationFeeCancelledNotification;
use App\Notifications\RegistrationFeePaidNotification;
use App\Notifications\RegistrationFeeRequestedNotification;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Quota di iscrizione dei privati (richiesta di Laura del 31/08/2026).
 *
 * DUE STRADE, DUE CONTABILITA' DIVERSE — e non e' una svista:
 *
 *   EURO (Stripe / PayPal / bonifico). L'utente paga 30 EUR veri a KNM fuori
 *   dal circuito e riceve 30 KY sul proprio conto, emessi dal conto di
 *   sistema. In KY il circuito ha coniato 30, in euro KNM ha incassato 30:
 *   per l'utente la quota non e' un costo in KY, ha comprato KY. E' lo stesso
 *   identico movimento di una ricarica KYCard.
 *
 *   KY. L'utente non tira fuori euro: il suo conto va SOTTO di 30 e i 30 KY
 *   finiscono sul conto di sistema. Il circuito conia comunque 30 KY (il
 *   saldo negativo E' moneta, vedi la nota del 28/08 sui fidi in uso), ma li
 *   tiene KNM. Si "recupera" invitando qualcuno: i bonus segnalazione che
 *   gia' esistono (ReferralBonusService) riportano il saldo verso lo zero.
 *
 * IL FIDO AGGIUNTIVO. Un privato appena registrato ha fido zero: senza fare
 * niente, l'addebito in KY verrebbe rifiutato dal motore prima ancora di
 * partire. E anche chi un fido ce l'ha non deve vederselo mangiare da una
 * quota di iscrizione. Decisione di Laura: il conto resta utilizzabile e il
 * fido si somma al debito (fido 50 => puo' arrivare a -80). Percio' chi paga
 * in KY riceve un fido aggiuntivo pari alla quota, scritto su
 * users.registration_fee_ky_allowance_cents e letto in due punti soli —
 * Account::massimale() (quel che si vede) e
 * TransferBookingService::assertTransferWithinLimits() (quel che il motore
 * consente davvero).
 *
 * A CHI SI APPLICA. Solo ai privati che si registrano da quando l'admin
 * accende l'interruttore. Lo decide users.registration_fee_due_cents, scritto
 * una volta alla registrazione: NULL = non deve niente, ed e' il valore che
 * hanno tutti quelli gia' iscritti. Dal 01/09 c'e' un terzo valore, ZERO =
 * quota SOSPESA, per chi entra dal portale di un agente e paga i 480 del
 * codice al posto dei 30: vedi la costante SOSPESA qui sotto.
 */
class RegistrationFeeService
{
    /**
     * Valore di `users.registration_fee_due_cents` che significa "questa
     * persona e' entrata come privato DOPO l'accensione della quota, ma dalla
     * porta dell'agente: per ora non deve niente".
     *
     * I tre valori della colonna, e la differenza fra i primi due e' tutto
     * quello che serve sapere:
     *
     *   NULL  = non deve niente e non dovra' mai niente. E' il valore dei
     *           milletrecento iscritti da prima che la quota esistesse.
     *   0     = SOSPESA. Non deve niente ORA. Se lascia il percorso agente
     *           (rinuncia sua o rifiuto dell'admin) la quota si accende.
     *   > 0   = la deve, ed e' quella cifra li' (scatto alla registrazione:
     *           se domani l'admin porta la quota a 50, chi si e' registrato
     *           a 30 continua a dovere 30).
     *
     * Decisione di Laura del 01/09/2026: chi entra dal portale di un agente
     * paga i 480 del codice agente, non anche i 30 dei privati — ma se poi
     * agente non lo diventa, i 30 tornano dovuti, altrimenti quella porta
     * sarebbe il modo per entrare nel circuito senza pagare niente.
     */
    public const SOSPESA = 0;

    public function __construct(private readonly TransferBookingService $transfers)
    {
    }

    public function settings(): SystemSetting
    {
        return SystemSetting::userLimitDefaults();
    }

    // ── Registrazione ───────────────────────────────────────────────────────

    /**
     * Marca il debito sul nuovo utente. Non bloccante: se qualcosa va storto
     * qui, la registrazione deve comunque riuscire — un utente senza quota
     * segnata e' un problema recuperabile a mano, una registrazione fallita
     * per colpa della quota no.
     */
    public function markDueOnRegistration(User $user): void
    {
        try {
            $settings = $this->settings();

            if (! $settings->registrationFeeEnabled()) {
                return;
            }

            // Solo i privati (decisione di Laura): le aziende hanno gia' i
            // piani di abbonamento, vedi PlanPayment.
            if ($user->account_holder_type !== 'private') {
                return;
            }

            // Mai sovrascrivere una colonna gia' scritta: qui dentro passa
            // anche chi e' stato creato dal portale di un agente e ha la
            // quota SOSPESA (zero), e riscriverla gliela accenderebbe subito.
            if ($user->registration_fee_due_cents !== null) {
                return;
            }

            $user->forceFill([
                'registration_fee_due_cents' => $settings->registrationFeeAmount(),
                'registration_fee_paid_at'   => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Quota iscrizione: impossibile marcare il debito', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── La porta dell'agente (01/09/2026) ───────────────────────────────────

    /**
     * Il nuovo privato e' stato creato dal portale di un agente
     * (MlmPortalController::registraAgenteStore): entra per diventare agente
     * e paga i 480 del codice, non anche i 30 dei privati. La quota nasce
     * SOSPESA — zero, non NULL — perche' la differenza fra "non la deve
     * adesso" e "non la dovra' mai" e' esattamente quello che tiene fuori i
     * milletrecento iscritti da prima.
     *
     * Non bloccante come markDueOnRegistration, e per lo stesso motivo: una
     * registrazione persa e' peggio di una quota da segnare a mano.
     */
    public function suspendForAgentPath(User $user): void
    {
        try {
            if (! $this->settings()->registrationFeeEnabled()) {
                return;
            }

            if ($user->account_holder_type !== 'private') {
                return;
            }

            // Solo su una colonna mai scritta. Qui dentro passa SOLO gente
            // appena creata, quindi in pratica e' sempre NULL; la sospensione
            // di una quota gia' segnata e' un'altra cosa e ha un metodo suo,
            // suspendOnAgentApproval(), perche' li' NULL vuol dire l'opposto.
            if ($user->registration_fee_due_cents !== null) {
                return;
            }

            $user->forceFill([
                'registration_fee_due_cents' => self::SOSPESA,
                'registration_fee_paid_at'   => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Quota iscrizione: impossibile sospendere il debito', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * L'utente lascia il percorso agente senza esserlo diventato: rinuncia
     * sua (AgentCodeFeeService::giveUp) o rifiuto dell'admin
     * (Admin\MlmAgentRequestController::reject). Da questo momento e' un
     * privato come tutti gli altri e la quota sospesa si accende.
     *
     * Si accende SOLO se era sospesa: NULL resta NULL (i vecchi iscritti che
     * chiedono di diventare agenti e vengono rifiutati non devono ritrovarsi
     * un debito che non hanno mai avuto), e una quota gia' dovuta o gia'
     * pagata non viene toccata.
     *
     * L'importo e' quello di OGGI e non uno scatto vecchio: la colonna
     * conteneva zero, non c'era nessun importo da conservare. E' l'unico
     * punto in cui la quota non segue lo scatto della registrazione, ed e'
     * inevitabile.
     *
     * @return int i centesimi ora dovuti, 0 se non si e' acceso niente
     */
    public function resumeAfterAgentPath(User $user, ?string $ipAddress = null): int
    {
        $importo = $this->settings()->registrationFeeAmount();

        if ($importo <= 0 || ! $this->settings()->registrationFeeEnabled()) {
            return 0;
        }

        $acceso = false;

        DB::transaction(function () use ($user, $importo, $ipAddress, &$acceso): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // UNICA guardia su "questa quota si puo' accendere", e sta DENTRO
            // il lock. Una copia qui fuori sembrerebbe piu' economica (niente
            // transazione per chi non c'entra) ma sarebbe l'ennesima coppia di
            // difese che si nascondono a vicenda dal mutation testing: con
            // due, spegnerne una lascia tutto verde e nessuno dei due
            // controlli risulta piu' davvero provato. Vedi la stessa scelta in
            // requestFrom().
            //
            // Le tre condizioni, e perche' ognuna:
            //   - NULL: non e' mai passato dalla porta dell'agente. E' il
            //     valore dei milletrecento iscritti da prima, e deve restare
            //     NULL anche se uno di loro chiede di diventare agente e viene
            //     rifiutato — un debito che non ha mai avuto.
            //   - diverso da SOSPESA: la quota gia' la deve, e con l'importo
            //     suo. Riscriverla vorrebbe dire cambiargli lo scatto.
            //   - gia' pagata: non si riapre niente.
            if ($locked->registration_fee_due_cents === null
                || (int) $locked->registration_fee_due_cents !== self::SOSPESA
                || $locked->registration_fee_paid_at !== null) {
                return;
            }

            $locked->forceFill([
                'registration_fee_due_cents' => $importo,
                'registration_fee_paid_at'   => null,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $locked->id,
                'event'          => 'registration_fee.resumed_after_agent_path',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => ['amount' => $importo],
            ]);

            $acceso = true;
        });

        if (! $acceso) {
            return 0;
        }

        $user->refresh();

        // Avvisarlo e' obbligatorio, non gentile: da adesso i bottoni di
        // pagare/incassare/comprare sono spenti e deve sapere perche'.
        $user->notify(new RegistrationFeeRequestedNotification($importo));

        return $importo;
    }

    /**
     * L'admin approva la richiesta «voglio diventare agente» (o promuove
     * l'utente direttamente): da qui in avanti il suo ingresso nel circuito lo
     * paga la quota del CODICE AGENTE, e i 30 dei privati restano SOSPESI
     * finche' quel percorso e' aperto.
     *
     * DECISIONE DI LAURA DEL 02/09/2026, e ribalta quella del 31/08: l'agente
     * paga una quota sola — i 480 — comunque sia entrato nel circuito. Prima
     * chi si registrava dal form pubblico e poi chiedeva il codice si trovava
     * addosso tutte e due le quote, 30 + 480, e la pagina dei 30 con sopra il
     * banner rosso dei 480.
     *
     * QUESTO METODO E' IL GEMELLO ASIMMETRICO DI suspendForAgentPath(), e la
     * differenza sta tutta in cosa fanno le due con una colonna NULL — sono
     * opposte apposta:
     *
     *   · alla CREAZIONE dal portale di un agente, NULL diventa SOSPESA:
     *     l'utente nasce in questo momento, la quota gli spetterebbe, e va
     *     tenuta in caldo per il caso in cui agente non lo diventi;
     *   · all'APPROVAZIONE, NULL resta NULL: li' dentro ci sono i
     *     milletrecento iscritti da prima che la quota esistesse, che non la
     *     devono e non la dovranno mai. Scriverci zero vorrebbe dire che al
     *     primo rifiuto si ritroverebbero un debito mai avuto.
     *
     * Una quota GIA' PAGATA non si tocca: quei soldi sono arrivati davvero e
     * restituirli e' una decisione dell'admin, non l'effetto collaterale di un
     * click su «Approva» (stessa regola del rifiuto e della rinuncia). Chi
     * approva se lo legge a schermo, mentre ci sta pensando.
     *
     * Nessuna guardia sull'interruttore della quota: se l'admin l'ha spenta
     * dopo aver messo in carico i 30, quei 30 sono comunque dovuti e vanno
     * comunque sospesi.
     *
     * @return int i centesimi sospesi, 0 se non c'era niente da sospendere
     */
    public function suspendOnAgentApproval(User $user, ?string $ipAddress = null, ?User $admin = null): int
    {
        $sospesi = 0;

        DB::transaction(function () use ($user, $ipAddress, $admin, &$sospesi): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // UNICA guardia, e sta DENTRO il lock: una copia qui fuori sarebbe
            // l'ennesima coppia di difese che si nascondono a vicenda dal
            // mutation testing (stessa scelta di resumeAfterAgentPath()).
            //
            //   - non privato: la quota di iscrizione non lo riguarda;
            //   - gia' pagata: non si restituisce niente da qui;
            //   - NULL o zero: non deve niente, e NULL deve restare NULL.
            if ($locked->account_holder_type !== 'private'
                || $locked->registration_fee_paid_at !== null
                || (int) ($locked->registration_fee_due_cents ?? 0) <= 0) {
                return;
            }

            $sospesi = (int) $locked->registration_fee_due_cents;

            $locked->forceFill(['registration_fee_due_cents' => self::SOSPESA])->save();

            AuditLog::create([
                // Chi ha deciso: l'admin che ha approvato o premuto il
                // bottone, se c'e'; l'utente stesso quando la sospensione
                // arriva da un percorso automatico.
                'actor_user_id'  => $admin?->id ?? $locked->id,
                'event'          => 'registration_fee.suspended_on_agent_approval',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                // L'importo che DOVEVA: la colonna ora dice zero e questa e'
                // l'unica traccia di quanto fosse lo scatto suo.
                'context'        => ['amount' => $sospesi],
            ]);
        });

        return $sospesi;
    }

    // ── Stato ───────────────────────────────────────────────────────────────

    /**
     * La quota e' SOSPESA: zero e mai pagata. Vuol dire che questa persona
     * nel circuito non ha ancora pagato NESSUN ingresso — sta sul percorso
     * agente, e a coprirla sono i 480 del codice.
     *
     * Serve a decidere quanto stringere (02/09/2026, decisione di Laura): chi
     * l'ingresso non l'ha mai pagato ha il conto fermo finche' non salda i
     * 480, perche' quei 480 SONO il suo ingresso; chi invece i 30 li aveva
     * gia' pagati — o non li ha mai dovuti, come i milletrecento iscritti da
     * prima — continua a usare il conto e gli manca solo la firma. Bloccare
     * anche lui vorrebbe dire che chiedere di crescere nel circuito ha come
     * primo effetto il conto congelato.
     */
    public function isSuspendedFor(?User $user): bool
    {
        return $user !== null
            && $user->registration_fee_due_cents !== null
            && (int) $user->registration_fee_due_cents === self::SOSPESA
            && $user->registration_fee_paid_at === null;
    }

    /** L'utente ha una quota ancora da saldare? */
    public function isDueFor(?User $user): bool
    {
        return $user !== null
            && $user->registration_fee_due_cents !== null
            && (int) $user->registration_fee_due_cents > 0
            && $user->registration_fee_paid_at === null;
    }

    public function amountDueFor(User $user): int
    {
        return max(0, (int) ($user->registration_fee_due_cents ?? 0));
    }

    /** Il conto personale su cui addebitare o accreditare la quota. */
    public function accountFor(User $user): ?Account
    {
        return Account::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();
    }

    // ── Apertura di un tentativo di pagamento ───────────────────────────────

    /**
     * @throws RuntimeException se la quota non e' dovuta o il metodo e' spento
     */
    public function startPayment(User $user, string $method): RegistrationFeePayment
    {
        if (! $this->isDueFor($user)) {
            throw new RuntimeException('La quota di iscrizione risulta già saldata.');
        }

        if (! array_key_exists($method, $this->settings()->registrationFeeMethods())) {
            throw new RuntimeException('Metodo di pagamento non disponibile.');
        }

        $amount = $this->amountDueFor($user);

        return RegistrationFeePayment::create([
            'user_id'          => $user->id,
            'account_id'       => $this->accountFor($user)?->id,
            'amount_eur_cents' => $amount,
            'ky_amount'        => $amount,
            'status'           => $method === RegistrationFeePayment::METHOD_BANK_TRANSFER
                ? RegistrationFeePayment::STATUS_PENDING_BANK_TRANSFER
                : RegistrationFeePayment::STATUS_PENDING,
            'payment_method'   => $method,
        ]);
    }

    // ── Bonifico gia' richiesto (01/09/2026) ────────────────────────────────

    /**
     * Il bonifico in attesa di questo utente, se ce n'e' uno.
     *
     * Serve perche' il bonifico non e' un pagamento istantaneo come gli altri:
     * l'utente lo chiede, va in banca, e torna sul sito ore o giorni dopo.
     * Se in quel momento ritrova i quattro bottoni come la prima volta non
     * sa se la sua richiesta e' arrivata, e ne fa un'altra — con una causale
     * diversa da quella che ha scritto sul bonifico vero.
     */
    public function pendingBankTransferFor(User $user): ?RegistrationFeePayment
    {
        return RegistrationFeePayment::query()
            ->where('user_id', $user->id)
            ->where('payment_method', RegistrationFeePayment::METHOD_BANK_TRANSFER)
            ->where('status', RegistrationFeePayment::STATUS_PENDING_BANK_TRANSFER)
            ->latest('id')
            ->first();
    }

    /**
     * Apre la richiesta di bonifico, oppure RIPRENDE quella gia' aperta.
     *
     * La causale contiene l'uuid del pagamento: aprirne una nuova a ogni
     * visita significherebbe dare all'utente una causale diversa da quella
     * che ha gia' scritto sul bonifico, e nessuno dei due bonifici sarebbe
     * piu' ricollegabile con certezza.
     *
     * @throws RuntimeException
     */
    public function startOrResumeBankTransfer(User $user): RegistrationFeePayment
    {
        $aperto = $this->pendingBankTransferFor($user);

        if ($aperto !== null && $this->isDueFor($user)) {
            return $aperto;
        }

        return $this->startPayment($user, RegistrationFeePayment::METHOD_BANK_TRANSFER);
    }

    /**
     * L'utente rinuncia al bonifico e torna a scegliere il metodo.
     *
     * Non e' un fallimento del circuito ma una scelta sua, e va scritta:
     * se il bonifico partisse comunque, l'admin deve poter capire dal
     * pagamento perche' quella causale risulta abbandonata.
     */
    public function abandonBankTransfer(User $user): bool
    {
        $aperto = $this->pendingBankTransferFor($user);

        if ($aperto === null) {
            return false;
        }

        $aperto->update([
            'status'      => RegistrationFeePayment::STATUS_FAILED,
            'admin_notes' => "L'utente ha rinunciato al bonifico e ha scelto un altro metodo.",
        ]);

        return true;
    }

    // ── Pagamento in KY ─────────────────────────────────────────────────────

    /**
     * Addebita la quota sul conto dell'utente e la accredita al conto di
     * sistema (KNM). Il conto va sotto: e' previsto, ed e' il motivo per cui
     * il fido aggiuntivo viene concesso PRIMA dell'addebito e dentro la
     * stessa transazione — se l'addebito fallisce, il fido sparisce con lui.
     *
     * @throws RuntimeException
     */
    public function payWithKy(User $user, ?string $ipAddress = null): RegistrationFeePayment
    {
        $payment = $this->startPayment($user, RegistrationFeePayment::METHOD_KY);

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
                // Lock sulla riga utente: due click sul bottone "paga in KY"
                // non devono produrre due addebiti. Il secondo trova la quota
                // gia' saldata e si ferma qui.
                $locked = User::whereKey($user->id)->lockForUpdate()->first();

                if (! $this->isDueFor($locked)) {
                    throw new RuntimeException('La quota di iscrizione risulta già saldata.');
                }

                // Il fido aggiuntivo PRIMA dell'addebito: senza, il motore
                // rifiuterebbe di portare a -30 un conto con fido zero.
                $locked->forceFill([
                    'registration_fee_ky_allowance_cents' => $amount,
                ])->save();

                $transfer = $this->transfers->book([
                    'initiated_by'    => $user->id,
                    'from_account_id' => $account->id,
                    'to_account_id'   => $systemAccount->id,
                    'amount'          => $amount,
                    'kind'            => 'registration_fee',
                    'description'     => 'Quota di iscrizione al circuito',
                    'idempotency_key' => 'regfee_' . $payment->uuid,
                    'ip_address'      => $ipAddress,
                ]);

                $locked->forceFill(['registration_fee_paid_at' => now()])->save();

                $payment->update([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $account->id,
                    'status'       => RegistrationFeePayment::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                AuditLog::create([
                    'actor_user_id'  => $user->id,
                    'event'          => 'registration_fee.paid_in_ky',
                    'auditable_type' => RegistrationFeePayment::class,
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
        $payment->refresh();

        // Fuori dalla transazione: una notifica che parte da dentro verrebbe
        // spedita anche se la transazione poi rotolasse indietro, e nessuno
        // se la riprende piu'.
        $user->notify(new RegistrationFeePaidNotification($payment));

        return $payment;
    }

    // ── Pagamento in euro: KNM emette i KY ──────────────────────────────────

    /**
     * Chiamato quando un pagamento in euro risulta incassato (Stripe, PayPal,
     * o bonifico confermato dall'admin). Emette i KY dal conto di sistema
     * verso l'utente e salda la quota.
     *
     * IDEMPOTENTE SU DUE LIVELLI, e servono entrambi: la guardia sullo stato
     * ferma la seconda chiamata nel caso normale, la idempotency_key del
     * transfer e' l'unica cosa che regge la corsa vera fra webhook Stripe e
     * pagina di successo — la stessa corsa che il 31/08 ha fatto assegnare i
     * punti MLM due volte (vedi fix_punti_mlm_doppi_31_08).
     */
    public function completeEuroPayment(RegistrationFeePayment $payment, ?int $confirmedBy = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $systemAccount = Account::systemAccount();
        if ($systemAccount === null) {
            Log::error('Quota iscrizione: conto di sistema mancante', ['payment' => $payment->uuid]);
            $this->markFailed($payment, 'Conto di sistema non disponibile.');

            return;
        }

        $user = $payment->user;
        $account = $payment->account ?? ($user ? $this->accountFor($user) : null);

        if ($user === null || $account === null) {
            Log::error('Quota iscrizione: conto utente mancante', ['payment' => $payment->uuid]);
            $this->markFailed($payment, 'Conto dell utente non disponibile.');

            return;
        }

        $superAdminId = User::where('is_super_admin', true)->value('id');
        if ($superAdminId === null) {
            Log::error('Quota iscrizione: nessun super admin per emettere i KY', ['payment' => $payment->uuid]);
            $this->markFailed($payment, 'Nessun super admin configurato.');

            return;
        }

        $doppia      = false;
        $accreditato = false;

        try {
            DB::transaction(function () use ($payment, $user, $account, $systemAccount, $superAdminId, $confirmedBy, &$doppia, &$accreditato): void {
                $locked = RegistrationFeePayment::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked === null || $locked->isCompleted()) {
                    return;
                }

                // La stessa quota pagata due volte. Succede davvero: ogni
                // click su "paga con carta" apre una riga nuova (scelta
                // voluta — riusare la riga sovrascriverebbe la sessione
                // Stripe e un pagamento su quella vecchia non verrebbe mai
                // accreditato), e le sessioni restano valide. Chi ne apre
                // due e le paga entrambe versa il doppio.
                //
                // I KY glieli accreditiamo lo stesso: quei soldi sono
                // arrivati davvero e in euro la quota E' un acquisto di KY,
                // quindi restituire il nulla sarebbe peggio. Ma non deve
                // sparire in silenzio — questa riga e' l'unica cosa che, fra
                // sei mesi, permettera' di rispondere a "ho pagato due volte".
                $doppia = User::whereKey($user->id)->value('registration_fee_paid_at') !== null;

                // L'emissione dal conto di sistema richiede un super admin:
                // e' l'unico che bypassa autorizzazione e fido nel motore
                // (stessa scelta di ReferralBonusService).
                $transfer = $this->transfers->book([
                    'initiated_by'    => $superAdminId,
                    'from_account_id' => $systemAccount->id,
                    'to_account_id'   => $account->id,
                    'amount'          => (int) $locked->ky_amount,
                    'kind'            => 'registration_fee_credit',
                    'description'     => 'Quota di iscrizione pagata in euro: accredito KY',
                    'idempotency_key' => 'regfee_' . $locked->uuid,
                ]);

                $locked->update([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $account->id,
                    'status'       => RegistrationFeePayment::STATUS_COMPLETED,
                    'confirmed_by' => $confirmedBy,
                    'completed_at' => now(),
                ]);

                User::whereKey($user->id)
                    ->whereNull('registration_fee_paid_at')
                    ->update(['registration_fee_paid_at' => now()]);

                AuditLog::create([
                    'actor_user_id'  => $confirmedBy ?? $user->id,
                    'event'          => 'registration_fee.paid_in_eur',
                    'auditable_type' => RegistrationFeePayment::class,
                    'auditable_id'   => $locked->id,
                    'context'        => [
                        'uuid'           => $locked->uuid,
                        'amount'         => (int) $locked->amount_eur_cents,
                        'payment_method' => $locked->payment_method,
                        'transfer_id'    => $transfer->id,
                        // true = la quota risultava gia' saldata da un ALTRO
                        // pagamento: questo e' un secondo incasso per la
                        // stessa quota, non un accredito ripetuto.
                        'quota_gia_saldata' => $doppia,
                    ],
                ]);

                // Solo qui, e non fuori guardando lo stato: la closure puo'
                // essere uscita prima (un'altra richiesta l'aveva gia'
                // accreditata) e in quel caso la ricevuta l'ha gia' mandata
                // quell'altra.
                $accreditato = true;
            });
        } catch (\Throwable $e) {
            Log::error('Quota iscrizione: accredito KY fallito', [
                'payment' => $payment->uuid,
                'error'   => $e->getMessage(),
            ]);
            $this->markFailed($payment, $e->getMessage());

            return;
        }

        if ($doppia) {
            Log::warning('Quota iscrizione: incassata due volte dalla stessa persona', [
                'payment' => $payment->uuid,
                'user'    => $user->id,
                'amount'  => (int) $payment->amount_eur_cents,
                'method'  => $payment->payment_method,
            ]);
        }

        $payment->refresh();

        if ($accreditato) {
            $user->notify(new RegistrationFeePaidNotification($payment));
        }
    }

    // ── Ripescaggio di un pagamento in euro incassato (01/09/2026) ──────────

    /**
     * Riprova l'accredito dei KY su un pagamento in euro finito `failed`.
     *
     * IL CASO CHE CHIUDE. completeEuroPayment(), se qualcosa va storto
     * mentre scrive (un deadlock, il conto di sistema irraggiungibile per un
     * istante), chiama markFailed(). Ma a quel punto Stripe o PayPal i soldi
     * li hanno GIA' presi. Da li' in poi non si rimette in moto niente da
     * solo: il webhook che ritenta trova `isPending()` falso e salta, la
     * pagina di successo pure, e in backoffice Conferma/Rifiuta valgono solo
     * sui bonifici e «Annulla quota» solo sulle quote saldate. Restava una
     * persona che ha pagato, senza KY, e nessuna strada che non fosse il
     * database a mano.
     *
     * PERCHE' NON E' UN BOTTONE CHE CREA MONETA. Questo metodo non decide
     * niente da solo: chi lo chiama deve avere gia' in mano la PROVA
     * dell'incasso — la sessione Stripe verificata dal server di Stripe,
     * l'ordine PayPal risultato COMPLETED, o, per il bonifico, un admin che
     * ha visto i soldi sul conto. Vedi
     * RegistrationFeeController::adminRetryCredit(), dove la prova si
     * raccoglie.
     *
     * L'accredito vero e' lo stesso di sempre, con la stessa
     * idempotency_key: se per caso il transfer originale era gia' passato,
     * il motore restituisce quello e non ne crea un secondo.
     *
     * @throws RuntimeException
     */
    public function retryEuroCredit(RegistrationFeePayment $payment, User $admin, ?string $ipAddress = null): void
    {
        if ($payment->isCompleted()) {
            throw new RuntimeException('Questa quota risulta già saldata.');
        }

        if ($payment->payment_method === RegistrationFeePayment::METHOD_KY) {
            throw new RuntimeException('Il ripescaggio vale solo per i pagamenti in euro: in KY non c\'è nessun incasso da recuperare.');
        }

        if ($payment->isCancelled()) {
            throw new RuntimeException('Questa quota è stata annullata: riaprila prima di accreditarla.');
        }

        AuditLog::create([
            'actor_user_id'  => $admin->id,
            'event'          => 'registration_fee.credit_retried',
            'auditable_type' => RegistrationFeePayment::class,
            'auditable_id'   => $payment->id,
            'ip_address'     => $ipAddress,
            'context'        => [
                'uuid'           => $payment->uuid,
                'user_id'        => $payment->user_id,
                'payment_method' => $payment->payment_method,
                'stato_prima'    => $payment->status,
                'note_prima'     => $payment->admin_notes,
            ],
        ]);

        $this->completeEuroPayment($payment, $admin->id);

        $payment->refresh();

        if (! $payment->isCompleted()) {
            // markFailed() ha gia' riscritto il motivo: ridarlo all'admin e'
            // l'unico modo perche' non riprema il bottone all'infinito.
            throw new RuntimeException('L\'accredito è fallito di nuovo: ' . ($payment->admin_notes ?: 'motivo non registrato'));
        }
    }

    // ── Annullamento di una quota gia' saldata (01/09/2026) ─────────────────

    /**
     * Disfa una quota saldata: storna il movimento, rimette la quota fra
     * quelle da pagare e toglie il fido aggiuntivo. Le tre cose insieme.
     *
     * PERCHE' NON SI FA ELIMINANDO IL MOVIMENTO. Cancellare il movimento da
     * /admin/movimenti ripristina i saldi e basta: la quota resta scritta
     * come pagata e il fido aggiuntivo resta addosso all'utente, che si
     * ritrova dentro il circuito gratis e con 30 KY di scoperto in piu'. Per
     * questo i movimenti di quota non sono piu' eliminabili da li'
     * (AdminController::MOVIMENTI_DI_QUOTA) e l'unica strada e' questa.
     *
     * LO STORNO SI FA SOLO SE IL MOVIMENTO C'E' ANCORA. Chi ha gia' cancellato
     * il movimento a mano — prima che quella strada venisse chiusa — ha gia'
     * avuto indietro i suoi KY: stornare di nuovo glieli regalerebbe una
     * seconda volta. Qui si guarda il movimento, non lo stato del pagamento.
     *
     * @throws RuntimeException
     */
    public function cancel(RegistrationFeePayment $payment, User $admin, ?string $reason = null, ?string $ipAddress = null): RegistrationFeePayment
    {
        if (! $payment->isCompleted()) {
            throw new RuntimeException("Si può annullare solo una quota già saldata.");
        }

        $superAdminId = User::where('is_super_admin', true)->value('id');
        if ($superAdminId === null) {
            throw new RuntimeException("Nessun super admin configurato: lo storno non può essere emesso.");
        }

        DB::transaction(function () use ($payment, $admin, $reason, $ipAddress, $superAdminId): void {
            $locked = RegistrationFeePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw new RuntimeException("Questa quota è già stata annullata.");
            }

            // Il movimento originale, se esiste ancora ed e' contabilizzato.
            $originale = $locked->transfer_id !== null
                ? Transfer::whereKey($locked->transfer_id)->where('status', 'booked')->first()
                : null;

            $stornoId = null;

            if ($originale !== null) {
                // Storno per inversione dei conti: funziona identico nei due
                // versi (in KY l'utente aveva pagato il sistema, in euro il
                // sistema aveva accreditato l'utente) senza doverli
                // distinguere qui.
                $storno = $this->transfers->book([
                    'initiated_by'    => $superAdminId,
                    'from_account_id' => $originale->to_account_id,
                    'to_account_id'   => $originale->from_account_id,
                    'amount'          => (int) $originale->amount,
                    'kind'            => 'registration_fee_reversal',
                    'description'     => 'Storno della quota di iscrizione',
                    'idempotency_key' => 'regfee_storno_' . $locked->uuid,
                    'ip_address'      => $ipAddress,
                ]);

                $stornoId = $storno->id;
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->first();

            if ($user !== null) {
                $user->forceFill([
                    // La quota torna dovuta. L'importo e' quello di questo
                    // pagamento, non quello di oggi in impostazioni: chi si
                    // era registrato a 30 continua a dovere 30 anche se nel
                    // frattempo la quota e' passata a 50.
                    // `?:` e non `??`: vale anche per lo zero della quota
                    // sospesa, che altrimenti resterebbe zero e la quota non
                    // tornerebbe dovuta pur essendo stata annullata.
                    'registration_fee_due_cents'          => $user->registration_fee_due_cents ?: (int) $locked->amount_eur_cents,
                    'registration_fee_paid_at'            => null,
                    // Il fido aggiuntivo se ne va con la quota che lo aveva
                    // motivato: era li' solo per reggere il -30.
                    'registration_fee_ky_allowance_cents' => 0,
                ])->save();
            }

            $locked->update([
                'status'       => RegistrationFeePayment::STATUS_CANCELLED,
                'admin_notes'  => $reason ?? 'Quota annullata dal backoffice.',
                'confirmed_by' => $admin->id,
            ]);

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'registration_fee.cancelled',
                'auditable_type' => RegistrationFeePayment::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'uuid'                 => $locked->uuid,
                    'user_id'              => $locked->user_id,
                    'amount'               => (int) $locked->ky_amount,
                    'payment_method'       => $locked->payment_method,
                    'original_transfer_id' => $locked->transfer_id,
                    'reversal_transfer_id' => $stornoId,
                    // Se qui c'e' false, il movimento era gia' stato
                    // cancellato a mano e i KY erano gia' tornati indietro.
                    'reversal_booked'      => $stornoId !== null,
                    'reason'               => $reason,
                ],
            ]);
        });

        $payment->refresh();

        if ($payment->user !== null) {
            $payment->user->notify(new RegistrationFeeCancelledNotification($payment));
        }

        return $payment;
    }

    // ── L'admin mette la quota in carico a un utente (01/09/2026) ───────────

    /**
     * Richiesta di Laura: l'admin deve poter chiedere la quota a chi non
     * l'ha pagata, compresi i privati gia' iscritti da prima che la quota
     * esistesse (per loro registration_fee_due_cents e' NULL).
     *
     * UNO ALLA VOLTA, DALLA SCHEDA DELL'UTENTE. E' l'unica differenza che
     * conta rispetto a un UPDATE sulla colonna: quello metterebbe in debito
     * milletrecento persone in un colpo solo e non lascerebbe traccia di chi
     * ha deciso cosa. Qui ogni addebito ha un nome, una data e un audit log.
     *
     * @throws RuntimeException
     */
    public function requestFrom(User $user, User $admin, ?string $ipAddress = null): int
    {
        if ($user->account_holder_type !== 'private') {
            throw new RuntimeException("La quota di iscrizione riguarda solo i privati: le aziende hanno i piani di abbonamento.");
        }

        if ($user->registration_fee_paid_at !== null) {
            throw new RuntimeException("Questo utente ha già saldato la quota. Per rimettergliela in carico, annulla il pagamento dalla pagina Quote di iscrizione.");
        }

        $settings = $this->settings();
        $importo  = $settings->registrationFeeAmount();

        if ($importo <= 0) {
            throw new RuntimeException("L'importo della quota è a zero: impostalo prima di chiederla a qualcuno.");
        }

        // Chiedere la quota a chi poi non ha nessun bottone per pagarla vuol
        // dire bloccargli il conto senza via d'uscita.
        if ($settings->registrationFeeMethods() === []) {
            throw new RuntimeException("Nessun metodo di pagamento è disponibile: l'utente non avrebbe modo di saldare.");
        }

        DB::transaction(function () use ($user, $admin, $importo, $ipAddress): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Unica guardia su "ce l'ha gia' aperta", e sta DENTRO il lock:
            // una copia qui fuori sembrerebbe piu' gentile (errore prima di
            // aprire una transazione) ma sarebbe l'ennesima coppia di difese
            // che si nascondono a vicenda dal mutation testing — verde anche
            // spegnendone una.
            // `> 0` e non `!== null`: zero e' la quota SOSPESA di chi e'
            // entrato dalla porta dell'agente, e l'admin deve poterla
            // accendere lo stesso — e' un atto esplicito con un nome sopra.
            if ((int) ($locked->registration_fee_due_cents ?? 0) > 0 && $locked->registration_fee_paid_at === null) {
                throw new RuntimeException("Questo utente ha già la quota da pagare.");
            }

            $locked->forceFill([
                'registration_fee_due_cents' => $importo,
                'registration_fee_paid_at'   => null,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'registration_fee.requested_by_admin',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'amount'    => $importo,
                    'user_email' => $locked->email,
                ],
            ]);
        });

        $user->refresh();
        $user->notify(new RegistrationFeeRequestedNotification($importo));

        return $importo;
    }

    public function markFailed(RegistrationFeePayment $payment, ?string $reason = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $payment->update([
            'status'      => RegistrationFeePayment::STATUS_FAILED,
            'admin_notes' => $reason ?? $payment->admin_notes,
        ]);
    }
}
