<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stato di erogazione del bonus segnalazione (punto 3 del 27/07), tenuto
 * sull'utente SEGNALATO (non sul segnalante): quanto e' gia' stato pagato
 * complessivamente al SUO segnalante e per quale livello, cosi' se il
 * segnalato attraversa piu' livelli nel tempo (es. si registra come privato
 * = "amico" e in seguito diventa anche agente = "agente") si eroga solo la
 * DIFFERENZA fino al livello piu' alto raggiunto, mai il totale sommato.
 * Vedi ReferralBonusService::awardTierOrFail().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('referral_bonus_paid_amount')
                ->default(0)
                ->after('referred_by_user_id')
                ->comment('Centesimi KY gia\' erogati al referrer di questo utente per la sua segnalazione (cumulativo, non per-livello).');

            $table->string('referral_bonus_tier', 20)->nullable()
                ->after('referral_bonus_paid_amount')
                ->comment('Livello piu\' alto raggiunto finora: amico|agente|attivita.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['referral_bonus_paid_amount', 'referral_bonus_tier']);
        });
    }
};
