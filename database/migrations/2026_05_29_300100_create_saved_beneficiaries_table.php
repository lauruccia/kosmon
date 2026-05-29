<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('owner_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('beneficiary_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('alias', 100)->nullable();  // nome personalizzato
            $table->text('notes')->nullable();
            $table->timestamps();

            // Un account non può salvare lo stesso destinatario due volte
            $table->unique(['owner_account_id', 'beneficiary_account_id']);
            $table->index('owner_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_beneficiaries');
    }
};
