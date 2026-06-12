<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existing = collect(\DB::select("PRAGMA index_list('transfers')"))->pluck('name');

        Schema::table('transfers', function (Blueprint $table) use ($existing) {
            if (! $existing->contains('transfers_from_status_booked_at_index')) {
                $table->index(['from_account_id', 'status', 'booked_at'], 'transfers_from_status_booked_at_index');
            }
            if (! $existing->contains('transfers_reversed_transfer_id_index')) {
                $table->index('reversed_transfer_id', 'transfers_reversed_transfer_id_index');
            }
            if (! $existing->contains('transfers_related_transfer_id_index')) {
                $table->index('related_transfer_id', 'transfers_related_transfer_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndexIfExists('transfers_from_status_booked_at_index');
            $table->dropIndexIfExists('transfers_reversed_transfer_id_index');
            $table->dropIndexIfExists('transfers_related_transfer_id_index');
        });
    }
};
