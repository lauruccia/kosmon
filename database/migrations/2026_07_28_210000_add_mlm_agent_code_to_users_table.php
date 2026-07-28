<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-07-28: "Codice agente" (punto 5, richiesta di Laura) — identificativo
// pubblico dell'agente nel formato K + 5 cifre casuali (es. K04821),
// generato UNA SOLA VOLTA al momento della firma del contratto di nomina
// (vedi MlmAgentContractController::sign() -> User::agentCode()) e mai più
// rigenerato/modificato dopo l'assegnazione (immutabile per decisione di
// Laura). Diverso e indipendente da `referral_code` (che resta invariato
// e condiviso da tutti gli utenti, clienti compresi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('mlm_agent_code', 8)->nullable()->unique()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mlm_agent_code');
        });
    }
};
