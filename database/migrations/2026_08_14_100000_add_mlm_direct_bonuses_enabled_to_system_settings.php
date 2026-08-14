<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interruttore globale dei BONUS DIRETTI KNM (2026-08-14, richiesta di
     * Laura: "i bonus diretti sono da disattivare").
     *
     * system_settings.mlm_direct_bonuses_enabled: 1 = i premi una tantum
     * sulle soglie di punti attivi 4/6/12 (200/300/400 EUR,
     * MlmAwardService::DIRECT_BONUS_TIERS_EUR_CENTS) vengono erogati come
     * prima; 0/NULL = non ne vengono piu' creati
     * (MlmAwardService::grantDirectPointBonuses() diventa un no-op).
     *
     * Default 0 = SPENTI, sia sulle righe nuove sia su quelle gia' esistenti
     * (che dopo l'ALTER restano NULL => false, vedi
     * SystemSetting::mlmDirectBonusesEnabled()): disattivarli e' il motivo
     * per cui la colonna esiste.
     *
     * NON riguarda i bonus di struttura (cascata BasiQ, MlmBonusService), gli
     * Extra Bonus di promozione grado (grantRankAward) ne' i compensi
     * diretti/indiretti (MlmCommissionEngine): quelli restano invariati.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('mlm_direct_bonuses_enabled')->default(false)->after('mlm_payout_threshold_eur_cents');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('mlm_direct_bonuses_enabled');
        });
    }
};
