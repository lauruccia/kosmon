<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netting_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Chi propone e chi riceve la proposta
            $table->foreignId('proposer_account_id')
                  ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('counterparty_account_id')
                  ->constrained('accounts')->restrictOnDelete();

            // Trasferimenti in sospeso selezionati da ciascuna parte
            // Array di transfer IDs che il proposer vuole compensare (crediti del proposer verso counterparty)
            $table->json('proposer_transfer_ids');
            // Array di transfer IDs dalla parte del counterparty (crediti del counterparty verso proposer)
            $table->json('counterparty_transfer_ids');

            // Importi lato
            $table->unsignedBigInteger('proposer_total');      // totale crediti proposer
            $table->unsignedBigInteger('counterparty_total');  // totale crediti counterparty
            $table->string('currency_code', 10)->default('KY');

            // Chi paga la differenza netta (null se pari)
            $table->foreignId('net_payer_account_id')
                  ->nullable()
                  ->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('net_amount')->default(0);

            // Descrizione / causale
            $table->string('description', 500)->nullable();

            // Stato proposta
            $table->enum('status', ['pending', 'accepted', 'rejected', 'expired'])
                  ->default('pending');

            // Trasferimento netto generato sull'accettazione (null se net_amount = 0)
            $table->foreignId('net_transfer_id')
                  ->nullable()
                  ->constrained('transfers')->restrictOnDelete();

            // Chi ha gestito (accettato/rifiutato) la proposta
            $table->foreignId('actioned_by')
                  ->nullable()
                  ->constrained('users')->restrictOnDelete();
            $table->timestamp('actioned_at')->nullable();

            $table->foreignId('proposed_by')
                  ->constrained('users')->restrictOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indici
            $table->index(['counterparty_account_id', 'status']);
            $table->index(['proposer_account_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netting_proposals');
    }
};
