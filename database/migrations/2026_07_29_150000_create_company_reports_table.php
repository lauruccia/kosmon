<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segnalazione azienda (feature richiesta da Laura il 29/07/2026): un
 * cliente qualsiasi puo' segnalare un'azienda dove vorrebbe spendere il
 * proprio saldo KY. La segnalazione arriva al suo agente di riferimento
 * (o alla radice di sistema se il cliente non ne ha uno, vedi
 * MlmTreeService::systemRootAgent()) e in copia/visibilita' a tutti gli
 * admin. Se l'agente firma un contratto con l'azienda segnalata, il
 * sistema eroga automaticamente un bonus KY al segnalante (vedi
 * CompanyReportService::markContractSigned()) — nessuna approvazione
 * admin: l'admin resta solo in copia/visibilita'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name');
            $table->string('company_city')->nullable();
            $table->text('company_notes')->nullable();
            $table->string('status')->default('pending'); // pending | contract_signed | rejected
            $table->text('agent_notes')->nullable();
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->foreignId('bonus_transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_user_id', 'status']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_reports');
    }
};
