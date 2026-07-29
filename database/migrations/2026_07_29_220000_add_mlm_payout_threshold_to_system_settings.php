<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soglia minima di prelievo per il portale agente (2026-07-29, richiesta
     * di Laura: "l'agente puo' richiedere di essere pagato ... al
     * raggiungimento di una soglia decisa da admin").
     *
     * system_settings.mlm_payout_threshold_eur_cents: importo minimo (EUR
     * centesimi) di maturato non ancora liquidato che l'agente deve avere
     * accumulato prima di poter usare il prelievo self-service dal portale
     * (MlmPayoutService::requestWithdrawal()). NULL/0 = nessuna soglia
     * (comportamento pre-esistente: qualunque importo > 0 e' prelevabile).
     * Non riguarda le liquidazioni generate manualmente dall'admin
     * (/admin/mlm-payouts, MlmPayoutController::generate()), che restano
     * senza soglia.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedInteger('mlm_payout_threshold_eur_cents')->nullable()->after('mlm_knm_margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('mlm_payout_threshold_eur_cents');
        });
    }
};
