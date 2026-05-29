<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->bigInteger('default_circuit_capacity_limit')->nullable();
            $table->bigInteger('default_negative_balance_limit')->nullable();
            $table->bigInteger('default_daily_transaction_limit')->nullable();
            $table->bigInteger('default_monthly_transaction_limit')->nullable();
            $table->bigInteger('default_per_movement_limit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
