<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();

            // Prezzo canone annuale, in centesimi (KY e EUR condividono la stessa
            // denominazione 1:1 nel circuito — vedi ky_format()/ky_to_cents()).
            $table->unsignedInteger('price_cents')->default(0);

            // Caratteristiche del piano (l'admin le imposta liberamente per ogni piano).
            $table->boolean('can_sell_products')->default(false);
            $table->string('card_style', 20)->default('simple'); // rich | compact | simple
            $table->unsignedInteger('display_order')->default(99);
            $table->string('badge_color', 9)->nullable(); // #rrggbb, opzionale
            $table->boolean('allow_ky_payment')->default(true); // consente pagamento upgrade in KY
            $table->boolean('is_active')->default(true); // disattivo = non proponibile per nuove sottoscrizioni

            $table->timestamps();
        });

        // Semina i 4 piani storici del circuito, cosi' il comportamento esistente
        // (directory, blocco pubblicazione prodotti, ordinamento) resta identico
        // dopo la migrazione da enum a tabella dinamica. Prezzi indicativi:
        // l'admin li puo' modificare da /admin/piani.
        $now = now();
        DB::table('plans')->insert([
            [
                'slug' => 'ecommerce', 'name' => 'Ecommerce',
                'description' => 'Vendi prodotti e servizi nello shop del circuito. Profilo in evidenza con logo, banner e vetrina completa.',
                'price_cents' => 34900, 'can_sell_products' => true, 'card_style' => 'rich',
                'display_order' => 0, 'badge_color' => '#6b21a8', 'allow_ky_payment' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'vetrina', 'name' => 'Vetrina',
                'description' => 'Profilo in evidenza con logo, banner e vetrina completa nella directory (senza vendita prodotti nello shop).',
                'price_cents' => 19900, 'can_sell_products' => false, 'card_style' => 'rich',
                'display_order' => 1, 'badge_color' => '#1d4ed8', 'allow_ky_payment' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'biglietto', 'name' => 'Biglietto da visita',
                'description' => 'Scheda contatti essenziale nella directory: logo (se presente), indirizzo, telefono, email e sito.',
                'price_cents' => 9900, 'can_sell_products' => false, 'card_style' => 'compact',
                'display_order' => 2, 'badge_color' => '#065f46', 'allow_ky_payment' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'anagrafica', 'name' => 'Anagrafica',
                'description' => 'Presenza base nella directory del circuito, senza vetrina dedicata.',
                'price_cents' => 0, 'can_sell_products' => false, 'card_style' => 'simple',
                'display_order' => 3, 'badge_color' => '#374151', 'allow_ky_payment' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
