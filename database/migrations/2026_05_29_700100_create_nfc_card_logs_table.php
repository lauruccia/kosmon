<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_card_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nfc_card_id')->constrained('nfc_cards')->cascadeOnDelete();

            $table->enum('event', [
                'tap',           // card avvicinata al merchant
                'auth_ok',       // PIN corretto
                'auth_fail',     // PIN errato
                'pin_locked',    // card bloccata per troppi tentativi PIN
                'blocked',       // tap su card bloccata
                'revoked',       // tap su card revocata
                'limit_exceeded',// limite superato
                'payment_ok',    // pagamento eseguito
                'payment_fail',  // pagamento fallito (es. saldo insufficiente)
            ]);

            // Chi ha incassato (merchant)
            $table->foreignId('merchant_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->unsignedInteger('amount')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_card_logs');
    }
};
