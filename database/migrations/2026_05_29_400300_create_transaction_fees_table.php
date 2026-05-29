<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transaction_fees', function (Blueprint $table) {
            $table->id();
            $table->string('operation_kind');            // portal_payment, portal_installment, ...
            $table->string('fee_type')->default('flat'); // flat | percentage
            $table->decimal('fee_value', 10, 4)->default(0);
            $table->integer('min_fee')->default(0);      // KY cents, applicato solo a percentage
            $table->integer('max_fee')->nullable();      // KY cents, cap
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('transaction_fees');
    }
};
