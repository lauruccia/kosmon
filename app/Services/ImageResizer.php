<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fa le versioni piccole delle foto dei prodotti.
 *
 * IL PROBLEMA (audit ecommerce del 26/08/2026, punto sulle prestazioni). Le
 * foto dei prodotti si mostrano tali e quali sono state caricate. Una foto
 * fatta col telefono pesa fra i 3 e i 6 MB ed e' larga 4000 pixel; nella
 * griglia dello shop se ne vedono quindici alla volta dentro riquadri da 300.
 * Il browser scarica decine di megabyte per disegnare dei francobolli, e su
 * una connessione mobile la pagina resta bianca per secondi. E' la lentezza
 * che si sente davvero, molto piu' delle query.
 *
 * LA SOLUZIONE, e i suoi confini. Al caricamento si salvano DUE copie piu'
 * piccole accanto all'originale. L'originale non si tocca mai: e' la roba del
 * venditore, e se un giorno le misure cambiano si rigenera tutto da li'.
 *
 *   listings/{uuid}/foto.jpg           <- originale, intatto
 *   listings/{uuid}/card/foto.jpg      <- 600px, le griglie
 *   listings/{uuid}/medium/foto.jpg    <- 1400px, la scheda prodotto
 *
 * NIENTE LIBRERIE NUOVE, solo GD (che sta dentro PHP). Non e' pigrizia: su
 * questi due server il vendor si aggiorna a mano senza SSH, quindi ogni
 * pacchetto in piu' e' un rischio di deploy, non una comodita'.
 *
 * NON FALLISCE MAI RUMOROSAMENTE. Se GD manca, se il file e' corrotto, se
 * l'immagine e' cosi' grande da non entrare in memoria: si registra la cosa
 * nel log e si va avanti. Chi guarda la pagina vedra' l'originale grande —
 * lento ma giusto. Un prodotto che non si riesce a pubblicare perche' una
 * miniatura non si genera sarebbe un danno molto peggiore della lentezza che
 * stiamo togliendo.
 */
class ImageResizer
{
    /** Le griglie: card dello shop, "I miei prodotti", carrello, offerte. */
    public const CARD = 'card';

    /** La foto grande della scheda prodotto. */
    public const MEDIUM = 'medium';

    /**
     * Lato lungo in pixel di ogni misura.
     *
     * 600 per una card disegnata a ~300: il doppio, cosi' resta nitida sugli
     * schermi retina senza scaricare l'inutile. 1400 per la scheda prodotto,
     * che e' grande ma non a schermo intero — per l'ingrandimento c'e' la
     * lente, e quella apre l'originale.
     */
    public const MISURE = [
        self::CARD   => 600,
        self::MEDIUM => 1400,
    ];

    /**
     * Oltre questa dimensione non ci si prova nemmeno.
     *
     * GD lavora in memoria non compressa: 4 byte per pixel piu' la copia di
     * destinazione. 30 milioni di pixel sono gia' ~120 MB solo per leggerla, e
     * un `memory_limit` esaurito non e' un'eccezione che si cattura: e' il
     * processo PHP che muore e l'utente che vede una pagina bianca dopo aver
     * caricato il prodotto.
     */
    private const PIXEL_MASSIMI = 30_000_000;

