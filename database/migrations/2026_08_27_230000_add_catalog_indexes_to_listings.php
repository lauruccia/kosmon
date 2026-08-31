<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tre indici per il catalogo (27/08/2026, audit ecommerce del 26/08).
 *
 * Gli indici che c'erano — `(status, company_id)`, `(category, status)`,
 * `featured`, `subcategory` — coprono il FILTRO ma non l'ORDINAMENTO. Ogni
 * pagina dello shop finisce con:
 *
 *     ORDER BY featured DESC, created_at DESC LIMIT 15
 *
 * e nessuno di quegli indici arriva fino a `created_at`. Il database deve
 * quindi leggere TUTTE le righe che passano il filtro, ordinarle in memoria e
 * buttarne via tutte tranne quindici. Con duecento prodotti non si nota; e' il
 * genere di cosa che smette di funzionare esattamente quando il circuito
 * comincia ad andare bene.
 *
 * Con questi indici le stesse quindici righe si leggono gia' nell'ordine
 * giusto, e la pagina 12 costa quanto la pagina 1.
 *
 * NIENTE INDICE PER LA RICERCA TESTUALE: `title LIKE '%parola%'` con il jolly
 * davanti non puo' usare nessun indice B-tree, e fingere il contrario
 * aggiungerebbe solo peso in scrittura. Se un giorno la ricerca diventera'
 * lenta servira' un indice FULLTEXT e una query diversa — un lavoro suo, non
 * un indice in piu' qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // 1. Il catalogo, e anche la fascia "in evidenza".
            //    `status` in uguaglianza, poi featured e created_at nell'ordine
            //    esatto della ORDER BY. Serve DUE query con un indice solo:
            //    la griglia (status='active') e la fascia in evidenza
            //    (status='active' AND featured=1), perche' featured e' in
            //    uguaglianza e created_at resta ordinato dentro il gruppo.
            $table->index(['status', 'featured', 'created_at'], 'listings_status_featured_created_index');

            // 2. "I miei prodotti" e lo shop di un singolo venditore.
            //    L'indice che c'era, `(status, company_id)`, ha le colonne
            //    nell'ordine sbagliato per queste due pagine: partono
            //    dall'azienda, non dallo stato.
            $table->index(['company_id', 'status', 'created_at'], 'listings_company_status_created_index');

            // 3. La navigazione per categoria, che e' come si gira davvero un
            //    catalogo. Quattro colonne perche' l'ordinamento e' a due
            //    livelli: senza `featured` dentro l'indice, il riordino in
            //    memoria tornerebbe comunque.
            $table->index(['category', 'status', 'featured', 'created_at'], 'listings_category_status_featured_created_index');
        });
    }

    public function down(): void
    {
        // `listings_company_status_created_index` comincia per company_id e
        // MySQL lo elegge a indice di appoggio della chiave esterna verso
        // companies: toglierlo da errore 1553. Ci pensa SchemaIndex, che
        // rimette un indice semplice sulla prima colonna e riprova.
        foreach ([
            'listings_status_featured_created_index',
            'listings_company_status_created_index',
            'listings_category_status_featured_created_index',
        ] as $indice) {
            SchemaIndex::dropIfExists('listings', $indice);
        }
    }
};
