<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CompanyAccountFeePayment;
use App\Models\Contracts\FeePayment;
use App\Models\User;
use App\Notifications\CompanyAccountFeeCancelledNotification;
use App\Notifications\CompanyAccountFeePaidNotification;
use App\Notifications\CompanyAccountFeeRequestedNotification;
use App\Services\Fees\AbstractFeeService;
use App\Services\Fees\FeeDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * QUOTA DI APERTURA CONTO DELLE AZIENDE (richiesta di Laura del 03/09/2026).
 *
 * Chi si registra come azienda paga una quota una tantum — 600,00 EUR
 * l'importo di partenza — per operare con il conto. E' la terza quota del
 * circuito e gira sullo stesso motore delle altre due (AbstractFeeService):
 * qui dentro c'e' solo cio' che la distingue.
 *
 * LE QUATTRO DECISIONI DI LAURA, e cosa cambiano nel codice:
 *
 *   1. COSA RICEVE L'AZIENDA IN CAMBIO LO DECIDE L'ADMIN (04/09/2026), e sono
 *      DUE LEVE DISTINTE — non due modi di dire la stessa cosa:
 *
 *        a. chi paga in EURO riceve N KY sul conto, N deciso dall'admin
 *           (`company_account_fee_ky_credit_cents`). Zero = niente, ed e' il
 *           valore di partenza: il circuito non conia un solo KY finche' non
 *           lo scrive qualcuno. La cifra non e' legata alla quota e puo'
 *           essere piu' bassa, uguale o piu' alta: e' una decisione
 *           commerciale, non un resto.
 *        b. chi paga in KY va SOTTO — quello e' il senso del pagare in KY — e
 *           l'admin decide solo se dargli il fido aggiuntivo pari alla quota
 *           (`company_account_fee_ky_allowance`, acceso di fabbrica) oppure
 *           fargliela mangiare dal fido che ha gia'. Da spento, l'azienda
 *           senza fido proprio non riesce a pagare in KY, e va bene cosi'.
 *
 *      Le due leve NON si incrociano: chi paga in KY non riceve nessun
 *      accredito, chi paga in euro non riceve nessun fido. Ognuna ha un
 *      default nel pannello e un ripiego per singola azienda, sulla sua
 *      scheda — vedi kyCreditFor() e kyAllowanceEnabledFor().
 *
 *   2. LA QUOTA NON BLOCCA IL CONTO. Questa e' la differenza vera dalle altre
 *      due, e va tenuta a mente prima di copiare qualunque riga da li'.
 *      L'azienda che non ha saldato continua a pagare, incassare e comprare:
 *      quello che riceve e' il banner in cima al portale e un sollecito per
 *      email, e nient'altro. Percio' questa quota NON compare in
 *      EnsureRegistrationFeePaid: non e' una dimenticanza, e aggiungercela
 *      sarebbe cambiare la regola, non completarla.
 *
 *   3. SOLO LE AZIENDE CHE SI REGISTRANO DA ORA. Lo decide
 *      users.company_account_fee_due_cents, scritta una volta alla
 *      registrazione: NULL = non la deve e non la dovra' mai, ed e' il valore
 *      che hanno le ~1.200 anagrafiche importate dal vecchio sito. L'admin
 *      puo' metterla in carico a una alla volta (requestFrom), dalla scheda
 *      dell'utente.
 *
 *   4. IL PAGAMENTO IN KY E' UNA CONCESSIONE. Nasce spento
 *      (company_account_fee_ky_enabled = 0, unico default diverso dalle altre
 *      due quote): l'admin lo accende dal backoffice quando vuole accettare
 *      600 KY al posto di 600 euro. E vale la pena sapere cosa vuol dire —
 *      600 KY di scoperto sono moneta creata dal circuito, venti volte i 30 di
 *      un privato.
 *
 * LO ZERO, QUI, NON SIGNIFICA NIENTE DI SPECIALE. Nelle altre due quote e' un
 * terzo stato (SOSPESA nei privati, ESONERATA negli agenti); qui la colonna ha
 * due soli valori sensati, NULL e l'importo dovuto. Se un giorno servisse un
 * esonero, e' li' che andrebbe messo — e andra' scritto, perche' il motore
 * comune tratta lo zero come "niente da pagare" in tutte e tre.
 *
 * CHI E' UN'AZIENDA. Due condizioni insieme, e non una sola: account_holder_type
 * === 'company' E company_id valorizzato. Il motivo sta in riguarda().
 */
