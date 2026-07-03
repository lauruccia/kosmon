<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Richiesta di adesione al programma agenti KNM. Stato nullo = mai richiesto.
            $table->string('mlm_agent_request_status', 20)->nullable()->after('mlm_client_agent_id');
            $table->timestamp('mlm_agent_requested_at')->nullable()->after('mlm_agent_request_status');
            $table->text('mlm_agent_request_note')->nullable()->after('mlm_agent_requested_at');
            $table->timestamp('mlm_agent_reviewed_at')->nullable()->after('mlm_agent_request_note');
            $table->unsignedBigInteger('mlm_agent_reviewed_by')->nullable()->after('mlm_agent_reviewed_at');
            $table->foreign('mlm_agent_reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->text('mlm_agent_rejection_reason')->nullable()->after('mlm_agent_reviewed_by');

            // Firma del contratto di nomina ad agente (OTP via email, come il contratto principale).
            $table->timestamp('mlm_agent_contract_signed_at')->nullable()->after('mlm_agent_rejection_reason');
            $table->string('mlm_agent_contract_otp', 6)->nullable()->after('mlm_agent_contract_signed_at');
            $table->timestamp('mlm_agent_contract_otp_expires_at')->nullable()->after('mlm_agent_contract_otp');

            $table->index(['mlm_agent_request_status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['mlm_agent_reviewed_by']);
            $table->dropIndex(['users_mlm_agent_request_status_index']);
            $table->dropColumn([
                'mlm_agent_request_status',
                'mlm_agent_requested_at',
                'mlm_agent_request_note',
                'mlm_agent_reviewed_at',
                'mlm_agent_reviewed_by',
                'mlm_agent_rejection_reason',
                'mlm_agent_contract_signed_at',
                'mlm_agent_contract_otp',
                'mlm_agent_contract_otp_expires_at',
            ]);
        });
    }
};
