<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Contracts\FeePayment;
use App\Models\AuditLog;
use App\Models\RegistrationFeePayment;
use App\Notifications\RegistrationFeeCancelledNotification;
use App\Notifications\RegistrationFeePaidNotification;
use App\Notifications\RegistrationFeeRequestedNotification;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Services\Fees\AbstractFeeService;
use App\Services\Fees\FeeDefinition;
use Illuminate\Database\Eloquent\Model;
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
class RegistrationFeeService extends AbstractFeeService
{
    public function definition(): FeeDefinition
    {
        return new FeeDefinition(
            paymentClass:                RegistrationFeePayment::class,
            dueColumn:                   'registration_fee_due_cents',
            paidAtColumn:                'registration_fee_paid_at',
            allowanceColumn:             'registration_fee_ky_allowance_cents',
            auditPrefix:                 'registration_fee',
            idempotencyPrefix:           'regfee_',
            // In euro il circuito EMETTE i KY: la quota non e' un costo, e' un
            // acquisto di KY. E' la differenza sostanziale con i 480.
            emitsKyInEuro:               true,
            paidNotification:            RegistrationFeePaidNotification::class,
            cancelledNotification:       RegistrationFeeCancelledNotification::class,
            kyTransferKind:              'registration_fee',
            kyTransferDescription:       'Quota di iscrizione al circuito',
            creditTransferKind:          'registration_fee_credit',
            creditTransferDescription:   'Quota di iscrizione pagata in euro: accredito KY',
            reversalTransferKind:        'registration_fee_reversal',
            reversalTransferDescription: 'Storno della quota di iscrizione',
            notDueMessage:               'La quota di iscrizione risulta già saldata.',
            retryOnCancelledMessage:     'Questa quota è stata annullata: riaprila prima di accreditarla.',
            retryFailedMessage:          'L\'accredito è fallito di nuovo: ',
        );
    }

    public function availableMethods(): array
    {
        return $this->settings()->registrationFeeMethods();
    }

    /**
     * L'emissione dei KY dal conto di sistema ha tre presupposti, e ognuno
     * spiega da solo perche' una riga puo' finire `failed` con quel testo in
     * admin_notes.
     */
    protected function euroSettlementBlocker(Model&FeePayment $payment, ?User $user): ?string
    {
        if (Account::systemAccount() === null) {
            return 'Conto di sistema non disponibile.';
        }

        if ($user === null || ($payment->account ?? $this->accountFor($user)) === null) {
            return 'Conto dell utente non disponibile.';
        }

        // L'emissione dal conto di sistema richiede un super admin: e' l'unico
        // che bypassa autorizzazione e fido nel motore (stessa scelta di
        // ReferralBonusService).
        if (User::where('is_super_admin', true)->value('id') === null) {
            return 'Nessun super admin configurato.';
        }

        return null;
    }

