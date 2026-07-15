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
    ];

    private const ORIGINAL_METRICS = ['points', 'level1_basic_count'];

    /**
     * Estende l'enum `mlm_metric_grants.metric` alle 5 metriche "di
     * struttura" (colonne con almeno un agente Key/Senior/Top/SuperVisor,
     * colonne da >= 300 punti), finora escluse dal regalo perche' legate
     * alla downline reale (vedi commento originale nella migration
     * 2026_07_14_090000_create_mlm_metric_grants_table.php).
     *
     * Richiesta di Laura il 2026-07-15: poter far raggiungere in automatico
     * ANCHE le qualifiche superiori (Senior, Top, SuperVisor, Manager), non
     * solo Basic/Key. Restano contatori astratti che si sommano al valore
     * reale calcolato da MlmRankEngine::evaluate() — NON creano agenti o
     * nodi veri nell'albero, quindi non alterano le viste Albero/rami ne'
     * i bonus di struttura gia' generati (quelli restano legati solo alla
     * downline reale, vedi MlmAwardService).
     *
     * MySQL (produzione): la colonna e' un vero ENUM, quindi basta un
     * ALTER TABLE ... MODIFY. sqlite (locale/test, vedi .env): e' un CHECK
     * costraint emulato da $table->enum() e non supporta MODIFY — usiamo
     * l'API Blueprint (che su sqlite ricrea la tabella sotto il cofano)
     * cosi' i test coprono davvero anche le nuove metriche, non solo
     * l'ambiente di produzione.
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
                . implode("','", self::ORIGINAL_METRICS) . "') NOT NULL");

            return;
        }

        Schema::table('mlm_metric_grants', function (Blueprint $table): void {
            $table->enum('metric', self::ORIGINAL_METRICS)->change();
        });
    }
};
