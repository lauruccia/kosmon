<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 64)->unique()->index();

            // Chi incassa
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();

            // Importo e descrizione
            $table->unsignedInteger('amount');
            $table->string('description', 255)->nullable();

            // Stato: pending, paid, expired, cancelled
            $table->string('status', 20)->default('pending')->index();

            // Scadenza (default 5 minuti dalla creazione)
            $table->timestamp('expires_at');

            // Compilato quando pagato
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();

            // Chi ha pagato (account del pagatore)
            $table->foreignId('from_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
