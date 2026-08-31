<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashbackRule;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\CashbackReceivedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Cashback — SCONTO DIFFERITO A CARICO DEL VENDITORE.
 *
 * DECISIONE DI LAURA (31/08/2026), che cambia chi paga:
 * il cashback non esce piu' dal conto di sistema ma dal conto di CHI HA
 * INCASSATO. Non e' piu' moneta nuova che il circuito conia: e' uno sconto che
 * il venditore riconosce al cliente dopo la vendita.
 *
 * Perche' era fermo (report del 14/08, riverificato oggi): il servizio
 * pretendeva `Account::systemAccount()` con un intestatario e con saldo
 * POSITIVO. In produzione quel conto e' quello di Knm srl (la "Cassa Circuito"
 * prevista dal codice non esiste, la sua migrazione non e' mai stata
 * applicata), e per progetto la Cassa e' negativa: le due condizioni non
 * potevano essere vere insieme, quindi il cashback non ha MAI erogato niente.
 *
 * Con il venditore come pagante il problema sparisce da solo — niente conto di
 * sistema, niente vincolo sul suo saldo — e soprattutto non aumenta il
 * circolante, che oggi e' il numero piu' delicato del circuito: in mano ai
 * membri ci sono KY che nessun euro copre.
 *
 * Il vincolo "saldo di sistema positivo" era pero' anche l'unica cosa che
 * impediva di coniare KY senza limite. Non e' stato rimosso: e' stato
 * SOSTITUITO dalla capienza del venditore entro il suo fido, la stessa regola
 * gia' applicata in ReferralBonusService (bonus amico) e in OrderService
 * (refundMerchant). Un tetto esplicito al posto di un tetto implicito.
 */
class CashbackService
{
    public function __construct(
        private readonly TransferBookingService $booking,
    ) {}

    /**
     * Valuta e applica il cashback per un transfer appena completato.
     * Chiamato dopo ogni booking andato a buon fine.
     * Se nessuna regola si applica, non fa nulla.
     */
    public function applyIfEligible(Transfer $transfer): void
    {
        // Solo trasferimenti booked
        if ($transfer->status !== 'booked') {
            return;
        }

        // Non applicare cashback su trasferimenti già cashback (evita loop):
        // il book() qui sotto richiama a sua volta applyIfEligible().
        if ($transfer->kind === 'portal_cashback') {
            return;
        }

        // Il cashback va al pagante (from_account) — necessario per il targeting
        $beneficiary = Account::find($transfer->from_account_id);
        if (! $beneficiary) {
            return;
        }

        // Chi paga il cashback: il conto che ha INCASSATO questo pagamento.
        $merchant = $transfer->to_account_id ? Account::find($transfer->to_account_id) : null;
        if (! $merchant) {
            return;
        }

        if ($merchant->id === $beneficiary->id) {
            return;
        }

        // Il conto di sistema non e' un venditore: un pagamento verso di lui e'
        // una commissione o un canone, non una vendita. Guardia esplicita
        // perche' addebitarlo qui vorrebbe dire tornare a coniare moneta.
        if ($merchant->is_system_account) {
            return;
        }

        // "A carico di chi ha venduto" presuppone un venditore. Fra privati un
        // pagamento e' un trasferimento, non una vendita: nessuno deve
        // ritrovarsi addebitato uno sconto commerciale che non ha concesso.
        if ($merchant->owner_type !== 'company') {
            return;
        }

        // Carica relazioni utili al targeting
        $beneficiary->loadMissing('ownerUser');

        $rules = CashbackRule::where('is_active', true)->get();

        $bestCashback = 0;
        foreach ($rules as $rule) {
            // Verifica targeting (azienda / privato / utente specifico)
            if (! $rule->appliesTo($beneficiary)) {
                continue;
            }
            $amount = $rule->calculateCashback($transfer->amount, $transfer->kind ?? '');
            $bestCashback = max($bestCashback, $amount);
        }

        if ($bestCashback <= 0) {
            return;
        }

        // Il cashback non puo' valere piu' del pagamento su cui matura: uno
        // sconto piu' grande della vendita non e' uno sconto.
        if ($bestCashback > (int) $transfer->amount) {
            $bestCashback = (int) $transfer->amount;
        }

        // Capienza del venditore ENTRO IL FIDO — sostituisce il vecchio
        // controllo sul saldo del conto di sistema. saldoDisponibile() =
        // saldo + massimale, e massimale() tiene insieme le due fonti di
        // scoperto (credit_limits e users.negative_balance_limit).
        $merchantFresh = Account::find($merchant->id);
        if (! $merchantFresh || $merchantFresh->saldoDisponibile() < $bestCashback) {
            Log::warning('CashbackService: cashback non erogato, il venditore non ha capienza entro il fido.', [
                'transfer_id'          => $transfer->id,
                'merchant_account_id'  => $merchant->id,
                'richiesto'            => $bestCashback,
                'disponibile_con_fido' => $merchantFresh?->saldoDisponibile(),
            ]);
            return;
        }

        try {
            // Initiator: serve SEMPRE un super admin. Il venditore non ha dato
            // il consenso al singolo addebito e User::canSendFromAccount() non
            // lo autorizzerebbe; solo is_super_admin bypassa
            // assertAuthorizedInitiator() nel motore. Stessa forma gia' usata
            // in ReferralBonusService. Il bypass del fido che ne consegue non
            // e' scoperto illimitato: la capienza e' verificata qui sopra.
            $systemUser = User::where('is_super_admin', true)->where('is_active', true)->first();

            if (! $systemUser) {
                Log::warning('CashbackService: nessun super admin attivo, cashback non erogato.');
                return;
            }

            $riferimento = strtoupper(substr($transfer->uuid ?? (string) $transfer->id, 0, 8));

            $cashbackTransfer = $this->booking->book([
                'initiated_by'    => $systemUser->id,
                'from_account_id' => $merchantFresh->id,
                'to_account_id'   => $beneficiary->id,
                'amount'          => $bestCashback,
                'description'     => 'Cashback del venditore su pagamento #' . $riferimento,
                'kind'            => 'portal_cashback',
                'idempotency_key' => 'cashback_' . $transfer->uuid,
            ]);

            AuditLog::create([
                'actor_user_id'  => $systemUser->id,
                'event'          => 'cashback.paid',
                'auditable_type' => Transfer::class,
                'auditable_id'   => $transfer->id,
                'context'        => [
                    'amount'                 => $bestCashback,
                    'merchant_account_id'    => $merchantFresh->id,
                    'beneficiary_account_id' => $beneficiary->id,
                    'cashback_transfer_uuid' => $cashbackTransfer->uuid ?? null,
                ],
            ]);

            // Notifica al beneficiario
            $owner = $beneficiary->ownerUser;
            if ($owner) {
                $owner->notify(new CashbackReceivedNotification($cashbackTransfer, $bestCashback));
            }
        } catch (\Throwable $e) {
            // Il cashback fallisce silenziosamente — non blocca il pagamento principale
            Log::error('CashbackService: errore erogazione cashback', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
