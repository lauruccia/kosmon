<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La firma OTP del contratto agente (2026-08-07) copre anche l'accettazione
 * delle "Direttive e Procedure Kosmos" — congeliamo qui uno snapshot
 * speculare a contract_html_snapshot/contract_version, così da avere prova
 * di cosa esattamente è stato accettato anche per questo secondo documento.
 * Nullable: le firme precedenti a questa data non hanno questo snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlm_agent_contract_signatures', function (Blueprint $table): void {
            $table->unsignedInteger('directives_version')->nullable()->after('signer_data_snapshot');
            $table->longText('directives_html_snapshot')->nullable()->after('directives_version');
        });
    }

    public function down(): void
    {
        Schema::table('mlm_agent_contract_signatures', function (Blueprint $table): void {
            $table->dropColumn(['directives_version', 'directives_html_snapshot']);
        });
    }
};
