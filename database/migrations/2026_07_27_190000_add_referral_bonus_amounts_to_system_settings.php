<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importi bonus KY per segnalazione (punto 3 del 27/07), configurabili da
 * admin come welcome_bonus_amount. Tre livelli dedotti automaticamente da
 * cosa fa il segnalato (mai dichiarati a monte dal segnalante):
 *   - amico: registrazione come privato (erogato subito)
 *   - agente: firma contratto di nomina ad agente KNM
 *   - attivita: KYC azienda approvato
 * Non cumulativi: si eroga solo la differenza fino al livello più alto
 * raggiunto (vedi ReferralBonusService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('referral_bonus_amico_amount')
                ->default(1000) // 10,00 KY
                ->after('welcome_bonus_amount')
                ->comment('Bonus segnalazione "amico" in centesimi KY, erogato al segnalante alla registrazione del segnalato come privato. 0 = disabilitato.');

            $table->unsignedBigInteger('referral_bonus_agente_amount')
                ->default(5000) // 50,00 KY
                ->after('referral_bonus_amico_amount')
                ->comment('Bonus segnalazione "agente" in centesimi KY, erogato al segnalante quando il segnalato firma il contratto di nomina ad agente KNM. 0 = disabilitato.');

            $table->unsignedBigInteger('referral_bonus_attivita_amount')
                ->default(10000) // 100,00 KY
                ->after('referral_bonus_agente_amount')
                ->comment('Bonus segnalazione "attività" in centesimi KY, erogato al segnalante quando l\'azienda del segnalato ottiene il KYC approvato. 0 = disabilitato.');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'referral_bonus_amico_amount',
                'referral_bonus_agente_amount',
                'referral_bonus_attivita_amount',
            ]);
        });
    }
};
