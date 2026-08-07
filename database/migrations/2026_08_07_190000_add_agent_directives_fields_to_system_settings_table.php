<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Direttive e Procedure Kosmos" (2026-08-07, richiesta di Laura): secondo
 * documento che l'agente deve accettare oltre al contratto di nomina —
 * stesso schema testo/versione di mlm_agent_contract_text/version, vedi
 * SystemSetting::defaultAgentDirectivesText() e agentContractSettings().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->longText('mlm_agent_directives_text')->nullable()->after('mlm_agent_contract_version');
            $table->unsignedInteger('mlm_agent_directives_version')->default(1)->after('mlm_agent_directives_text');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn(['mlm_agent_directives_text', 'mlm_agent_directives_version']);
        });
    }
};
