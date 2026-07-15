<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Radice unica dell'albero MLM (2026-07-15): l'admin sceglie UN agente
     * esistente come unica radice del sistema. Quando impostata, MlmTreeService
     * la usa per: (1) agganciare automaticamente qualsiasi nuovo agente senza
     * sponsor valido, invece di lasciarlo creare un albero indipendente; (2)
     * impedire che un agente diverso dalla radice venga spostato "senza
     * sponsor" (vedi MlmTreeService::moveAgent). Vedi anche
     * MlmTreeService::setSystemRootAgent() per il consolidamento di eventuali
     * alberi indipendenti preesistenti.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->foreignId('mlm_root_agent_id')->nullable()->after('mlm_points_validity_override_minutes')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mlm_root_agent_id');
        });
    }
};
