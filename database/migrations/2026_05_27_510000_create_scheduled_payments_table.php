<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();

            $table->unsignedInteger('amount'); // centesimi KY

            $table->string('description', 500);

            // Data/ora di esecuzione programmata
            $table->timestamp('scheduled_at');

            // pending | executed | cancelled | failed
            $table->string('status', 20)->default('pending')->index();

            // Compilato dopo esecuzione
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_payments');
    }
};
