<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Percentuale Kmoney accettata, dichiarata dall'azienda (2026-07-17).
     *
     * Ogni azienda dichiara nel proprio profilo la percentuale del prezzo
     * che accetta in Kmoney (0/25/50/75/100). La percentuale e' mostrata
     * come badge sulla card della directory /aziende; sulla card viene
     * scelta in automatico la % migliore tra quella dichiarata e la
     * migliore % (25-100) dei prodotti attivi caricati nello shop.
     *
     * NULL = mai dichiarata (nessun badge, salvo prodotti attivi).
     * Se il conto e' sottozero la % non e' modificabile e vale sempre 100
     * (stessa regola circuito gia' applicata ai prodotti tramite
     * Account::allowedKyPercentages()).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('accepted_ky_percentage')->nullable()->after('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('accepted_ky_percentage');
        });
    }
};
