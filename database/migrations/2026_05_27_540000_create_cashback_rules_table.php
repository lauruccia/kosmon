<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);         // es. "Cashback 2% ottobre"
            $table->unsignedInteger('min_amount'); // soglia minima in KY (centesimi)
            $table->decimal('percentage', 5, 2);  // es. 2.50 = 2.5%
            $table->unsignedInteger('max_cashback')->nullable(); // cap per transazione (centesimi), null = illimitato

            // Quali kind di transfer sono eligibili
            $table->json('applicable_kinds'); // es. ["portal_payment","portal_text_request"]

            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_rules');
    }
};
