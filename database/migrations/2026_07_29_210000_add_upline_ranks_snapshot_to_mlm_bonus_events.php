<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scatto ("snapshot") della qualifica di ciascun upline al MOMENTO del
     * rilevamento BasiQ (job notturno), separato da `upline_chain_snapshot`
     * (che invece viene scritto SOLO a fine elaborazione, il mercoledi', per
     * audit del payout gia' calcolato). Decisione di Laura del 29/07/2026:
     * la cascata deve usare "le qualifiche del momento" in cui BasiQ e'
     * scattato, non quelle correnti al momento in cui il job settimanale
     * elabora materialmente l'evento (che puo' cadere giorni dopo e trovare
     * un upline gia' promosso o retrocesso nel frattempo). Nullable per
     * compatibilita' con eventi 'pending' gia' esistenti in produzione
     * prima di questa migration: MlmBonusService::processEvent() ricade sul
     * rank corrente se questa colonna e' vuota.
     */
    public function up(): void
    {
        Schema::table('mlm_bonus_events', function (Blueprint $table): void {
            $table->json('upline_ranks_at_trigger')->nullable()->after('upline_chain_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('mlm_bonus_events', function (Blueprint $table): void {
            $table->dropColumn('upline_ranks_at_trigger');
        });
    }
};
