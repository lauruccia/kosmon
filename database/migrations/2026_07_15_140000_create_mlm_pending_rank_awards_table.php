<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coda delle promozioni di grado in attesa dell'Extra Bonus (vedi
 * app/Models/MlmPendingRankAward.php e app/Services/MlmAwardService.php).
 * Introdotta il 2026-07-15 per separare il rilevamento della promozione
 * (notturno, mlm:recalculate-points) dall'erogazione del premio EUR
 * (settimanale, mlm:calculate-weekly-bonuses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_pending_rank_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('rank');
            $table->timestamp('detected_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Una promozione allo stesso grado non viene mai riaccodata una
            // volta gia' presente/processata: enforce anche a livello DB
            // della regola "mai due volte per lo stesso grado".
            $table->unique(['user_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_pending_rank_awards');
    }
};
