<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->longText('mlm_agent_contract_text')->nullable();
            $table->unsignedInteger('mlm_agent_contract_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn(['mlm_agent_contract_text', 'mlm_agent_contract_version']);
        });
    }
};
