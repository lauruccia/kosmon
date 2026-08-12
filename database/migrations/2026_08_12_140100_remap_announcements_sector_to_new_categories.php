<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allinea gli annunci gia' pubblicati in Bacheca annunci (tabella
 * announcements, colonna sector) alla nuova nomenclatura delle categorie
 * shop — su richiesta di Laura (2026-08-12), estensione del rename gia'
 * fatto per Listing::CATEGORIES (vedi
 * 2026_08_12_130100_add_subcategory_to_listings_table): Announcement::SECTORS
 * condivideva apposta gli stessi 11 vecchi slug, quindi usa qui la STESSA
 * identica mappatura vecchio slug -> nuovo slug, per restare coerente.
 */
return new class extends Migration
{
    /** @var array<string,string> */
    private array $map = [
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

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            if ($old === $new) {
                continue;
            }

            DB::table('announcements')->where('sector', $old)->update(['sector' => $new]);
        }

        // Rete di sicurezza: un valore non previsto dalla mappatura (non
        // dovrebbe succedere — i vecchi Announcement::SECTORS avevano solo
        // queste 11 chiavi) finisce in "altro" invece di restare orfano di
        // una voce che non esiste piu' in Announcement::SECTORS.
        $knownNew = array_values(array_unique($this->map));
        DB::table('announcements')->whereNotIn('sector', $knownNew)->update(['sector' => 'altro']);
    }

    public function down(): void
    {
        // Non ripristina i vecchi slug: la mappatura non e' invertibile 1:1
        // (consulenza+formazione confluiscono entrambe in
        // consulenza-e-formazione, logistica+verde in servizi).
    }
};
