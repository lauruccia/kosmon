<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Chi possiede la card (cliente)
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Chi l'ha emessa (admin user)
            $table->foreignId('issued_by')->constrained('users');

            // Numero seriale fisico opzionale
            $table->string('serial_number', 50)->nullable();

            // Ciclo di vita
            $table->enum('status', ['pending', 'issued', 'delivered', 'active', 'blocked', 'revoked'])
                  ->default('pending');

            // Autenticazione PIN (bcrypt)
            $table->string('pin_hash')->nullable();
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // Limiti cliente (null = nessun limite)
            $table->unsignedInteger('limit_per_transaction')->nullable()->comment('Max KY per singola transazione');
            $table->unsignedInteger('limit_daily')->nullable()->comment('Max KY al giorno');
            $table->unsignedInteger('limit_monthly')->nullable()->comment('Max KY al mese');

            // Accumulatori (reset via job)
            $table->unsignedInteger('daily_spent')->default(0);
            $table->unsignedInteger('monthly_spent')->default(0);
            $table->date('daily_reset_date')->nullable()->comment('Data dell\'ultimo reset giornaliero');
            $table->string('monthly_reset_month', 7)->nullable()->comment('Mese dell\'ultimo reset (YYYY-MM)');

            // Dati scritti sul chip NFC (URL completo con firma)
            $table->text('nfc_payload')->nullable();

            // Note admin
            $table->text('notes')->nullable();

            // Timestamp eventi
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_cards');
    }
};
