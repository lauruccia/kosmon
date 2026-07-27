<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\ReferralBonusReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bonus KY per segnalazioni (punto 3 del 27/07/2026 — vedi memoria
 * "analisi_4_requisiti_27_07" / "implementazione_punti_1_2_4_27_07").
 * Indipendente da MLM: resta attivo anche con kmoney.mlm_enabled=false,
 * come il resto del sistema referral (referral_code/referred_by_user_id,
 * vedi ReferralController/AuthController).
 *
 * Tre livelli, dedotti SEMPRE automaticamente da cosa fa l'invitato — mai
 * dichiarati a monte dal segnalante, che ha un solo referral_code/link
 * generico condiviso indipendentemente da chi lo riceve:
 *
 *   - TIER_AMICO    : l'invitato si registra come privato (account_holder_type
 *                     = 'private'). Erogato SUBITO alla registrazione stessa,
 *                     perché per i privati non esiste oggi nessun evento
 *                     "contratto completato" (EnsureContractSigned salta chi
 *                     non ha company_id).
 *   - TIER_AGENTE   : l'invitato diventa agente KNM a tutti gli effetti,
 *                     cioè firma il contratto di nomina (mlm_role passa ad
 *                     'agente' solo lì, vedi MlmAgentContractController::sign()).
 *   - TIER_ATTIVITA : l'invitato si registra come azienda E l'azienda ottiene
 *                     il KYC approvato (stesso momento del bonus di benvenuto,
 *                     vedi KycController::approve()).
 *
 * NON cumulativo: se lo stesso invitato attraversa più livelli nel tempo
 * (unico caso possibile oggi: si registra come privato = amico, poi diventa
 * anche agente = agente), il segnalante incassa solo la DIFFERENZA fino al
 * livello più alto raggiunto — mai la somma dei livelli. Lo stato è tracciato
 * su users.referral_bonus_paid_amount/referral_bonus_tier del SEGNALATO (non
 * del segnalante): "quanto ho già fatto guadagnare a chi mi ha segnalato".
 */
class ReferralBonusService
{
    public const TIER_AMICO = 'amico';
    public const TIER_AGENTE = 'agente';
    public const TIER_ATTIVITA = 'attivita';

    public const TIERS = [self::TIER_AMICO, self::TIER_AGENTE, self::TIER_ATTIVITA];

    /** Importi correnti per livello (centesimi KY), da SystemSetting::userLimitDefaults(). */
    public function tierAmounts(): array
    {
        return SystemSetting::userLimitDefaults()->referralBonusAmounts();
    }

    /**
     * Punto di ingresso per i controller: eroga (o completa fino a) il bonus
     * di livello $tier al segnalante di $referredUser. Non bloccante — un
     * errore qui non deve MAI impedire l'operazione che l'ha scatenato
     * (registrazione, approvazione KYC, firma contratto agente), stesso
     * pattern di KycController::maybeErogateWelcomeBonus().
     */
    public function awardTier(User $referredUser, string $tier, ?User $actor = null): void
    {
        try {
            $this->awardTierOrFail($referredUser, $tier, $actor);
        } catch (\Throwable $e) {
            Log::warning("Referral bonus fallito per l'utente segnalato {$referredUser->id} (livello {$tier}): " . $e->getMessage());
        }
    }

