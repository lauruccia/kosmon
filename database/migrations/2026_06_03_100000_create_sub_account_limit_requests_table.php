<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_account_limit_requests', function (Blueprint $table) {
            $table->id();

            // Il sottoconto che fa la richiesta
            $table->foreignId('sub_account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // L'utente gestore che ha inviato la richiesta
            $table->foreignId('requested_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Chi ha deciso (approvato/rifiutato)
            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Tipo di richiesta
            $table->enum('type', [
                'spending_limit_increase',   // aumento limite per singolo pagamento
                'daily_limit_increase',      // aumento limite giornaliero
                'monthly_limit_increase',    // aumento limite mensile
                'temporary_overdraft',       // sforamento una-tantum per una spesa imprevista
            ]);

            // Importo richiesto (in centesimi di KY)
            // Per gli increase: nuovo valore limite proposto
            // Per overdraft: importo della spesa specifica
            $table->bigInteger('requested_amount');

            // Motivazione fornita dal gestore
            $table->text('reason');

            // Stato della richiesta
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // Nota opzionale del titolare alla decisione
            $table->text('decision_note')->nullable();

            // Per gli overdraft approvati: scadenza entro cui può essere usato
            $table->timestamp('overdraft_expires_at')->nullable();

            // Se l'overdraft è già stato consumato (pagamento effettuato)
            $table->boolean('overdraft_used')->default(false);

            // Transfer che ha consumato l'overdraft (se applicabile)
            $table->foreignId('overdraft_transfer_id')
                ->nullable()
                ->constrained('transfers')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_account_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_account_limit_requests');
    }
};
