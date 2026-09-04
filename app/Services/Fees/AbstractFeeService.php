<?php

namespace App\Services\Fees;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Contracts\FeePayment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Transfer;
use App\Services\TransferBookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Il motore comune alle due quote del circuito (02/09/2026).
 *
 * Lo estendono RegistrationFeeService (i 30 dei privati) e AgentCodeFeeService
 * (i 480 del codice agente). Qui dentro sta tutto cio' che le due quote fanno
 * allo stesso identico modo; quello che le distingue sta in FeeDefinition, e
 * il perche' di questa separazione e' scritto la'.
 *
 * QUI NON ENTRA IL CICLO DI VITA. Le due quote nascono, si sospendono, si
 * esonerano e si chiudono in modi che non hanno niente in comune — una segue
 * la registrazione di un privato, l'altra il percorso di nomina di un agente.
 * Quei metodi restano nelle due sottoclassi, ed e' giusto che ci restino: non
 * sono duplicazione, sono due mestieri diversi.
 */
abstract class AbstractFeeService
{
    public function __construct(protected readonly TransferBookingService $transfers)
    {
    }

    /** Le differenze fra questa quota e l'altra. */
    abstract public function definition(): FeeDefinition;

    /** I metodi di pagamento accesi per questa quota, come ['stripe' => 'Carta…']. */
    abstract public function availableMethods(): array;

    public function settings(): SystemSetting
    {
        return SystemSetting::userLimitDefaults();
    }

    // ── Stato ───────────────────────────────────────────────────────────────

    /**
     * Il conto personale su cui addebitare o accreditare la quota.
     *
     * `whereNull('parent_account_id')`: i sottoconti non pagano quote, le paga
     * il conto principale del titolare.
     */
    public function accountFor(User $user): ?Account
    {
        return Account::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();
    }

    public function amountDueFor(User $user): int
    {
        return max(0, (int) ($user->{$this->definition()->dueColumn} ?? 0));
    }

    /**
     * L'utente ha una quota ancora da saldare?
     *
     * `> 0` e non `!== null`: lo ZERO e' un terzo valore che significa una cosa
     * diversa nelle due quote — SOSPESA nei privati, ESONERATA negli agenti —
     * e in nessuno dei due casi c'e' qualcosa da pagare.
     */
    public function isDueFor(?User $user): bool
    {
        $d = $this->definition();

        return $user !== null
            && $user->{$d->dueColumn} !== null
            && (int) $user->{$d->dueColumn} > 0
            && $user->{$d->paidAtColumn} === null;
    }

    // ── Apertura di un tentativo di pagamento ───────────────────────────────

    /**
     * @throws RuntimeException se la quota non e' dovuta o il metodo e' spento
     */
    public function startPayment(User $user, string $method): Model&FeePayment
    {
        $d = $this->definition();

        if (! $this->isDueFor($user)) {
            throw new RuntimeException($d->notDueMessage);
        }

        if (! array_key_exists($method, $this->availableMethods())) {
            throw new RuntimeException('Metodo di pagamento non disponibile.');
        }

        $amount = $this->amountDueFor($user);
        $class  = $d->paymentClass;

        return $class::create([
            'user_id'          => $user->id,
            'account_id'       => $this->accountFor($user)?->id,
            'amount_eur_cents' => $amount,
            'ky_amount'        => $amount,
            // Il bonifico ha uno stato suo perche' aspettare e' il suo
            // mestiere: e' quello che lo tiene fuori dalla scadenza notturna
            // dei tentativi abbandonati.
            'status'           => $method === $class::METHOD_BANK_TRANSFER
                ? $class::STATUS_PENDING_BANK_TRANSFER
                : $class::STATUS_PENDING,
            'payment_method'   => $method,
        ]);
    }

    public function markFailed(Model&FeePayment $payment, ?string $reason = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $payment->update([
            'status'      => $this->definition()->paymentClass::STATUS_FAILED,
            'admin_notes' => $reason ?? $payment->admin_notes,
        ]);
    }