    /**
     * Stessa logica di awardTier() ma propaga le eccezioni — utile nei test
     * per far fallire rumorosamente un errore inatteso invece di inghiottirlo.
     */
    public function awardTierOrFail(User $referredUser, string $tier, ?User $actor = null): void
    {
        if (! in_array($tier, self::TIERS, true)) {
            return;
        }

        $targetAmount = $this->tierAmounts()[$tier] ?? 0;
        if ($targetAmount <= 0) {
            return; // livello disabilitato dall'admin
        }

        DB::transaction(function () use ($referredUser, $tier, $targetAmount, $actor) {
            // Lock sulla riga dell'utente SEGNALATO: serializza eventuali
            // trigger concorrenti per lo stesso invitato (es. doppio click,
            // job rilanciato) senza toccare i lock sui conti, che restano
            // interamente a carico di TransferBookingService.
            $locked = User::lockForUpdate()->findOrFail($referredUser->id);

            $referrer = $locked->referredBy;
            if (! $referrer) {
                return; // nessuna segnalazione dietro questo utente
            }

            $alreadyPaid = (int) ($locked->referral_bonus_paid_amount ?? 0);
            $delta = $targetAmount - $alreadyPaid;

            if ($delta <= 0) {
                return; // livello già raggiunto/superato in precedenza, nulla da erogare
            }

            $idempotencyKey = "referral_bonus_{$locked->id}_{$tier}";
            $alreadyBooked = Transfer::where('idempotency_key', $idempotencyKey)->exists();

            if (! $alreadyBooked) {
                $referrerAccount = Account::where('owner_user_id', $referrer->id)
                    ->whereNull('parent_account_id')
                    ->where('status', 'active')
                    ->first();

                $systemAccount = Account::systemAccount();

                if (! $referrerAccount || ! $systemAccount) {
                    return;
                }

                // Initiator: l'admin che ha scatenato l'evento se presente E
                // autorizzato (es. approvazione KYC), altrimenti un super
                // admin qualsiasi. Il conto sistema (Cassa Circuito) ha
                // owner_user_id/company_id sempre NULL (vedi migration
                // seed_cassa_circuito_account) e User::canSendFromAccount()
                // non prevede alcuna eccezione per is_system_account: SOLO
                // is_super_admin bypassa il controllo di autorizzazione in
                // TransferBookingService::assertAuthorizedInitiator() —
                // stesso vincolo di Admin\EmissionController (emissione KY
                // riservata al super admin). Il segnalante/segnalato NON
                // possono mai essere usati come initiator qui.
                $systemUser = ($actor && $actor->is_super_admin)
                    ? $actor
                    : User::where('is_super_admin', true)->where('is_active', true)->first();

                if (! $systemUser) {
                    Log::warning('ReferralBonusService: nessun super admin trovato per erogare il bonus dal conto sistema.');
                    return;
                }

                $booking = app(TransferBookingService::class);
                $transfer = $booking->book([
                    'initiated_by'    => $systemUser->id,
                    'from_account_id' => $systemAccount->id,
                    'to_account_id'   => $referrerAccount->id,
                    'amount'          => $delta,
                    'description'     => $this->descriptionFor($tier, $locked),
                    'kind'            => 'portal_cashback', // esente da fee, come il bonus di benvenuto
                    'idempotency_key' => $idempotencyKey,
                ]);

                AuditLog::create([
                    'actor_user_id'  => $actor?->id,
                    'event'          => 'referral_bonus.credited',
                    'auditable_type' => User::class,
                    'auditable_id'   => $referrer->id,
                    'context'        => [
                        'referred_user_id'  => $locked->id,
                        'tier'              => $tier,
                        'amount'            => $delta,
                        'cumulative_amount' => $targetAmount,
                        'account_id'        => $referrerAccount->id,
                    ],
                ]);

                try {
                    $referrer->notify(new ReferralBonusReceivedNotification($transfer, $locked, $tier, $delta));
                } catch (\Throwable $e) {
                    // La notifica non deve mai far fallire l'erogazione già avvenuta.
                    Log::warning("Notifica bonus segnalazione fallita per referrer {$referrer->id}: " . $e->getMessage());
                }
            }

            // Allinea sempre lo stato locale al target, sia nel percorso
            // fresco sia in un eventuale replay (transfer già booked ma stato
            // locale non ancora aggiornato, es. crash a metà chiamata precedente).
            $locked->forceFill([
                'referral_bonus_paid_amount' => $targetAmount,
                'referral_bonus_tier'        => $tier,
            ])->save();
        });
    }

    private function descriptionFor(string $tier, User $referredUser): string
    {
        return match ($tier) {
            self::TIER_AMICO    => "Bonus segnalazione amico: {$referredUser->name}",
            self::TIER_AGENTE   => "Bonus segnalazione agente: {$referredUser->name}",
            self::TIER_ATTIVITA => "Bonus segnalazione attività: {$referredUser->name}",
            default             => 'Bonus segnalazione',
        };
    }
}
