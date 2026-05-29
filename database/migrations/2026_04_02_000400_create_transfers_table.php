<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency_code')->default('KY');
            $table->string('status')->default('pending');
            $table->string('kind')->default('trade_payment');
            $table->string('idempotency_key')->unique();
            $table->text('description')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();

            $table->index(['from_account_id', 'status']);
            $table->index(['to_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};