    /**
     * In euro la quota dei privati EMETTE moneta: il conto di sistema accredita
     * all'utente l'importo della quota. Per lui la quota non e' un costo in KY,
     * ha comprato KY — e' lo stesso identico movimento di una ricarica KYCard.
     */
    protected function settleEuroPayment(Model&FeePayment $locked, User $user): ?int
    {
        $transfer = $this->transfers->book([
            'initiated_by'    => User::where('is_super_admin', true)->value('id'),
            'from_account_id' => Account::systemAccount()->id,
            'to_account_id'   => ($locked->account ?? $this->accountFor($user))->id,
            'amount'          => (int) $locked->ky_amount,
            'kind'            => $this->definition()->creditTransferKind,
            'description'     => $this->definition()->creditTransferDescription,
            'idempotency_key' => $this->definition()->idempotencyKey($locked->uuid),
        ]);

        return $transfer->id;
    }

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
     * L'IMPORTO E' LO SCATTO SOSPESO, quando c'e' (02/09/2026). Fino a oggi
     * qui si leggeva sempre la cifra di OGGI, con la motivazione che «la
     * colonna conteneva zero, non c'era nessun importo da conservare»: non e'
     * piu' vero da quando esiste suspendOnAgentApproval(), che l'importo di
     * prima lo scrive nell'audit log `registration_fee.suspended_on_agent_
     * approval`. Senza leggerlo, chi si era registrato a 30 ed era stato
     * approvato agente si ritrovava a dovere 50 se nel frattempo la quota era
     * cambiata. E' lo stesso ripiego, e per lo stesso motivo, di
     * AgentCodeFeeService::revokeWaiver().
     *
     * Resta la cifra di oggi nell'unico caso in cui non c'e' nessuno scatto da
     * conservare: chi e' nato con la quota gia' sospesa (suspendForAgentPath,
     * dal portale di un agente), che un importo in carico non lo ha mai avuto.
     *
     * L'INTERRUTTORE VALE SOLO IN QUEL SECONDO CASO, e l'asimmetria e' voluta:
     * suspendOnAgentApproval() non lo guarda affatto — quei 30 erano gia'
     * dovuti e vanno sospesi comunque — mentre qui, prima del 02/09, lo si
     * guardava sempre. Il risultato era che spegnere l'interruttore un giorno e
     * riaccenderlo il giorno dopo condonava in silenzio chiunque fosse uscito
     * dal percorso nel frattempo: la colonna restava a zero e nessun sollecito
     * la trovava piu' (il comando filtra `> 0`). Una quota gia' messa in carico
     * si riaccende com'era; l'interruttore decide se la quota si CHIEDE a
     * qualcuno di nuovo, non se un debito gia' esistente si puo' cancellare.
     *
     * CHI I 480 LI HA PAGATI DAVVERO NON DEVE ANCHE I 30 (02/09/2026). La
     * quota del codice agente e' un INGRESSO nel circuito, ed e' sedici volte
     * quello dei privati: chi l'ha saldata e poi esce dal percorso — rinuncia
     * sua o rifiuto dell'admin — ha gia' pagato per entrare, e riaccendergli i
     * 30 vorrebbe dire chiedere altri soldi a chi ne ha appena versati 480 per
     * un codice che non avra' mai. Fino al 02/09 succedeva esattamente questo,
     * e per giunta in silenzio: la notifica gli arrivava senza spiegazione.
     *
     * In quel caso la colonna va a NULL e non resta a zero, ed e' voluto:
     * isSuspendedFor() significa "nel circuito non ha ancora pagato NESSUN
     * ingresso" ed e' cio' che il middleware legge per decidere quanto
     * stringere. Lasciarla a zero direbbe il falso su una persona che
     * l'ingresso lo ha pagato piu' caro di tutti.
     *
     * @return int i centesimi ora dovuti, 0 se non si e' acceso niente
     */
    public function resumeAfterAgentPath(User $user, ?string $ipAddress = null): int
    {
        $sospeso = $this->importoSospesoInCarico($user);

        if ($sospeso !== null) {
            // Era gia' dovuta prima di essere sospesa: torna dovuta com'era, e
            // l'interruttore non c'entra (vedi il docblock).
            $importo = $sospeso;
        } else {
            // Nasceva sospesa: non e' mai stata in carico, quindi vale la
            // regola di oggi — interruttore compreso.
            if (! $this->settings()->registrationFeeEnabled()) {
                return 0;
            }

            $importo = $this->settings()->registrationFeeAmount();
        }

        if ($importo <= 0) {
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

            // Ha saldato i 480 del codice agente: l'ingresso nel circuito lo
            // ha gia' pagato, e di piu'. La quota sospesa non si riaccende e
            // non resta nemmeno sospesa — si spegne per sempre (vedi il
            // docblock). Nessuna notifica: non c'e' niente da comunicare,
            // dirgli "la tua quota non e' tornata dovuta" e' rumore.
            if ($locked->agent_code_fee_paid_at !== null) {
                $locked->forceFill(['registration_fee_due_cents' => null])->save();

                AuditLog::create([
                    'actor_user_id'  => $locked->id,
                    'event'          => 'registration_fee.settled_by_agent_fee',
                    'auditable_type' => User::class,
                    'auditable_id'   => $locked->id,
                    'ip_address'     => $ipAddress,
                    'context'        => [
                        // Quanto NON gli e' stato richiesto. La chiave e'
                        // `amount` come in tutti gli altri eventi che scrivono
                        // questa colonna, e non una sua: se fosse diversa, il
                        // controllo sul NOME dell'evento in
                        // restoreAfterAgentFeeCancelled() non sarebbe piu'
                        // distinguibile da un controllo sulla chiave, e
                        // nessuna delle due risulterebbe provata.
                        'amount' => $importo,
                        'agent_code_fee_paid_at' => (string) $locked->agent_code_fee_paid_at,
                    ],
                ]);

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
     * Quanto doveva questa persona nel momento in cui la quota le e' stata
     * SOSPESA, letto dall'audit log della sospensione.
     *
     * Dopo lo zero non resta scritto da nessun'altra parte: la colonna e'
     * l'unico posto dove viveva l'importo, e la sospensione ce l'ha
     * sovrascritto. Stesso ripiego di AgentCodeFeeService::revokeWaiver().
     *
     * @return int|null null se non e' mai stata messa in carico, cioe' se
     *                  nasceva gia' sospesa dal portale di un agente
     */
    private function importoSospesoInCarico(User $user): ?int
    {
        $ultima = AuditLog::query()
            ->where('event', 'registration_fee.suspended_on_agent_approval')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('id')
            ->first();

        $importo = (int) ($ultima?->context['amount'] ?? 0);

        return $importo > 0 ? $importo : null;
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

    // ── L'ingresso pagato torna indietro (02/09/2026) ───────────────────────

    /**
     * Gli eventi che SCRIVONO `registration_fee_due_cents` sull'utente. Servono
     * a sapere da dove viene il NULL che c'e' adesso nella colonna: il NULL da
     * solo non lo dice, ed e' lo stesso valore dei milletrecento iscritti da
     * prima che la quota esistesse.
     *
     * `registration_fee.cancelled` non e' qui perche' e' scritto sul PAGAMENTO,
     * non sull'utente; `registration_fee.reminded` perche' non tocca la colonna.
     *
     * @var list<string>
     */
    private const EVENTI_CHE_SCRIVONO_LA_COLONNA = [
        'registration_fee.resumed_after_agent_path',
        'registration_fee.suspended_on_agent_approval',
        'registration_fee.requested_by_admin',
        'registration_fee.settled_by_agent_fee',
        'registration_fee.restored_after_agent_fee_cancelled',
    ];

    /**
     * L'admin ha annullato la quota del CODICE AGENTE: i 480 tornano indietro,
     * e con loro deve tornare indietro anche la conclusione che ne era derivata
     * — «l'ingresso nel circuito e' pagato».
     *
     * IL BUCO CHE CHIUDE, ed e' nato oggi stesso. Fino a stamattina i 30 si
     * riaccendevano SEMPRE quando uno usciva dal percorso agente, anche a chi i
     * 480 li aveva pagati: sbagliato, e corretto in 952be20. Ma la correzione
     * spegneva la quota dei privati guardando un fatto che puo' essere disfatto:
     * se poi l'admin annulla quei 480 e li restituisce, resta una persona
     * entrata dal portale di un agente, con il conto pienamente operativo, che
     * nel circuito non ha pagato niente. Lo storno rimetteva a posto il denaro
     * (saldo, fido aggiuntivo, quota di nuovo dovuta) e lasciava in piedi la
     * conseguenza.
     *
     * PERCHE' SI LEGGE L'AUDIT LOG E NON LA COLONNA. La colonna dice NULL, e
     * NULL vuol dire due cose diverse: «i 480 hanno pagato il suo ingresso»
     * (scritto da resumeAfterAgentPath) e «non l'ha mai dovuta e non la dovra'
     * mai», che e' il valore dei milletrecento iscritti da prima. Rimettere in
     * carico i 30 al secondo gruppo sarebbe un debito mai avuto. L'unica cosa
     * che distingue i due casi e' l'evento che ha scritto quel NULL, e
     * dev'essere anche l'ULTIMO ad aver toccato la colonna: se dopo e' passato
     * un admin a chiedere o a sospendere la quota, comanda quello.
     *
     * L'importo e' quello che quel giorno NON gli era stato richiesto, letto
     * dallo stesso audit log — stesso ripiego, e per lo stesso motivo, di
     * importoSospesoInCarico() e di AgentCodeFeeService::revokeWaiver().
     *
     * @return int i centesimi rimessi in carico, 0 se non c'era niente da fare
     */
    public function restoreAfterAgentFeeCancelled(User $user, ?string $ipAddress = null): int
    {
        $rimesso = 0;

        DB::transaction(function () use ($user, $ipAddress, &$rimesso): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // UNICA GUARDIA, e sta dentro il lock: l'ULTIMA cosa che ha
            // toccato questa colonna dev'essere lo spegnimento per ingresso
            // gia' pagato. Da sola dice tutto quello che serve, e le tre
            // condizioni che stavano qui prima — colonna NULL, non gia'
            // pagata, e' un privato — erano implicate da questa e la
            // nascondevano: spegnendola, i test restavano verdi (tredicesima
            // volta in questo progetto).
            //
            //   · nessun evento: e' uno dei milletrecento iscritti da prima
            //     che la quota esistesse. Non l'ha mai dovuta e non la dovra'
            //     mai: inventargli qui un debito sarebbe il danno peggiore;
            //   · un altro evento: qualcuno — di solito l'admin — ha deciso
            //     dopo, e la sua decisione vale piu' di questa ricostruzione;
            //   · questo evento: la colonna e' stata spenta perche' i 480
            //     pagavano l'ingresso, e quei 480 stanno tornando indietro.
            $ultimo = AuditLog::query()
                ->whereIn('event', self::EVENTI_CHE_SCRIVONO_LA_COLONNA)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $locked->id)
                ->latest('id')
                ->first();

            if ($ultimo?->event !== 'registration_fee.settled_by_agent_fee') {
                return;
            }

            $importo = (int) ($ultimo->context['amount'] ?? 0);

            // RIDONDANTE, e tenuta apposta — come il filtro sui bonifici in
            // quote:scadi-tentativi. Quando l'evento e' quello giusto
            // l'importo e' per forza maggiore di zero (resumeAfterAgentPath
            // scrive quel log solo in quel caso), quindi nessun test la puo'
            // distinguere e la mutazione che la toglie sopravvive. Resta
            // scritta perche' qui lo zero non e' "niente": e' la quota
            // SOSPESA, e un audit log malformato scriverebbe in silenzio uno
            // stato che significa un'altra cosa.
            if ($importo <= 0) {
                return;
            }

            $locked->forceFill([
                'registration_fee_due_cents' => $importo,
                'registration_fee_paid_at'   => null,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $locked->id,
                'event'          => 'registration_fee.restored_after_agent_fee_cancelled',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => ['amount' => $importo],
            ]);

            $rimesso = $importo;
        });

        if ($rimesso === 0) {
            return 0;
        }

        $user->refresh();

        // Da adesso il conto e' di nuovo fermo: deve sapere perche', e in che
        // rapporto sta con i 480 che gli sono appena tornati indietro.
        $user->notify(new RegistrationFeeRequestedNotification($rimesso));

        return $rimesso;
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

    // ── Apertura di un tentativo di pagamento ───────────────────────────────

    // ── Bonifico gia' richiesto (01/09/2026) ────────────────────────────────

    // ── Pagamento in KY ─────────────────────────────────────────────────────

    // ── Pagamento in euro: KNM emette i KY ──────────────────────────────────

    // ── Ripescaggio di un pagamento in euro incassato (01/09/2026) ──────────

    // ── Annullamento di una quota gia' saldata (01/09/2026) ─────────────────

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

}
