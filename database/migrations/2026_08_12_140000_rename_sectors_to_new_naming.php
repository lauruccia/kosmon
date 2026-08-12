<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allinea "Settore azienda" (tabella sectors) alla stessa lista di 17 nomi
 * usata per le categorie prodotto shop (listing_categories), su richiesta
 * di Laura (2026-08-12): stessa nomenclatura in entrambe le tendine, ma
 * restano 2 sistemi/tabelle separati (Sector per le aziende, ListingCategory
 * per i prodotti) — Sector non guadagna sotto-categorie ne' uno slug
 * stabile, resta identico architetturalmente, cambia solo "name".
 *
 * Sector.name e' l'identificativo diretto salvato su companies.sector (non
 * c'e' uno slug separato come in ListingCategory), quindi qui ci sono DUE
 * operazioni distinte e volutamente NON accoppiate 1:1 fra loro:
 *
 *  1) BACKFILL DATI: companies.sector (aziende gia' censite) viene rimappato
 *     dal vecchio nome settore al nuovo nome piu' vicino per significato,
 *     mappatura concordata esplicitamente con Laura il 2026-08-12 (incluso
 *     il caso ambiguo "Ristorazione e ospitalità" -> "Mangiare e Bere").
 *     Più vecchi settori possono confluire sullo stesso nuovo nome (es.
 *     Commercio al dettaglio/all'ingrosso/Energia e ambiente/Logistica e
 *     trasporti -> "Servizi"): scelta approvata da Laura invece di
 *     inventare nuove categorie non richieste.
 *
 *  2) RIETICHETTATURA RIGHE: le 17 righe della tabella sectors vengono
 *     rinominate 1:1 (nessun duplicato, nessuna riga persa) sui 17 nuovi
 *     nomi, nello stesso ordine (sort_order) di listing_categories, cosi'
 *     le due tendine mostrano la lista IDENTICA (stessi 17 nomi, stesso
 *     ordine) anche per le aziende non ancora censite. Quale riga fisica
 *     riceva quale nuovo nome e' irrilevante ai fini dei dati: l'aggancio
 *     con le aziende esistenti e' gia' garantito dal backfill per VALORE
 *     al punto 1 (non dipende dall'id della riga).
 */
return new class extends Migration
{
    /** @var array<string,string> Backfill companies.sector: vecchio nome -> nuovo nome (concordata 2026-08-12). */
    private array $companySectorMapping = [
        'Agroalimentare'                     => 'Mangiare e Bere',
        'Artigianato'                        => 'Artigiani',
        'Commercio al dettaglio'             => 'Servizi',
        'Commercio all\'ingrosso'            => 'Servizi',
        'Consulenza e servizi professionali' => 'Consulenza e Formazione',
        'Editoria e media'                   => 'Marketing e Comunicazione',
        'Energia e ambiente'                 => 'Servizi',
        'Formazione e istruzione'            => 'Consulenza e Formazione',
        'ICT e tecnologia'                   => 'Elettronica e Tecnologia',
        'Immobiliare'                        => 'Costruire e Abitare',
        'Logistica e trasporti'              => 'Servizi',
        'Manifatturiero'                     => 'Artigiani',
        'Ristorazione e ospitalità'          => 'Mangiare e Bere',
        'Salute e benessere'                 => 'Salute e Bellezza',
        'Sport e tempo libero'               => 'Sport',
        'Turismo'                            => 'Dormire',
        'Altro'                              => 'Altro',
    ];

    /** @var list<string> I 17 nuovi nomi, stesso ordine di listing_categories. */
    private array $newSectorNamesInOrder = [
        'Arte e intrattenimento',
        'Artigiani',
        'Auto e Moto',
        'Costruire e Abitare',
        'Dormire',
        'Elettronica e Tecnologia',
        'Consulenza e Formazione',
        'Marketing e Comunicazione',
        'Professionisti',
        'Mangiare e Bere',
        'Pet Shop',
        'Servizi',
        'Salute e Bellezza',
        'Regali e Preziosi',
        'Vestire e Camminare',
        'Sport',
        'Altro',
    ];

    public function up(): void
    {
        // 1) Backfill companies.sector, PRIMA di rinominare le righe di
        //    sectors, cosi' non c'e' mai un istante in cui companies.sector
        //    punta a un nome che non esiste piu' in sectors.
        foreach ($this->companySectorMapping as $old => $new) {
            if ($old === $new) {
                continue;
            }

            DB::table('companies')->where('sector', $old)->update(['sector' => $new]);
        }

        // Rete di sicurezza: un valore di companies.sector non previsto dalla
        // mappatura sopra (non dovrebbe succedere — i 17 vecchi nomi erano
        // fissi) finisce in "Altro" invece di restare orfano.
        DB::table('companies')
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->whereNotIn('sector', array_values(array_unique($this->companySectorMapping)))
            ->update(['sector' => 'Altro']);

        // 2) Rietichetta le righe di sectors 1:1 sui 17 nuovi nomi, stesso
        //    ordine di listing_categories. Se in futuro qualcuno ha aggiunto
        //    righe extra oltre alle 17 originali, quelle in eccesso restano
        //    invariate (non c'e' un nuovo nome da assegnare loro).
        $rows = DB::table('sectors')->orderBy('sort_order')->orderBy('id')->get();
        foreach ($rows as $index => $row) {
            if (! array_key_exists($index, $this->newSectorNamesInOrder)) {
                continue;
            }

            DB::table('sectors')->where('id', $row->id)->update([
                'name'       => $this->newSectorNamesInOrder[$index],
                'sort_order' => $index,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non ripristina i vecchi nomi: la mappatura companies.sector non e'
        // invertibile 1:1 (piu' vecchi settori confluiscono sullo stesso
        // nuovo nome, es. 4 settori diversi -> "Servizi"), e l'assegnazione
        // riga-per-riga al punto 2 e' anch'essa persa. Ripristinare da
        // backup se necessario.
    }
};
