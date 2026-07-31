<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congela, al momento della firma, i dati anagrafici del firmatario e dello
 * sponsor in un JSON strutturato (2026-07-31). Finora `contract_html_snapshot`
 * interpolava questi dati solo come testo dentro l'HTML: utile da leggere,
 * ma non interrogabile. Questa colonna aggiuntiva rende i dati del
 * firmatario (codice fiscale, nascita, residenza, sponsor) query-abili per
 * eventuali verifiche amministrative/legali, senza toccare lo snapshot HTML
 * esistente (che resta la fonte "as displayed" del contratto firmato).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlm_agent_contract_signatures', function (Blueprint $table): void {
            $table->json('signer_data_snapshot')->nullable()->after('contract_html_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('mlm_agent_contract_signatures', function (Blueprint $table): void {
            $table->dropColumn('signer_data_snapshot');
        });
    }
};
