<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override "da test" della durata di validita' dei punti cliente (PC),
     * in minuti. Quando valorizzato, MlmPointsService lo usa al posto della
     * durata normale di business (1/12/24/36 mesi a seconda dello scaglione
     * di deposito) per TUTTI i nuovi punti assegnati — cosi' si puo' impostare
     * es. 60 (1 ora) per verificare subito il ricalcolo qualifiche invece di
     * aspettare mesi. NULL = comportamento normale di produzione.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedInteger('mlm_points_validity_override_minutes')->nullable()->after('welcome_bonus_amount');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('mlm_points_validity_override_minutes');
        });
    }
};
