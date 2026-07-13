<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estende mlm_bonus_payouts per ospitare, oltre ai bonus di struttura
     * (cascata BasiQ), anche i premi una tantum introdotti il 2026-07-13:
     *
     *  - Bonus Diretti KNM (kind='diretto'): 200/300/400 EUR al raggiungimento
     *    di 4/6/12 punti personali ATTIVI, una volta per soglia per agente.
     *  - Extra Bonus KNM (kind='extra'): premio alla prima promozione a
     *    senior/top/supervisor/manager (300/3.000/5.000/20.000 EUR).
     *
     * Le nuove righe non nascono da un evento BasiQ, quindi mlm_bonus_event_id
     * diventa nullable; rank_at_time diventa stringa nullable (per i bonus
     * diretti non c'e' una qualifica associata). L'unicita' "una volta sola"
     * e' garantita dall'idempotency_key gia' UNIQUE.
     */
    public function up(): void
    {
        Schema::table('mlm_bonus_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('mlm_bonus_event_id')->nullable()->change();
            $table->string('rank_at_time', 20)->nullable()->change();
            $table->string('kind', 20)->default('struttura')->after('rank_at_time');
            $table->index(['kind', 'beneficiary_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mlm_bonus_payouts', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'beneficiary_user_id']);
            $table->dropColumn('kind');
        });
    }
};
