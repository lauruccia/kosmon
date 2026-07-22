<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Punti MLM direttamente sulle KY Card (2026-07-22, osservazione di
     * Laura: i tagli di ricarica REALI sono le card di /admin/ky-cards, non
     * un elenco separato). Ogni card dice quanti punti matura l'agente
     * diretto quando un suo cliente la acquista e per quanti giorni restano
     * attivi (1 mese = 30 giorni). Editabile card per card in
     * /admin/ky-cards; 0 punti = la card non genera punti.
     *
     * Backfill per le card gia' esistenti: fasce di prezzo decise da Laura
     * la mattina del 22/07 (>=1.200 EUR -> 2 pt / 360 gg; >=600 EUR ->
     * 2 pt / 180 gg; >=120 EUR -> 2 pt / 30 gg; sotto 120 EUR -> 0 punti),
     * poi regolabili a mano su ciascuna card.
     */
    public function up(): void
    {
        Schema::table('ky_cards', function (Blueprint $table): void {
            $table->decimal('mlm_points', 8, 2)->default(0)->after('bonus_value');
            $table->unsignedInteger('mlm_points_duration_days')->default(0)->after('mlm_points');
        });

        DB::table('ky_cards')->where('price_eur_cents', '>=', 120_000)
            ->update(['mlm_points' => 2, 'mlm_points_duration_days' => 360]);
        DB::table('ky_cards')->where('price_eur_cents', '>=', 60_000)->where('price_eur_cents', '<', 120_000)
            ->update(['mlm_points' => 2, 'mlm_points_duration_days' => 180]);
        DB::table('ky_cards')->where('price_eur_cents', '>=', 12_000)->where('price_eur_cents', '<', 60_000)
            ->update(['mlm_points' => 2, 'mlm_points_duration_days' => 30]);
    }

    public function down(): void
    {
        Schema::table('ky_cards', function (Blueprint $table): void {
            $table->dropColumn(['mlm_points', 'mlm_points_duration_days']);
        });
    }
};