class CompanyAccountFeeService extends AbstractFeeService
{
    public function definition(): FeeDefinition
    {
        return new FeeDefinition(
            paymentClass:                CompanyAccountFeePayment::class,
            dueColumn:                   'company_account_fee_due_cents',
            paidAtColumn:                'company_account_fee_paid_at',
            allowanceColumn:             'company_account_fee_ky_allowance_cents',
            auditPrefix:                 'company_account_fee',
            idempotencyPrefix:           'aperturaconto_',
            // Puo' emettere KY pagando in euro, ma QUANTI lo decide l'admin e
            // di partenza sono zero: il ramo vero sta in settleEuroPayment().
            // Il motore non legge questo campo — e' documentazione — e
            // scriverci `false` direbbe il falso appena l'admin imposta un
            // accredito.
            emitsKyInEuro:               true,
            paidNotification:            CompanyAccountFeePaidNotification::class,
            cancelledNotification:       CompanyAccountFeeCancelledNotification::class,
            kyTransferKind:              'company_account_fee',
            kyTransferDescription:       'Quota di apertura conto azienda',
            // Usati solo quando l'admin ha impostato un accredito maggiore di
            // zero: sono il movimento con cui il conto di sistema emette quei
            // KY verso l'azienda.
            creditTransferKind:          'company_account_fee_credit',
            creditTransferDescription:   'Quota di apertura conto: accredito KY',
            reversalTransferKind:        'company_account_fee_reversal',
            reversalTransferDescription: 'Storno della quota di apertura conto',
            notDueMessage:               'La quota di apertura conto risulta già saldata.',
            retryOnCancelledMessage:     'Questa quota è stata annullata: riaprila prima di darla per saldata.',
            retryFailedMessage:          'La chiusura è fallita di nuovo: ',
        );
    }

    public function availableMethods(): array
    {
        return $this->settings()->companyAccountFeeMethods();
    }

    /**
     * I presupposti dipendono da quanto c'e' da accreditare: senza accredito
     * basta che l'utente ci sia, con accredito servono le tre cose che servono
     * a emettere moneta. Ognuno di questi testi finisce in `admin_notes` ed e'
     * quello che l'admin legge per capire perche' una riga e' finita `failed`.
     */
    protected function euroSettlementBlocker(Model&FeePayment $payment, ?User $user): ?string
    {
        if ($user === null) {
            return 'Utente non disponibile.';
        }

        if ($this->kyCreditFor($user) <= 0) {
            return null;
        }

        if (Account::systemAccount() === null) {
            return 'Conto di sistema non disponibile.';
        }

        if (($payment->account ?? $this->accountFor($user)) === null) {
            return 'Conto dell azienda non disponibile.';
        }

        // L'emissione dal conto di sistema richiede un super admin: e' l'unico
        // che bypassa autorizzazione e fido nel motore.
        if (User::where('is_super_admin', true)->value('id') === null) {
            return 'Nessun super admin configurato.';
        }

        return null;
    }

    /**
     * In euro il conto viene toccato SOLO SE l'admin ha deciso un accredito:
     * il conto di sistema emette quei KY verso l'azienda, ed e' lo stesso
     * movimento di una ricarica. Con accredito a zero non si muove niente, e
     * allora l'unica difesa contro il doppio incasso e' il lock di
     * completeEuroPayment() — non c'e' nessuna idempotency_key perche' non c'e'
     * nessun movimento.
     *
     * L'IMPORTO E' QUELLO DI OGGI, non uno scatto congelato alla registrazione
     * come la quota. E' voluto: la quota e' un debito che l'azienda si e'
     * assunta a una certa cifra, l'accredito e' una decisione commerciale del
     * circuito, e vale quella in vigore quando i soldi arrivano. Quanto sia
     * stato dato resta scritto nel movimento e nell'audit log.
     */
    protected function settleEuroPayment(Model&FeePayment $locked, User $user): ?int
    {
        $accredito = $this->kyCreditFor($user);

        if ($accredito <= 0) {
            return null;
        }

        $transfer = $this->transfers->book([
            'initiated_by'    => User::where('is_super_admin', true)->value('id'),
            'from_account_id' => Account::systemAccount()->id,
            'to_account_id'   => ($locked->account ?? $this->accountFor($user))->id,
            'amount'          => $accredito,
            'kind'            => $this->definition()->creditTransferKind,
            'description'     => $this->definition()->creditTransferDescription,
            'idempotency_key' => $this->definition()->idempotencyKey($locked->uuid),
        ]);

        return $transfer->id;
    }

    // ── Le due leve: cosa riceve l'azienda in cambio ────────────────────────

    /**
     * Quanti KY riceve questa azienda pagando in EURO, in centesimi.
     *
     * Il ripiego sulla singola azienda vince sul default del pannello, e NULL
     * vuol dire "segui il default" — non "zero". La differenza conta: azzerare
     * l'accredito per una sola azienda si fa scrivendo 0, non svuotando il
     * campo, e sono due atti diversi che l'audit log distingue.
     */
    public function kyCreditFor(User $user): int
    {
        $suo = $user->company_account_fee_ky_credit_override_cents;

        return $suo !== null
            ? max(0, (int) $suo)
            : $this->settings()->companyAccountFeeKyCredit();
    }