    /** GD c'e'? Senza, questo servizio non fa niente e lo dice. */
    public function disponibile(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * Il path della versione ridotta di un'immagine.
     *
     * Pura manipolazione di stringa: non guarda il disco e non promette che il
     * file esista. Serve sia a scriverlo sia a cercarlo.
     */
    public static function pathDerivato(string $path, string $misura): string
    {
        $cartella = trim(dirname($path), '.');
        $nome     = basename($path);

        return ($cartella === '' ? '' : $cartella . '/') . $misura . '/' . $nome;
    }

    /**
     * Genera tutte le misure per un'immagine appena caricata.
     *
     * @return array<string, string> misura => path generato (solo quelle riuscite)
     */
    public function generaTutte(string $path): array
    {
        $fatte = [];

        foreach (array_keys(self::MISURE) as $misura) {
            $derivato = $this->genera($path, $misura);

            if ($derivato !== null) {
                $fatte[$misura] = $derivato;
            }
        }

        return $fatte;
    }

    /**
     * Genera UNA misura. Torna il path scritto, oppure null se non si e'
     * potuto fare — e in quel caso chi guarda vedra' l'originale.
     *
     * Non rifa' un lavoro gia' fatto: se il file c'e' gia' lo lascia stare, a
     * meno di `$rifai`. E' quello che rende il comando di backfill
     * ri-eseguibile su migliaia di foto senza ricominciare da capo ogni volta.
     */
    public function genera(string $path, string $misura, bool $rifai = false): ?string
    {
        if (! isset(self::MISURE[$misura]) || ! $this->disponibile()) {
            return null;
        }

        $disco     = Storage::disk('public');
        $destinato = self::pathDerivato($path, $misura);

        if (! $rifai && $disco->exists($destinato)) {
            return $destinato;
        }

        if (! $disco->exists($path)) {
            return null;
        }

        try {
            $origine = $disco->path($path);
            $info    = @getimagesize($origine);

            if ($info === false) {
                return null;
            }

            [$larghezza, $altezza] = $info;
            $tipo = $info[2] ?? null;

            if ($larghezza < 1 || $altezza < 1 || ($larghezza * $altezza) > self::PIXEL_MASSIMI) {
                Log::warning('shop.miniatura.saltata', [
                    'path'   => $path,
                    'misura' => $misura,
                    'motivo' => 'immagine troppo grande o illeggibile',
                    'pixel'  => $larghezza * $altezza,
                ]);

                return null;
            }

            // Gia' piccola: non si INGRANDISCE mai. Una foto da 300px stirata a
            // 600 pesa di piu' dell'originale ed e' anche piu' brutta. In
            // questo caso non c'e' derivato, e l'accessor ripiega
            // sull'originale — che e' gia' della misura giusta.
            $lato = self::MISURE[$misura];

            if ($larghezza <= $lato && $altezza <= $lato) {
                return null;
            }

            $sorgente = $this->apri($origine, $tipo);

            if ($sorgente === null) {
                return null;
            }

            $sorgente = $this->raddrizza($sorgente, $origine, $tipo);
            $larghezza = imagesx($sorgente);
            $altezza   = imagesy($sorgente);

            $fattore    = $lato / max($larghezza, $altezza);
            $nuovaL     = max(1, (int) round($larghezza * $fattore));
            $nuovaA     = max(1, (int) round($altezza * $fattore));
            $ridotta    = imagecreatetruecolor($nuovaL, $nuovaA);

            $this->conservaTrasparenza($ridotta, $tipo);

            imagecopyresampled($ridotta, $sorgente, 0, 0, 0, 0, $nuovaL, $nuovaA, $larghezza, $altezza);
            imagedestroy($sorgente);

            $disco->makeDirectory(dirname($destinato));
            $scritta = $this->salva($ridotta, $disco->path($destinato), $tipo);
            imagedestroy($ridotta);

            return $scritta ? $destinato : null;
        } catch (\Throwable $e) {
            Log::warning('shop.miniatura.fallita', [
                'path'   => $path,
                'misura' => $misura,
                'errore' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancella le versioni ridotte di un'immagine.
     * Va chiamata quando si cancella l'originale, altrimenti le miniature
     * restano sul disco per sempre come spazzatura invisibile.
     */
    public function eliminaDerivate(string $path): void
    {
        $disco = Storage::disk('public');

        foreach (array_keys(self::MISURE) as $misura) {
            $derivato = self::pathDerivato($path, $misura);

            if ($disco->exists($derivato)) {
                $disco->delete($derivato);
            }
        }
    }

    // ── Il lavoro sporco con GD ──────────────────────────────────────────────

    private function apri(string $file, ?int $tipo): ?\GdImage
    {
        $immagine = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG  => @imagecreatefrompng($file),
            IMAGETYPE_GIF  => @imagecreatefromgif($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default        => false,
        };

        return $immagine instanceof \GdImage ? $immagine : null;
    }

    /**
     * Rimette dritte le foto scattate col telefono.
     *
     * Una foto verticale fatta con l'iPhone e' salvata ORIZZONTALE con
     * un'etichetta EXIF che dice "ruotala". I browser leggono l'etichetta,
     * GD no: senza questo passaggio le miniature dei ritratti uscirebbero
     * tutte coricate, ed e' il genere di dettaglio per cui il venditore
     * pensa che il sito sia rotto.
     */
    private function raddrizza(\GdImage $immagine, string $file, ?int $tipo): \GdImage
    {
        if ($tipo !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $immagine;
        }

        $exif = @exif_read_data($file);
        $orientamento = (int) ($exif['Orientation'] ?? 0);

        $gradi = match ($orientamento) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($gradi === 0) {
            return $immagine;
        }

        $ruotata = @imagerotate($immagine, $gradi, 0);

        if (! $ruotata instanceof \GdImage) {
            return $immagine;
        }

        imagedestroy($immagine);

        return $ruotata;
    }

    private function conservaTrasparenza(\GdImage $tela, ?int $tipo): void
    {
        if ($tipo !== IMAGETYPE_PNG && $tipo !== IMAGETYPE_WEBP && $tipo !== IMAGETYPE_GIF) {
            return;
        }

        imagealphablending($tela, false);
        imagesavealpha($tela, true);
        $trasparente = imagecolorallocatealpha($tela, 0, 0, 0, 127);
        imagefilledrectangle($tela, 0, 0, imagesx($tela) - 1, imagesy($tela) - 1, $trasparente);
    }

    private function salva(\GdImage $immagine, string $destinazione, ?int $tipo): bool
    {
        return match ($tipo) {
            // 78: la qualita' oltre la quale l'occhio non distingue piu' niente
            // su un'immagine gia' rimpicciolita, ma il file continua a crescere.
            IMAGETYPE_JPEG => @imagejpeg($immagine, $destinazione, 78),
            IMAGETYPE_PNG  => @imagepng($immagine, $destinazione, 6),
            IMAGETYPE_GIF  => @imagegif($immagine, $destinazione),
            IMAGETYPE_WEBP => function_exists('imagewebp') && @imagewebp($immagine, $destinazione, 80),
            default        => false,
        };
    }
}
