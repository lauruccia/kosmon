<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\RegistrationFeePayment;
use App\Models\SystemSetting;
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
