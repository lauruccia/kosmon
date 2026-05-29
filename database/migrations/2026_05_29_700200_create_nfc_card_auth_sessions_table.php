<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_card_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('nonce')->unique();

            $table->foreignId('nfc_card_id')->constrained('nfc_cards')->cascadeOnDelete();
            $table->foreignId('merchant_company_id')->constrained('companies')->cascadeOnDelete();

            $table->unsignedInteger('amount');
            $table->string('description', 200)->nullable();

            $table->enum('status', ['pending', 'authorized', 'expired', 'cancelled', 'failed'])
                  ->default('pending');

            // UUID della transazione completata (dopo auth OK)
            $table->uuid('transfer_uuid')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_card_auth_sessions');
    }
};
