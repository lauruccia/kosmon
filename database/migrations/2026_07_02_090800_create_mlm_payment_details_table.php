<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dati bancari dell'agente per la liquidazione EUR. Tabella separata da users
     * per contenere il PII sensibile (IBAN) fuori dal modello principale.
     */
    public function up(): void
    {
        Schema::create('mlm_payment_details', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('agent_user_id')->unique();
            $table->string('account_holder_name');
            $table->string('iban');
            $table->string('bic_swift')->nullable();
            $table->string('bank_name')->nullable();

            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('verified_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_payment_details');
    }
};
