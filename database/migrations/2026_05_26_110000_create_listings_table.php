<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('category')->default('altro');
            $table->unsignedBigInteger('price_ky');          // importo in KY (centesimi non usati)
            $table->json('images')->nullable();              // array di path relativi storage
            $table->string('status')->default('active');    // active | suspended | expired | draft
            $table->boolean('featured')->default(false);
            $table->string('contact_info')->nullable();     // email/tel visibile al buyer
            $table->string('delivery_note')->nullable();    // es. "Consegna in 48h"
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'company_id']);
            $table->index(['category', 'status']);
            $table->index('featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
