<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE C — il promemoria della quota in euro ha bisogno di ricordarsi di sé.
 *
 * Un ordine che resta fermo in attesa del pagamento in euro viene sollecitato
 * una volta sola. Per non ripetersi il comando deve sapere se ha gia' scritto,
 * e quel "gia' scritto" va in una colonna: dedurlo dalla tabella delle
 * notifiche funzionerebbe solo finche' l'utente tiene acceso il canale
 * `database`, e chi lo spegne verrebbe sollecitato ogni notte.
 *
 * ADDITIVA, NESSUN BACKFILL: gli ordini gia' in attesa partono da NULL e
 * riceveranno il loro sollecito quando toccherà a loro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('euro_reminder_sent_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('euro_reminder_sent_at');
        });
    }
};
