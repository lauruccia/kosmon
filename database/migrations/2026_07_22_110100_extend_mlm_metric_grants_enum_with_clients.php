<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALL_METRICS = [
        'points',
        'level1_basic_count',
        'branches_with_key',
        'branches_with_senior',
        'branches_with_top',
        'branches_with_supervisor',
        'branches_300pt',
        'clients_count',
    ];

    private const PREVIOUS_METRICS = [
        'points',
        'level1_basic_count',
        'branches_with_key',
        'branches_with_senior',
        'branches_with_top',
        'branches_with_supervisor',
        'branches_300pt',
    ];

    /**
     * Estende l'enum `mlm_metric_grants.metric` con 'clients_count':
     * dal 2026-07-22 il numero di clienti registrati e' un requisito di
     * qualifica (vedi migration 2026_07_22_110000) e Laura ha scelto di
     * renderlo regalabile come le altre metriche omaggio. Stesso pattern
     * driver-specifico della migration 2026_07_15_120000 (MySQL = vero ENUM
     * con MODIFY, sqlite = CHECK ricreato via Blueprint).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE mlm_metric_grants MODIFY metric ENUM('"
                . implode("','", self::ALL_METRICS) . "') NOT NULL");

            return;
        }

        Schema::table('mlm_metric_grants', function (Blueprint $table): void {
            $table->enum('metric', self::ALL_METRICS)->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE mlm_metric_grants MODIFY metric ENUM('"
                . implode("','", self::PREVIOUS_METRICS) . "') NOT NULL");

            return;
        }

        Schema::table('mlm_metric_grants', function (Blueprint $table): void {
            $table->enum('metric', self::PREVIOUS_METRICS)->change();
        });
    }
};
