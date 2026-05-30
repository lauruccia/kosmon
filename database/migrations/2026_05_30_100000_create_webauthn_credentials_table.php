<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Base64url-encoded credential ID (max ~512 chars, in practice ~88)
            $table->string('credential_id', 512)->unique();

            // Serialized PublicKeyCredentialSource (JSON, includes public key + counter)
            $table->text('credential_source');

            // Nome leggibile dato dall'utente
            $table->string('name')->default('Dispositivo');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
