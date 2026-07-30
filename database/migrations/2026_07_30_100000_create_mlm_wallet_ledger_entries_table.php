<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Cassetto kmoney" (2026-07-30, richiesta di Laura — vedi
     * MLM_PROPOSAL.md §10, che questa modifica sostituisce parzialmente):
     * invece di restare solo in un ledger EUR "esterno" al circuito KY fino
     * alla liquidazione via bonifico, compensi diretti/indiretti/estesi e
     * bonus vengono ora accreditati SUBITO in KY sul conto dell'agente
     * (spendibili da subito come qualunque altro KY), ma restano tracciati
     * qui come "ancora prelevabili/convertibili in € finche' non spesi" —
     * a differenza del resto del saldo KY, che non e' mai convertibile.
     *
     * Ledger append-only (stesso pattern di mlm_point_ledger/LedgerEntry):
     * ogni riga e' o un accredito (compenso maturato, amount_cents > 0) o un
     * addebito (riserva/rilascio per un prelievo, amount_cents < 0). Il
     * saldo "prelevabile" di un agente = somma delle righe, sempre limitato
     * dal saldo KY realmente disponibile sul conto (App\Services\MlmWalletService::withdrawableBalance())
     * per riflettere quanto e' stato eventualmente gia' speso in negozio.
     *
     * 'category' alimenta i 4 contatori informativi richiesti da Laura
     * (diretti/indiretti/estesi/bonus) — null per le righe di
     * riserva/rilascio prelievo, che non sono un "guadagno" ma un movimento
     * interno di prelievo.
     */
    public function up(): void
    {
        Schema::create('mlm_wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['agent_user_id', 'category']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_wallet_ledger_entries');
    }
};
