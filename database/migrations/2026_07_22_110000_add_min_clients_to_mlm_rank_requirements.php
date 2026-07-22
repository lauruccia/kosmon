<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nuovo requisito di qualifica "clienti registrati" (2026-07-22,
     * richiesta di Laura): numero minimo di clienti DIRETTI con conto
     * registrato (anche senza ricariche) perche' l'agente possa tenere il
     * grado. Valori decisi da Laura:
     *
     *   Basic  = minimo 6 clienti
     *   Key    = minimo 12 clienti
     *   Senior = minimo 24 clienti
     *   Top / SuperVisor / Manager = sempre almeno 24 clienti
     *
     * Come per gli altri requisiti vale la retrocessione standard: chi non
     * ha il minimo di clienti scende di grado al primo ricalcolo (confermato
     * da Laura il 22/07). Editabile da admin in /admin/mlm-impostazioni.
     */
    public function up(): void
    {
        Schema::table('mlm_rank_requirements', function (Blueprint $table): void {
            $table->unsignedInteger('min_clients')->default(0)->after('min_points');
        });

        foreach (['basic' => 6, 'key' => 12, 'senior' => 24, 'top' => 24, 'supervisor' => 24, 'manager' => 24] as $rank => $minClients) {
            DB::table('mlm_rank_requirements')->where('rank', $rank)->update(['min_clients' => $minClients]);
        }
    }

    public function down(): void
    {
        Schema::table('mlm_rank_requirements', function (Blueprint $table): void {
            $table->dropColumn('min_clients');
        });
    }
};
