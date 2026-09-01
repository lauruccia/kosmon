<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\RegistrationFeePayment;
use App\Notifications\RegistrationFeeCancelledNotification;
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
 * hanno tutti quelli gia' iscritti.
 */
class RegistrationFeeService
{
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

    // ── Stato ───────────────────────────────────────────────────────────────

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

        return $payment->refresh();
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

        try {
            DB::transaction(function () use ($payment, $user, $account, $systemAccount, $superAdminId, $confirmedBy): void {
                $locked = RegistrationFeePayment::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked === null || $locked->isCompleted()) {
                    return;
                }

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
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Quota iscrizione: accredito KY fallito', [
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
                    'registration_fee_due_cents'          => $user->registration_fee_due_cents ?? (int) $locked->amount_eur_cents,
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
            if ($locked->registration_fee_due_cents !== null && $locked->registration_fee_paid_at === null) {
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
