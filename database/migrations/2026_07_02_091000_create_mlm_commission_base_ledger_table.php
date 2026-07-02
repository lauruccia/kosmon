<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger dell'"importo mensile" commissionabile (smoothing), parallelo a
     * mlm_point_ledger ma in EUR: ogni deposito genera una riga con
     * l'importo mensile (deposito / durata scaglione) attivo per lo stesso
     * numero di mesi usato per i punti. Vedi MlmPointsService e
     * MLM_PROPOSAL.md §5.
     */
    public function up(): void
    {
        Schema::create('mlm_commission_base_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('client_user_id');
            $table->unsignedBigInteger('direct_agent_id'); // agente diretto del cliente (per commissioni dirette/indirette)

            $table->unsignedBigInteger('source_transfer_id')->nullable();
            $table->unsignedBigInteger('monthly_amount_eur_cents');
            $table->date('valid_from');
            $table->date('valid_until');

            $table->timestamps();

            $table->foreign('client_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('direct_agent_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['direct_agent_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commission_base_ledger');
    }
};