    // ── Bonifico gia' richiesto ─────────────────────────────────────────────

    /**
     * Il bonifico in attesa di questo utente, se ce n'e' uno.
     *
     * Serve perche' il bonifico non e' un pagamento istantaneo come gli altri:
     * l'utente lo chiede, va in banca, e torna sul sito ore o giorni dopo. Se
     * in quel momento ritrova i quattro bottoni come la prima volta non sa se
     * la sua richiesta sia arrivata, e ne fa un'altra — con una causale diversa
     * da quella che ha scritto sul bonifico vero, e nessuno dei due piu'
     * ricollegabile con certezza.
     */
    public function pendingBankTransferFor(User $user): (Model&FeePayment)|null
    {
        $class = $this->definition()->paymentClass;

        return $class::query()
            ->where('user_id', $user->id)
            ->where('payment_method', $class::METHOD_BANK_TRANSFER)
            ->where('status', $class::STATUS_PENDING_BANK_TRANSFER)
            ->latest('id')
            ->first();
    }

    /**
     * Apre la richiesta di bonifico, oppure RIPRENDE quella gia' aperta.
     *
     * La causale contiene l'uuid del pagamento: aprirne una nuova a ogni visita
     * significherebbe dare all'utente una causale diversa da quella che ha gia'
     * scritto sul bonifico.
     *
     * @throws RuntimeException
     */
    public function startOrResumeBankTransfer(User $user): Model&FeePayment
    {
        $aperto = $this->pendingBankTransferFor($user);

        if ($aperto !== null && $this->isDueFor($user)) {
            return $aperto;
        }

        return $this->startPayment($user, $this->definition()->paymentClass::METHOD_BANK_TRANSFER);
    }

    /**
     * L'utente rinuncia al bonifico e torna a scegliere il metodo.
     *
     * Non e' un fallimento del circuito ma una scelta sua, e va scritta: se il
     * bonifico partisse comunque, l'admin deve poter capire dalla riga perche'
     * quella causale risulta abbandonata.
     *
     * `failed` e NON `cancelled`: se il bonifico arriva lo stesso, l'admin lo
     * deve poter ancora accreditare. `cancelled` e' riservato alle risposte
     * gia' date.
     */
    public function abandonBankTransfer(User $user): bool
    {
        $aperto = $this->pendingBankTransferFor($user);

        if ($aperto === null) {
            return false;
        }

        $this->markFailed($aperto, "L'utente ha rinunciato al bonifico e ha scelto un altro metodo.");

        return true;
    }

    // ── Pagamento in KY ─────────────────────────────────────────────────────

