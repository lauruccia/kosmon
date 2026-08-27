<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ImageResizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fa le versioni ridotte delle foto gia' caricate (27/08/2026).
 *
 * Da oggi le miniature nascono insieme al prodotto, ma le foto che sono in
 * produzione da mesi non ne hanno nessuna: senza questo comando resterebbero
 * grandi per sempre, e sono proprio loro il grosso del catalogo attuale.
 *
 * Va lanciato UNA VOLTA dopo il deploy. E' ri-eseguibile: quello che c'e' gia'
 * lo salta, quindi se si interrompe a meta' basta rilanciarlo.
 *
 *   php artisan shop:miniature --dry-run     guarda e non tocca
 *   php artisan shop:miniature               fa il lavoro
 *   php artisan shop:miniature --rifai       rigenera anche quelle che ci sono
 *                                            (serve solo se cambiano le misure)
 *
 * Su un hosting condiviso conviene spezzarlo: `--limite=50` fa cinquanta
 * prodotti e si ferma, cosi' nessuna esecuzione dura troppo.
 */
class GeneraMiniatureShop extends Command
{
    protected $signature = 'shop:miniature
                            {--rifai : Rigenera anche le miniature che esistono gia\'}
                            {--dry-run : Dice che cosa farebbe, senza scrivere niente}
                            {--limite= : Fermati dopo questo numero di prodotti}';

    protected $description = 'Genera le versioni ridotte delle foto dei prodotti gia\' caricate';

    public function handle(ImageResizer $ridimensionatore): int
    {
        if (! $ridimensionatore->disponibile()) {
            // Non e' un errore da far fallire un cron: e' una notizia. Il sito
            // continua a funzionare mostrando gli originali.
            $this->error('L\'estensione GD non e\' attiva su questo server: le miniature non si possono generare.');
            $this->line('Il catalogo continua a funzionare mostrando le foto originali, solo più lentamente.');

            return self::FAILURE;
        }

        $prova   = (bool) $this->option('dry-run');
        $rifai   = (bool) $this->option('rifai');
        $limite  = $this->option('limite') !== null ? max(1, (int) $this->option('limite')) : null;

        $prodotti = 0;
        $generate = 0;
        $saltate  = 0;

        $query = Listing::query()
            ->whereNotNull('images')
            ->orderBy('id');

        $query->chunkById(50, function ($lotto) use (
            $ridimensionatore, $prova, $rifai, $limite, &$prodotti, &$generate, &$saltate
        ) {
            foreach ($lotto as $listing) {
                $immagini = $listing->images ?? [];

                if ($immagini === []) {
                    continue;
                }

                $prodotti++;

                foreach ($immagini as $path) {
                    foreach (array_keys(ImageResizer::MISURE) as $misura) {
                        if ($prova) {
                            $esiste = Storage::disk('public')
                                ->exists(ImageResizer::pathDerivato($path, $misura));

                            if ($esiste && ! $rifai) {
                                $saltate++;
                            } else {
                                $generate++;
                            }

                            continue;
                        }

                        $ridimensionatore->genera($path, $misura, $rifai) !== null
                            ? $generate++
                            : $saltate++;
                    }
                }

                if ($limite !== null && $prodotti >= $limite) {
                    return false;
                }
            }

            return true;
        });

        $this->info(($prova ? '[prova] ' : '') . "Prodotti esaminati: {$prodotti}");
        $this->info(($prova ? '[prova] ' : '') . "Miniature generate: {$generate}");

        // "Saltate" non vuol dire "fallite": ci finiscono anche le foto gia'
        // piu' piccole della misura chiesta, per cui la miniatura NON si deve
        // fare. E' normale che questo numero sia alto.
        $this->line("Saltate (gia' fatte, o gia' piccole): {$saltate}");

        if ($limite !== null && $prodotti >= $limite) {
            $this->comment("Fermato al limite di {$limite} prodotti: rilancia il comando per continuare.");
        }

        return self::SUCCESS;
    }
}
