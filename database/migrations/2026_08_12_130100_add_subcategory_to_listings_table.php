<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Sotto-categoria facoltativa (richiesta di Laura, 2026-08-12): a
            // differenza di "category" (sempre obbligatoria) questa resta NULL
            // se il venditore non ne sceglie una, anche quando la categoria
            // scelta ne ha di disponibili.
            $table->string('subcategory')->nullable()->after('category');
            $table->index(['subcategory']);
        });

        // Rimappa le vecchie categorie hardcoded (Listing::CATEGORIES, rimosso da
        // questa release) sui nuovi slug di listing_categories, per non perdere
        // la categoria dei prodotti gia' pubblicati in produzione. Mappatura
        // concordata con Laura il 2026-08-12:
        //
        //   alimentari  -> mangiare-e-bere            formazione  -> consulenza-e-formazione
        //   artigianato -> artigiani                  informatica -> elettronica-e-tecnologia
        //   consulenza  -> consulenza-e-formazione     logistica  -> servizi
        //   marketing   -> marketing-e-comunicazione   salute     -> salute-e-bellezza
        //   turismo     -> dormire                     verde      -> servizi
        //   altro       -> altro
        //
        // Nota: consulenza+formazione confluiscono nella stessa nuova categoria,
        // e logistica+verde confluiscono entrambe in "servizi" — nessuna delle
        // 11 categorie precedenti aveva un corrispettivo 1:1 ovvio per queste
        // due coppie, scelta approvata da Laura invece di crearne di ulteriori
        // non richieste.
        $map = [
            'alimentari'  => 'mangiare-e-bere',
            'artigianato' => 'artigiani',
            'consulenza'  => 'consulenza-e-formazione',
            'formazione'  => 'consulenza-e-formazione',
            'informatica' => 'elettronica-e-tecnologia',
            'logistica'   => 'servizi',
            'marketing'   => 'marketing-e-comunicazione',
            'salute'      => 'salute-e-bellezza',
            'turismo'     => 'dormire',
            'verde'       => 'servizi',
            'altro'       => 'altro',
        ];

        foreach ($map as $old => $new) {
            DB::table('listings')->where('category', $old)->update(['category' => $new]);
        }

        // Rete di sicurezza: un valore di categoria non previsto dalla mappatura
        // (non dovrebbe succedere — il vecchio CATEGORIES aveva solo queste 11
        // chiavi) finisce in "altro" invece di restare orfano di una categoria
        // che non esiste piu' in listing_categories.
        $knownNew = array_values(array_unique($map));
        DB::table('listings')->whereNotIn('category', $knownNew)->update(['category' => 'altro']);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['subcategory']);
            $table->dropColumn('subcategory');
        });

        // Nota: il down() non ripristina i vecchi slug di categoria — la
        // mappatura non e' invertibile 1:1 (consulenza+formazione confluiscono
        // entrambe in consulenza-e-formazione, logistica+verde in servizi).
    }
};
