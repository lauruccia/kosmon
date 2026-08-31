<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type');            // visura_camerale | documento_identita | statuto | altro
            $table->string('file_path');       // path relativo su disk 'private'
            $table->string('original_name');   // nome file originale
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->string('status')->default('pending');        // pending | accepted | rejected
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('type');
        });

        // Aggiunge colonna kyc_notes alla tabella companies (note admin sul processo KYC)
        Schema::table('companies', function (Blueprint $table) {
            $table->text('kyc_notes')->nullable()->after('kyc_status');
            $table->foreignId('kyc_reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('kyc_notes');
            $table->timestamp('kyc_reviewed_at')->nullable()->after('kyc_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');

        // `kyc_reviewed_by` ha una chiave esterna: togliere la colonna senza
        // togliere prima il vincolo da 1553. Su SQLite non si vedeva perche'
        // la tabella viene ricostruita da zero a ogni ALTER (B7, 31/08).
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_reviewed_by');
            $table->dropColumn(['kyc_notes', 'kyc_reviewed_at']);
        });
    }
};