    /**
     * Questa azienda, pagando in KY, riceve il fido aggiuntivo pari alla quota?
     * Stessa regola del ripiego: NULL segue il pannello.
     */
    public function kyAllowanceEnabledFor(User $user): bool
    {
        $suo = $user->company_account_fee_ky_allowance_override;

        return $suo !== null
            ? (bool) $suo
            : $this->settings()->companyAccountFeeKyAllowance();
    }

    /**
     * Il fido aggiuntivo per chi paga in KY. Zero quando l'admin ha deciso di
     * non darlo: l'azienda va sotto lo stesso, ma con il fido che ha gia', e
     * se non le basta l'addebito viene rifiutato dal motore.
     */
    protected function kyAllowanceFor(User $user, int $amount): int
    {
        return $this->kyAllowanceEnabledFor($user) ? $amount : 0;
    }

    /**
     * Il conto su cui addebitare la quota pagata in KY.
     *
     * PERCHE' NON BASTA QUELLO DEL MOTORE. AbstractFeeService cerca il conto
     * per `owner_user_id`, che per un'azienda registrata dal portale pubblico
     * e' valorizzato (AuthController lo mette). Non lo e' per le aziende
     * importate dal vecchio database, dove il conto e' legato all'azienda
     * (`company_id`) e non alla persona: quelle sono proprio le ~1.200 a cui
     * l'admin puo' mettere la quota in carico a mano, e senza questo ripiego
     * il pagamento in KY si fermerebbe su "nessun conto attivo trovato".
     *
     * Il conto personale resta il primo cercato: se una persona ne ha uno suo,
     * la quota si addebita li' e non sul conto dell'azienda.
     */
    public function accountFor(User $user): ?Account
    {
        $personale = parent::accountFor($user);

        if ($personale !== null || $user->company_id === null) {
            return $personale;
        }

        return Account::query()
            ->where('company_id', $user->company_id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    // ── Chi la deve ─────────────────────────────────────────────────────────

    /**
     * Questa quota riguarda questo utente?
     *
     * DUE CONDIZIONI E NON UNA, e la seconda evita due incidenti veri:
     *
     *   - `account_holder_type` da solo direbbe di si' anche per gli ADMIN e i
     *     SUPER ADMIN, che nascono con 'company' e company_id NULL (vedi il
     *     seeder e ImportOldData), e per i COLLABORATORI invitati come
     *     sottoconto, che il campo non lo passano affatto e cadono sul default
     *     del database, che e' 'company'. Nessuno dei due ha aperto un conto
     *     aziendale, e nessuno dei due deve vedersi chiedere 600 euro.
     *   - `company_id` da solo prenderebbe anche i collaboratori di
     *     un'azienda gia' dentro: la quota e' dell'apertura del conto, si paga
     *     una volta, e chi viene aggiunto dopo non riapre niente. Per questo
     *     la quota si segna solo alla registrazione (markDueOnRegistration),
     *     che e' la porta da cui passa il titolare e nessun altro.
     */
    public function riguarda(?User $user): bool
    {
        return $user !== null
            && $user->account_holder_type === 'company'
            && $user->company_id !== null;
    }

    // ── Registrazione ───────────────────────────────────────────────────────

    /**
     * Marca il debito sulla nuova azienda. Non bloccante: se qualcosa va storto
     * qui, la registrazione deve comunque riuscire — una quota non segnata si
     * rimette a mano, una registrazione persa no. Stessa scelta, e stesso
     * motivo, di RegistrationFeeService::markDueOnRegistration().
     */
    public function markDueOnRegistration(User $user): void
    {
        try {
            $settings = $this->settings();

            if (! $settings->companyAccountFeeEnabled()) {
                return;
            }

            if (! $this->riguarda($user)) {
                return;
            }

            // Mai sovrascrivere una colonna gia' scritta.
            if ($user->company_account_fee_due_cents !== null) {
                return;
            }

            $user->forceFill([
                'company_account_fee_due_cents' => $settings->companyAccountFeeAmount(),
                'company_account_fee_paid_at'   => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Quota apertura conto: impossibile marcare il debito', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── L'admin mette la quota in carico ────────────────────────────────────

    /**
     * L'admin chiede la quota a un'azienda che non la deve: le ~1.200
     * importate dal vecchio sito, o chi si e' registrato mentre la quota era
     * spenta.
     *
     * UNA ALLA VOLTA, DALLA SCHEDA DELL'UTENTE. E' l'unica differenza che
     * conta rispetto a un UPDATE sulla colonna: quello metterebbe in debito
     * milleduecento aziende in un colpo solo e non lascerebbe traccia di chi
     * ha deciso cosa. Qui ogni addebito ha un nome, una data e un audit log.
     *
     * @throws RuntimeException
     */
    public function requestFrom(User $user, User $admin, ?string $ipAddress = null): int
    {
        if (! $this->riguarda($user)) {
            throw new RuntimeException("La quota di apertura conto riguarda solo le aziende: questo profilo non è un conto aziendale.");
        }

        if ($user->company_account_fee_paid_at !== null) {
            throw new RuntimeException("Questa azienda ha già saldato la quota. Per rimettergliela in carico, annulla il pagamento dalla pagina Quote apertura conto.");
        }

        $settings = $this->settings();
        $importo  = $settings->companyAccountFeeAmount();

        if ($importo <= 0) {
            throw new RuntimeException("L'importo della quota è a zero: impostalo prima di chiederla a qualcuno.");
        }

        // Chiedere la quota a chi poi non ha nessun bottone per pagarla vuol
        // dire mandargli una mail su una pagina vuota.
        if ($settings->companyAccountFeeMethods() === []) {
            throw new RuntimeException("Nessun metodo di pagamento è disponibile: l'azienda non avrebbe modo di saldare.");
        }

        DB::transaction(function () use ($user, $admin, $importo, $ipAddress): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Unica guardia su "ce l'ha gia' aperta", e sta DENTRO il lock:
            // una copia qui fuori sarebbe l'ennesima coppia di difese che si
            // nascondono a vicenda dal mutation testing.
            if ((int) ($locked->company_account_fee_due_cents ?? 0) > 0 && $locked->company_account_fee_paid_at === null) {
                throw new RuntimeException("Questa azienda ha già la quota da pagare.");
            }

            $locked->forceFill([
                'company_account_fee_due_cents' => $importo,
                'company_account_fee_paid_at'   => null,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => 'company_account_fee.requested_by_admin',
                'auditable_type' => User::class,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'amount'     => $importo,
                    'user_email' => $locked->email,
                    'company_id' => $locked->company_id,
                ],
            ]);
        });

        $user->refresh();
        $user->notify(new CompanyAccountFeeRequestedNotification($importo));

        return $importo;
    }

    // ── Il trattamento della singola azienda (04/09/2026) ───────────────────

    /**
     * L'admin scavalca, per QUESTA azienda, i due default del pannello: quanti
     * KY riceve pagando in euro, e se pagando in KY ha il fido aggiuntivo.
     *
     * `null` su un parametro vuol dire «torna a seguire il pannello», e non e'
     * la stessa cosa di `0` / `false`: quelli sono decisioni prese per questa
     * azienda e restano ferme anche se domani il default cambia. La differenza
     * e' l'unico motivo per cui questo metodo esiste invece di due update.
     *
     * NON tocca chi ha gia' pagato: il fido concesso e i KY accreditati sono
     * fatti avvenuti, e si disfano annullando la quota, non cambiando la
     * regola. Cambiare il trattamento a saldo avvenuto vale per la prossima
     * volta — che per una quota una tantum vuol dire, in pratica, mai.
     *
     * @throws RuntimeException
     */
    public function setTreatment(
        User $user,
        User $admin,
        ?int $creditCents,
        ?bool $allowance,
        ?string $ipAddress = null,
    ): void {
        if (! $this->riguarda($user)) {
            throw new RuntimeException("Questo profilo non e' un conto aziendale: non ha un trattamento da impostare.");
        }

        if ($creditCents !== null && $creditCents < 0) {
            throw new RuntimeException("L'accredito in KY non puo' essere negativo.");
        }

        $prima = [
            'credit'    => $user->company_account_fee_ky_credit_override_cents,
            'allowance' => $user->company_account_fee_ky_allowance_override,
        ];

        $user->forceFill([
            'company_account_fee_ky_credit_override_cents' => $creditCents,
            'company_account_fee_ky_allowance_override'    => $allowance,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $admin->id,
            'event'          => 'company_account_fee.treatment_set',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $ipAddress,
            'context'        => [
                'prima'      => $prima,
                'dopo'       => ['credit' => $creditCents, 'allowance' => $allowance],
                // Cosa vorranno dire quei due valori quando qualcuno leggera'
                // questa riga fra sei mesi: i default di oggi.
                'default_credit'    => $this->settings()->companyAccountFeeKyCredit(),
                'default_allowance' => $this->settings()->companyAccountFeeKyAllowance(),
                'gia_saldata'       => $user->company_account_fee_paid_at !== null,
            ],
        ]);
    }
}
