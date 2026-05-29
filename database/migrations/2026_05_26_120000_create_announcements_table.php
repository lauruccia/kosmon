<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type')->default('offer');        // offer | request
            $table->string('title');
            $table->text('body');
            $table->string('sector')->default('altro');      // usa stessi slug di Listing::CATEGORIES
            $table->string('contact_info')->nullable();      // email/telefono visibile
            $table->string('status')->default('active');    // active | suspended | expired | draft
            $table->boolean('featured')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'company_id']);
            $table->index(['sector', 'status']);
            $table->index(['type', 'status']);
            $table->index('featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
