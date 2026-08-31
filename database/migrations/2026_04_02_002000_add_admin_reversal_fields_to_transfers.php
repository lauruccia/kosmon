<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('reversed_transfer_id')->nullable()->after('idempotency_key')->constrained('transfers')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('reversed_transfer_id');
            $table->string('admin_action')->nullable()->after('refunded_at');
            $table->index(['reversed_transfer_id']);
        });
    }

    public function down(): void
    {
        // Prima la chiave esterna, poi l'indice — e l'indice puo' gia' non
        // esserci, perche' lo tocca anche il down() di 2026_06_12_200000
        // (B7, 31/08).
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_transfer_id');
            $table->dropColumn(['refunded_at', 'admin_action']);
        });

        SchemaIndex::dropIfExists('transfers', 'transfers_reversed_transfer_id_index');
    }
};
