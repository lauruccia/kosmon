<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le consegne dei webhook verso le APPLICAZIONI del circuito — fase 2b.
 *
 * I webhook che KMoney manda oggi appartengono a un'azienda: un negoziante
 * registra il suo indirizzo e riceve gli eventi dei suoi incassi. Kosmoshop non
 * è un'azienda: è la piattaforma su cui vendono tutte. Se dovesse ricevere
 * `company.trading_status_changed` come webhook aziendale, ogni singola azienda
 * del circuito dovrebbe registrarsi l'indirizzo di kshop — e chi non lo facesse
 * continuerebbe a vendere al mix sbagliato, che è esattamente il guasto che
 * questo evento esiste per evitare (§3.2 del piano).
 *
 * Quindi il canale è uno solo, per applicazione, e vive dove vivono già i
 * client OAuth: in `config/oauth.php` più due righe di `.env`. Nessuna tabella
 * di configurazione, nessuna schermata: finché `OAUTH_KSHOP_WEBHOOK_URL` è
 * vuoto il canale non esiste, ed è anche l'interruttore per spegnerlo.
 *
 * Questa tabella è soltanto il registro delle consegne — quello che l'admin
 * guarda quando kshop dice "non mi è arrivato niente".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('client_id', 100)->index();
            $table->string('event', 60)->index();
            $table->string('url', 500);

            // Il corpo ESATTO che è stato firmato e spedito, non i soli dati.
            // La firma HMAC è calcolata su questi byte: tenerli permette di
            // rispondere a "la firma non mi torna" senza tirare a indovinare.
            $table->text('body');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_webhook_deliveries');
    }
};
