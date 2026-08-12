<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('listing_categories')->nullOnDelete();
            $table->string('slug', 60)->unique();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'is_active']);
        });

        // Sostituisce le categorie hardcoded in Listing::CATEGORIES con un elenco
        // gestibile da pannello admin (categorie + sotto-categorie, richiesta di
        // Laura, 2026-08-12). Lo "slug" e' l'identificativo STABILE salvato su
        // listings.category/listings.subcategory: rinominare "name" dal pannello
        // admin non scollega mai i prodotti gia' assegnati a quella categoria.
        //
        // Naming aggiornato secondo la lista fornita da Laura — alcune sono un
        // semplice rinominare delle 11 categorie precedenti, altre sono nuove di
        // zecca. La rimappatura dei prodotti gia' pubblicati (colonna
        // listings.category) e' nella migration successiva
        // (2026_08_12_130100_add_subcategory_to_listings_table.php).
        $categories = [
            ['arte-e-intrattenimento',    'Arte e intrattenimento'],
            ['artigiani',                 'Artigiani'],
            ['auto-e-moto',               'Auto e Moto'],
            ['costruire-e-abitare',       'Costruire e Abitare'],
            ['dormire',                   'Dormire'],
            ['elettronica-e-tecnologia',  'Elettronica e Tecnologia'],
            ['consulenza-e-formazione',   'Consulenza e Formazione'],
            ['marketing-e-comunicazione', 'Marketing e Comunicazione'],
            ['professionisti',            'Professionisti'],
            ['mangiare-e-bere',           'Mangiare e Bere'],
            ['pet-shop',                  'Pet Shop'],
            ['servizi',                   'Servizi'],
            ['salute-e-bellezza',         'Salute e Bellezza'],
            ['regali-e-preziosi',         'Regali e Preziosi'],
            ['vestire-e-camminare',       'Vestire e Camminare'],
            ['sport',                     'Sport'],
            ['altro',                     'Altro'],
        ];

        foreach ($categories as $i => [$slug, $name]) {
            DB::table('listing_categories')->insert([
                'slug'       => $slug,
                'name'       => $name,
                'is_active'  => true,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_categories');
    }
};
