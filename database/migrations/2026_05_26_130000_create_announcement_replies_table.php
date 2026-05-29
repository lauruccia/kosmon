<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_replies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();       // chi risponde
            $table->foreignId('company_id')->constrained()->restrictOnDelete();    // azienda di chi risponde
            $table->text('message');
            $table->boolean('is_read')->default(false);   // se il pubblicatore ha letto
            $table->timestamps();

            $table->index(['announcement_id', 'is_read']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_replies');
    }
};
