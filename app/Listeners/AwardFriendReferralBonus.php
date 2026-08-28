<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ReferralBonusService;
use Illuminate\Auth\Events\Verified;

/**
 * Bonus segnalazione "amico" (10 KY) — erogato quando l'invitato VERIFICA
 * l'indirizzo email, non piu' nell'istante della registrazione.
 *
 * Fino al 28/08/2026 AuthController::register() lo erogava subito: bastava
 * compilare il form con un indirizzo inesistente per far uscire 10 KY dal
 * conto dell'agente di riferimento, ripetutamente e senza che nessuno
 * dovesse dimostrare di possedere una casella. Ora vale la stessa regola
 * degli altri due livelli, che aspettano un evento verificabile:
 * "attivita" l'approvazione KYC, "agente" la firma del contratto di nomina.
 *
 * Nota: EmailChangeController marca `email_verified_at` direttamente senza
 * emettere l'evento Verified, quindi cambiare indirizzo non fa scattare il
 * bonus una seconda volta. Anche se lo facesse, awardTier e' idempotente
 * sulla chiave "referral_bonus_{id}_{tier}".
 */
class AwardFriendReferralBonus
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // Le aziende hanno il loro livello ("attivita"), che scatta
        // all'approvazione KYC in KycController::approve().
        if ($user->account_holder_type !== 'private') {
            return;
        }

        app(ReferralBonusService::class)->awardTier($user, ReferralBonusService::TIER_AMICO);
    }
}
