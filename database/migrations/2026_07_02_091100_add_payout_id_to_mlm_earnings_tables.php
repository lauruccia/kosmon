<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collega commissioni e bonus alla liquidazione EUR (mlm_payouts) che li
     * ha aggregati, per evitare di ricontarli in liquidazioni successive.
     */
    public function up(): void
    {
        Schema::table('mlm_commissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('mlm_payout_id')->nullable()->after('status');
            $table->foreign('mlm_payout_id')->references('id')->on('mlm_payouts')->nullOnDelete();
        });

        Schema::table('mlm_bonus_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('mlm_payout_id')->nullable()->after('status');
            $table->foreign('mlm_payout_id')->references('id')->on('mlm_payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mlm_commissions', function (Blueprint $table): void {
            $table->dropForeign(['mlm_payout_id']);
            $table->dropColumn('mlm_payout_id');
        });

        Schema::table('mlm_bonus_payouts', function (Blueprint $table): void {
            $table->dropForeign(['mlm_payout_id']);
            $table->dropColumn('mlm_payout_id');
        });
    }
};
