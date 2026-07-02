<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inviti email tracciati degli agenti MLM: l'agente invita via email con il
 * proprio link referral e vede se l'invitato si e' registrato o meno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->string('status', 20)->default('pending'); // pending | registered
            $table->foreignId('registered_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_user_id', 'email']);
            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_invitations');
    }
};
