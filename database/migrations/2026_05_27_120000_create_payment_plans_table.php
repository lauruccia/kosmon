<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('total_amount');
            $table->string('currency_code')->default('KY');
            $table->unsignedSmallInteger('installments_count');
            $table->string('frequency')->default('monthly'); // monthly | weekly | biweekly
            $table->date('first_due_date');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active | completed | cancelled
            $table->timestamps();

            $table->index(['from_account_id', 'status']);
            $table->index(['to_account_id', 'status']);
            $table->index(['status']);
        });

        Schema::create('payment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number'); // 1-based
            $table->bigInteger('amount');
            $table->date('due_date');
            $table->string('status')->default('pending'); // pending | paid | failed | cancelled
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['payment_plan_id', 'status']);
            $table->index(['due_date', 'status']); // used by the daily job
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_installments');
        Schema::dropIfExists('payment_plans');
    }
};
