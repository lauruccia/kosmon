<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('mlm_role', ['cliente', 'agente'])->default('cliente')->after('referred_by_user_id');
            $table->enum('mlm_rank', ['start', 'basic', 'key', 'senior', 'top', 'supervisor', 'manager'])
                ->default('start')->after('mlm_role');
            $table->timestamp('mlm_rank_updated_at')->nullable()->after('mlm_rank');
            $table->timestamp('mlm_activated_at')->nullable()->after('mlm_rank_updated_at');
            $table->timestamp('mlm_basiq_at')->nullable()->after('mlm_activated_at');
            $table->boolean('mlm_basiq_bonus_eligible')->default(false)->after('mlm_basiq_at');

            // Per i clienti: agente "risolto" (primo antenato con mlm_role=agente) a cui vengono
            // attribuiti punti/commissioni. Impostato una sola volta alla registrazione.
            $table->unsignedBigInteger('mlm_client_agent_id')->nullable()->after('mlm_basiq_bonus_eligible');
            $table->foreign('mlm_client_agent_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['mlm_role', 'mlm_rank']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['mlm_client_agent_id']);
            $table->dropIndex(['users_mlm_role_mlm_rank_index']);
            $table->dropColumn([
                'mlm_role',
                'mlm_rank',
                'mlm_rank_updated_at',
                'mlm_activated_at',
                'mlm_basiq_at',
                'mlm_basiq_bonus_eligible',
                'mlm_client_agent_id',
            ]);
        });
    }
};
