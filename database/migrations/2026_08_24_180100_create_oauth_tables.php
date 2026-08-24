<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le due sole tabelle di "Accedi con KMoney".
 *
 * Non c'è una tabella dei client: i client sono di prima parte, pochissimi, e
 * vivono in config/oauth.php + .env (vedi FASE1_MOTORE_OAUTH.md per il perché).
 *
 * Regola che vale per entrambe: **in chiaro non si salva niente.** Il codice di
 * autorizzazione e i token viaggiano una volta sola verso il client; qui dentro
 * resta solo il loro SHA-256, esattamente come già succede per gli `api_tokens`.
 * Se il database finisse nelle mani sbagliate, non ci sarebbe nessun token
 * riutilizzabile da leggere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();

            // Biglietto usa e getta scambiato con un token. Dura 60 secondi.
            $table->string('code_hash', 64)->unique();

            // Catena: identifica il consenso originale. Se questo codice viene
            // riusato (segno di furto), si revocano tutti i token nati da qui.
            $table->uuid('chain_uuid')->index();

            $table->string('client_id', 100)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // `text` e non `json`: Laravel su MariaDB genererebbe un longtext
            // con CHECK(json_valid()), su MySQL 8 un vero tipo `json`. Qui le
            // migrazioni in produzione si scrivono a mano in SQL, su due motori
            // diversi: meglio una colonna che viene identica su entrambi. A
            // convertire avanti e indietro ci pensa il cast 'array' del model,
            // e nessuna query fa ricerche dentro gli scope.
            $table->text('scopes');
            $table->text('redirect_uri');

            // PKCE: obbligatorio, e solo nella variante S256 (vedi OAuthService).
            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 10)->default('S256');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('created_ip', 45)->nullable();

            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('token_hash', 64)->unique();
            $table->string('refresh_hash', 64)->nullable()->unique();

            // Stessa catena del codice che li ha generati: i rinnovi la
            // ereditano, così una revoca spegne tutta la discendenza.
            $table->uuid('chain_uuid')->index();

            $table->string('client_id', 100)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('scopes');   // vedi nota sopra

            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('created_ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
    }
};
