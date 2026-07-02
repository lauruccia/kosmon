<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prelievi richiesti dall'agente: quando una liquidazione nasce da una
 * richiesta di prelievo dal portale, requested_at ne registra il momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlm_payouts', function (Blueprint $table) {
            $table->timestamp('requested_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mlm_payouts', function (Blueprint $table) {
            $table->dropColumn('requested_at');
        });
    }
};
