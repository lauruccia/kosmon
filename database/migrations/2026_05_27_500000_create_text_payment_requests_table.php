<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Creditore (chi richiede il pagamento)
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();

            // Debitore (chi deve pagare)
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();

            $table->unsignedInteger('amount'); // in centesimi KY

            $table->string('causale', 500);
            $table->text('note')->nullable();

            $table->date('due_date')->nullable(); // scadenza opzionale

            // pending | approved | rejected | cancelled | expired
            $table->string('status', 20)->default('pending')->index();

            // Compilato quando approvata e pagata
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();

            // Chi ha creato la richiesta
            $table->foreignId('created_by')->constrained('users');

            // Chi ha approvato/rifiutato
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_payment_requests');
    }
};