    /**
     * Addebita la quota sul conto dell'utente e la accredita al conto di
     * sistema (KNM). Il conto va SOTTO: e' previsto, ed e' il motivo per cui il
     * fido aggiuntivo viene concesso PRIMA dell'addebito e dentro la stessa
     * transazione — se l'addebito fallisce, il fido sparisce con lui.
     *
     * @throws RuntimeException
     */
    public function payWithKy(User $user, ?string $ipAddress = null): Model&FeePayment
    {
        $d       = $this->definition();
        $payment = $this->startPayment($user, $d->paymentClass::METHOD_KY);

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
            DB::transaction(function () use ($user, $payment, $account, $systemAccount, $amount, $ipAddress, $d): void {
                // Lock sulla riga utente: due click sul bottone "paga in KY"
                // non devono produrre due addebiti. Il secondo trova la quota
                // gia' saldata e si ferma qui.
                $locked = User::whereKey($user->id)->lockForUpdate()->first();

                if (! $this->isDueFor($locked)) {
                    throw new RuntimeException($d->notDueMessage);
                }

                // Il fido aggiuntivo PRIMA dell'addebito: senza, il motore
                // rifiuterebbe di portare sotto un conto con fido zero.
                //
                // QUANTO sia, lo decide la quota (04/09/2026): per le due
                // storiche e' sempre l'intero importo, per quella di apertura
                // conto delle aziende puo' essere zero, e in quel caso la
                // quota se la deve reggere il fido che l'azienda ha gia'. Se
                // non ce l'ha, l'addebito qui sotto viene rifiutato dal motore
                // ed e' il comportamento voluto — non un guasto.
                $locked->forceFill([$d->allowanceColumn => $this->kyAllowanceFor($locked, $amount)])->save();

                $transfer = $this->transfers->book([
                    'initiated_by'    => $user->id,
                    'from_account_id' => $account->id,
                    'to_account_id'   => $systemAccount->id,
                    'amount'          => $amount,
                    'kind'            => $d->kyTransferKind,
                    'description'     => $d->kyTransferDescription,
                    'idempotency_key' => $d->idempotencyKey($payment->uuid),
                    'ip_address'      => $ipAddress,
                ]);

                $locked->forceFill([$d->paidAtColumn => now()])->save();

                $payment->update([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $account->id,
                    'status'       => $d->paymentClass::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                AuditLog::create([
                    'actor_user_id'  => $user->id,
                    'event'          => $d->event('paid_in_ky'),
                    'auditable_type' => $d->paymentClass,
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
        // spedita anche se la transazione poi rotolasse indietro, e nessuno se
        // la riprende piu'.
        $notifica = $d->paidNotification;
        $user->notify(new $notifica($payment));

        return $payment;
    }

    // ── Annullamento di una quota gia' saldata ──────────────────────────────

    /**
     * Disfa una quota saldata: storna il movimento, rimette la quota fra quelle
     * da pagare e toglie il fido aggiuntivo. Le tre cose insieme.
     *
     * PERCHE' NON SI FA ELIMINANDO IL MOVIMENTO. Cancellarlo da /admin/movimenti
     * ripristina i saldi e basta: la quota resta scritta come pagata e il fido
     * aggiuntivo resta addosso all'utente, che si ritrova dentro il circuito
     * gratis e con dello scoperto in piu'. Per questo i movimenti di quota non
     * sono piu' eliminabili da li' (AdminController::MOVIMENTI_DI_QUOTA) e
     * l'unica strada e' questa.
     *
     * LO STORNO SI FA SOLO SE IL MOVIMENTO C'E' ANCORA. Chi lo ha gia' visto
     * cancellare a mano i KY li ha gia' riavuti: stornare in base allo stato del
     * pagamento («risulta completed, quindi restituisco») glieli regalerebbe una
     * seconda volta. Qui si guarda il MOVIMENTO.
     *
     * In euro le due quote si comportano diversamente, e non serve un ramo: la
     * quota dei privati aveva accreditato KY e quel movimento si storna, quella
     * del codice agente non ne aveva mai emessi e quindi non c'e' niente da
     * stornare — i soldi veri restano incassati e il rimborso e' a mano.
     *
     * @throws RuntimeException
     */
    public function cancel(Model&FeePayment $payment, User $admin, ?string $reason = null, ?string $ipAddress = null): Model&FeePayment
    {
        $d = $this->definition();

        // NB: la guardia "e' saldata?" sta UNA VOLTA SOLA, dentro il lock qui
        // sotto. Una copia qui fuori sarebbe la solita coppia di difese
        // ridondanti che si nascondono a vicenda: spegnendo quella esterna la
        // suite resta verde, e nessun test dice piu' niente su quella vera.
        $extra = $this->extraCancelContext($payment);

        DB::transaction(function () use ($payment, $admin, $reason, $ipAddress, $extra, $d): void {
            $locked = $d->paymentClass::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw new RuntimeException($locked->isCancelled()
                    ? 'Questa quota è già stata annullata.'
                    : 'Si può annullare solo una quota già saldata.');
            }

            // Il movimento originale, se esiste ancora ed e' contabilizzato.
            $originale = $locked->transfer_id !== null
                ? Transfer::whereKey($locked->transfer_id)->where('status', 'booked')->first()
                : null;

            $stornoId = null;

            if ($originale !== null) {
                $superAdminId = User::where('is_super_admin', true)->value('id');
                if ($superAdminId === null) {
                    throw new RuntimeException('Nessun super admin configurato: lo storno non può essere emesso.');
                }

                // Storno per inversione dei conti: funziona identico nei due
                // versi (l'utente aveva pagato il sistema, o il sistema aveva
                // accreditato l'utente) senza doverli distinguere qui.
                $storno = $this->transfers->book([
                    'initiated_by'    => $superAdminId,
                    'from_account_id' => $originale->to_account_id,
                    'to_account_id'   => $originale->from_account_id,
                    'amount'          => (int) $originale->amount,
                    'kind'            => $d->reversalTransferKind,
                    'description'     => $d->reversalTransferDescription,
                    'idempotency_key' => $d->idempotencyKey($locked->uuid, 'storno'),
                    'ip_address'      => $ipAddress,
                ]);

                $stornoId = $storno->id;
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->first();

            if ($user !== null) {
                $user->forceFill([
                    // La quota torna dovuta dell'importo DI QUESTO pagamento,
                    // non di quello di oggi in impostazioni: chi era entrato a
                    // 30 deve 30 anche se nel frattempo la quota e' passata a
                    // 50. `?:` e non `??`: cosi' vale anche per lo zero (quota
                    // sospesa nei privati, esonerata negli agenti), che
                    // altrimenti resterebbe zero e la quota non tornerebbe
                    // dovuta pur essendo stata annullata.
                    $d->dueColumn       => $user->{$d->dueColumn} ?: (int) $locked->amount_eur_cents,
                    $d->paidAtColumn    => null,
                    // Il fido aggiuntivo se ne va con la quota che lo aveva
                    // motivato: era li' solo per reggere lo scoperto.
                    $d->allowanceColumn => 0,
                ])->save();
            }

            $locked->update([
                'status'       => $d->paymentClass::STATUS_CANCELLED,
                'admin_notes'  => $reason ?? 'Quota annullata dal backoffice.',
                'confirmed_by' => $admin->id,
            ]);

            AuditLog::create([
                'actor_user_id'  => $admin->id,
                'event'          => $d->event('cancelled'),
                'auditable_type' => $d->paymentClass,
                'auditable_id'   => $locked->id,
                'ip_address'     => $ipAddress,
                'context'        => array_merge([
                    'uuid'                 => $locked->uuid,
                    'user_id'              => $locked->user_id,
                    // amount_eur_cents e ky_amount sono lo stesso numero: la
                    // quota si paga tutta, in euro o in KY.
                    'amount'               => (int) $locked->amount_eur_cents,
                    'payment_method'       => $locked->payment_method,
                    'original_transfer_id' => $locked->transfer_id,
                    'reversal_transfer_id' => $stornoId,
                    // false = il movimento era gia' stato cancellato a mano e i
                    // KY erano gia' tornati indietro; oppure non ce n'era mai
                    // stato uno (i 480 in euro), e il rimborso resta da fare a
                    // mano.
                    'reversal_booked'      => $stornoId !== null,
                    'reason'               => $reason,
                ], $extra),
            ]);
        });

        $payment->refresh();

        if ($payment->user !== null) {
            $notifica = $d->cancelledNotification;
            $payment->user->notify(new $notifica($payment));

            $this->afterCancelled($payment, $ipAddress);
        }

        return $payment;
    }

    /**
     * Il fido aggiuntivo da concedere a chi paga questa quota in KY, in
     * centesimi. Di regola e' l'intero importo della quota: il conto va sotto
     * di quella cifra e il fido che l'utente aveva resta intero.
     *
     * Chiamato DENTRO la transazione, con la riga utente gia' bloccata, cosi'
     * una sottoclasse puo' leggere impostazioni sue senza correre.
     */
    protected function kyAllowanceFor(User $user, int $amount): int
    {
        return $amount;
    }

    /**
     * Quel che questa quota vuole scritto nell'audit log dell'annullamento, e
     * l'altra no. Calcolato PRIMA della transazione, come lo stato di partenza.
     *
     * @return array<string, mixed>
     */
    protected function extraCancelContext(Model&FeePayment $payment): array
    {
        return [];
    }

    /**
     * Quel che questa quota deve fare DOPO un annullamento andato a buon fine,
     * e l'altra no. Fuori dalla transazione: se lo storno rotola indietro, qui
     * non si arriva.
     */
    protected function afterCancelled(Model&FeePayment $payment, ?string $ipAddress): void
    {
    }

    // ── Pagamento in euro ───────────────────────────────────────────────────

    /**
     * Chiamato quando un pagamento in euro risulta incassato (Stripe, PayPal, o
     * bonifico confermato dall'admin).
     *
     * QUI NON SI DECIDE SE I SOLDI SONO ARRIVATI. Chi chiama deve avere gia' in
     * mano la prova: la sessione Stripe verificata dal server di Stripe,
     * l'ordine PayPal risultato COMPLETED dell'importo esatto, o un admin che ha
     * visto il bonifico sul conto. Vedi StripeCheckoutVerifier e
     * PayPalOrderVerifier.
     *
     * IDEMPOTENTE, e serve davvero: webhook e pagina di esito possono arrivare
     * insieme — e' la corsa che il 31/08 ha fatto assegnare i punti MLM due
     * volte. La guardia sullo stato sotto lock ferma il caso normale; dove si
     * emette moneta, la idempotency_key del transfer e' la seconda difesa.
     *
     * Cosa succede al DENARO lo decide la quota, in settleEuroPayment(): i 30
     * dei privati fanno emettere KY, i 480 del codice agente no.
     */
    public function completeEuroPayment(Model&FeePayment $payment, ?int $confirmedBy = null): void
    {
        $d = $this->definition();

        if ($payment->isCompleted()) {
            return;
        }

        $user = $payment->user;

        $impedimento = $this->euroSettlementBlocker($payment, $user);

        if ($impedimento !== null) {
            Log::error('Quota: pagamento in euro non chiudibile', [
                'quota'   => $d->auditPrefix,
                'payment' => $payment->uuid,
                'motivo'  => $impedimento,
            ]);
            $this->markFailed($payment, $impedimento);

            return;
        }

        $doppia  = false;
        $saldata = false;

        try {
            DB::transaction(function () use ($payment, $user, $confirmedBy, $d, &$doppia, &$saldata): void {
                $locked = $d->paymentClass::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked === null || $locked->isCompleted()) {
                    return;
                }

                // La stessa quota pagata due volte. Succede davvero: ogni click
                // su "paga con carta" apre una riga nuova (scelta voluta —
                // riusare la riga sovrascriverebbe la sessione Stripe e un
                // pagamento su quella vecchia non verrebbe mai accreditato), e
                // le sessioni restano valide. Chi ne apre due e le paga
                // entrambe versa il doppio. Non si rifiuta il secondo incasso —
                // quei soldi sono arrivati davvero — ma non deve sparire in
                // silenzio: questa riga e' l'unica cosa che, fra sei mesi,
                // permettera' di rispondere a "ho pagato due volte".
                $doppia = User::whereKey($user->id)->value($d->paidAtColumn) !== null;

                $transferId = $this->settleEuroPayment($locked, $user);

                $campi = [
                    'status'       => $d->paymentClass::STATUS_COMPLETED,
                    'confirmed_by' => $confirmedBy,
                    'completed_at' => now(),
                ];

                if ($transferId !== null) {
                    $campi['transfer_id'] = $transferId;
                    $campi['account_id']  = $locked->account_id ?? $this->accountFor($user)?->id;
                }

                $locked->update($campi);

                User::whereKey($user->id)
                    ->whereNull($d->paidAtColumn)
                    ->update([$d->paidAtColumn => now()]);

                AuditLog::create([
                    'actor_user_id'  => $confirmedBy ?? $user->id,
                    'event'          => $d->event('paid_in_eur'),
                    'auditable_type' => $d->paymentClass,
                    'auditable_id'   => $locked->id,
                    'context'        => [
                        'uuid'           => $locked->uuid,
                        'amount'         => (int) $locked->amount_eur_cents,
                        'payment_method' => $locked->payment_method,
                        'transfer_id'    => $transferId,
                        // true = la quota risultava gia' saldata da un ALTRO
                        // pagamento: questo e' un secondo incasso per la stessa
                        // quota, non un accredito ripetuto.
                        'quota_gia_saldata' => $doppia,
                    ],
                ]);

                // Solo qui, e non fuori guardando lo stato: la closure puo'
                // essere uscita prima (un'altra richiesta l'aveva gia' chiusa)
                // e in quel caso la ricevuta l'ha gia' mandata quell'altra.
                $saldata = true;
            });
        } catch (\Throwable $e) {
            Log::error('Quota: chiusura del pagamento in euro fallita', [
                'quota'   => $d->auditPrefix,
                'payment' => $payment->uuid,
                'error'   => $e->getMessage(),
            ]);
            $this->markFailed($payment, $e->getMessage());

            return;
        }

        if ($doppia) {
            Log::warning('Quota: incassata due volte dalla stessa persona', [
                'quota'   => $d->auditPrefix,
                'payment' => $payment->uuid,
                'user'    => $user->id,
                'amount'  => (int) $payment->amount_eur_cents,
                'method'  => $payment->payment_method,
            ]);
        }

        $payment->refresh();

        if ($saldata) {
            $notifica = $d->paidNotification;
            $user->notify(new $notifica($payment));
        }
    }

    /**
     * Quel che impedisce di chiudere questo pagamento in euro, o null se si puo'
     * procedere. Controllato PRIMA di aprire la transazione, e il testo finisce
     * in admin_notes: e' quello che l'admin legge in backoffice per capire
     * perche' una riga e' finita `failed`.
     */
    abstract protected function euroSettlementBlocker(Model&FeePayment $payment, ?User $user): ?string;

    /**
     * Cosa succede al denaro quando un pagamento in euro risulta incassato.
     * Chiamato DENTRO la transazione, con la riga gia' bloccata.
     *
     * @return int|null id del movimento creato, null se non se ne crea nessuno
     */
    abstract protected function settleEuroPayment(Model&FeePayment $locked, User $user): ?int;

    // ── Ripescaggio di un incasso in euro ───────────────────────────────────

    /**
     * Riprende un pagamento in euro finito `failed` quando i soldi, in realta',
     * sono stati incassati.
     *
     * IL CASO CHE CHIUDE. completeEuroPayment(), se la scrittura va storta (un
     * deadlock, il conto di sistema irraggiungibile per un istante), chiama
     * markFailed(). Ma a quel punto Stripe o PayPal i soldi li hanno GIA' presi,
     * e da li' in poi non si rimetteva in moto niente da solo: la conferma del
     * bonifico pretende lo stato `pending_bank_transfer`, e «Annulla quota» vale
     * solo sulle righe saldate.
     *
     * PERCHE' NON E' UN BOTTONE CHE CREA MONETA. Questo metodo non decide
     * niente: chi lo chiama deve avere gia' in mano la PROVA dell'incasso. Vedi
     * i due adminRetryCredit(), dove la prova si raccoglie.
     *
     * @throws RuntimeException
     */
    public function retryEuroCredit(Model&FeePayment $payment, User $admin, ?string $ipAddress = null): void
    {
        $d = $this->definition();

        if ($payment->isCompleted()) {
            throw new RuntimeException('Questa quota risulta già saldata.');
        }

        if ($payment->payment_method === $d->paymentClass::METHOD_KY) {
            throw new RuntimeException('Il ripescaggio vale solo per i pagamenti in euro: in KY non c\'è nessun incasso da recuperare.');
        }

        if ($payment->isCancelled()) {
            throw new RuntimeException($d->retryOnCancelledMessage);
        }

        AuditLog::create([
            'actor_user_id'  => $admin->id,
            'event'          => $d->event('credit_retried'),
            'auditable_type' => $d->paymentClass,
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
            throw new RuntimeException($d->retryFailedMessage . ($payment->admin_notes ?: 'motivo non registrato'));
        }
    }
}
