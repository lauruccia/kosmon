<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('circuit_capacity_limit')->nullable()->after('is_super_admin');
            $table->bigInteger('negative_balance_limit')->nullable()->after('circuit_capacity_limit');
            $table->bigInteger('daily_transaction_limit')->nullable()->after('negative_balance_limit');
            $table->bigInteger('monthly_transaction_limit')->nullable()->after('daily_transaction_limit');
            $table->bigInteger('per_movement_limit')->nullable()->after('monthly_transaction_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'circuit_capacity_limit',
                'negative_balance_limit',
                'daily_transaction_limit',
                'monthly_transaction_limit',
                'per_movement_limit',
            ]);
        });
    }
};
