<?php

/**
 * Genera le miniature delle foto prodotto SENZA Laravel e senza artisan.
 *
 * PERCHE' ESISTE. Il modo normale e' `php artisan shop:miniature`, ed e' quello
 * da usare dove artisan c'e'. Su kmoney.it pero' il deploy cPanel rimuove
 * artisan e composer dalla cartella pubblicata: la' quel comando non si puo'
 * lanciare, e senza un'alternativa le foto gia' caricate resterebbero grandi
 * per sempre — cioe' proprio quelle che rendono lento il catalogo di oggi.
 *
 * Questo script non ha bisogno di niente: ne' del database, ne' del vendor, ne'
 * di Laravel. Cammina sulle cartelle delle foto e fa il suo lavoro con GD.
 * Le regole sono le stesse di App\Services\ImageResizer:
 *
 *   - due misure, lato lungo 600 (card) e 1400 (medium);
 *   - non ingrandisce mai una foto gia' piccola;
 *   - non rifa' quello che c'e' gia', quindi si puo' rilanciare;
 *   - se una foto non si legge, la salta e va avanti.
 *
 * COME SI LANCIA (da cPanel -> Cron Jobs, oppure Terminal):
 *
 *   php /home/kmoney/public_html/strumenti/miniature-standalone.php
 *   php .../miniature-standalone.php --prova       guarda e non tocca
 *   php .../miniature-standalone.php --rifai       rigenera anche le esistenti
 *
 * SOLO DA RIGA DI COMANDO: se qualcuno prova ad aprirlo col browser non
 * succede niente. Un ridimensionamento di massa raggiungibile via web sarebbe
 * un modo comodissimo per mettere in ginocchio il server.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (! extension_loaded('gd')) {
    fwrite(STDERR, "L'estensione GD non e' attiva su questo server: non posso generare niente.\n");
    exit(1);
}

$misure       = ['card' => 600, 'medium' => 1400];
$pixelMassimi = 30_000_000;

$prova = in_array('--prova', $argv, true) || in_array('--dry-run', $argv, true);
$rifai = in_array('--rifai', $argv, true);

// La cartella delle foto, cercata a partire da questo file:
// strumenti/ -> radice del progetto -> storage/app/public/listings
$radice   = dirname(__DIR__);
$cartella = $radice . '/storage/app/public/listings';

if (! is_dir($cartella)) {
    fwrite(STDERR, "Non trovo la cartella delle foto: {$cartella}\n");
    fwrite(STDERR, "Lancia lo script dalla sua posizione dentro il progetto.\n");
    exit(1);
}

$esaminate = 0;
$generate  = 0;
$saltate   = 0;
$fallite   = 0;

$iteratore = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cartella, FilesystemIterator::SKIP_DOTS)
);

foreach ($iteratore as $file) {
    /** @var SplFileInfo $file */
    if (! $file->isFile()) {
        continue;
    }

    $percorso = $file->getPathname();
    $nomeCartella = basename(dirname($percorso));

    // Le derivate stanno dentro card/ e medium/: non si fanno le miniature
    // delle miniature.
    if (isset($misure[$nomeCartella])) {
        continue;
    }

    $estensione = strtolower($file->getExtension());

    if (! in_array($estensione, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        continue;
    }

    $esaminate++;

    $info = @getimagesize($percorso);

    if ($info === false) {
        $fallite++;
        continue;
    }

    [$larghezza, $altezza] = $info;
    $tipo = $info[2] ?? null;

    if ($larghezza < 1 || $altezza < 1 || ($larghezza * $altezza) > $pixelMassimi) {
        $saltate += count($misure);
        continue;
    }

    foreach ($misure as $nome => $lato) {
        $destinazione = dirname($percorso) . '/' . $nome . '/' . basename($percorso);

        if (! $rifai && is_file($destinazione)) {
            $saltate++;
            continue;
        }

        // Gia' piccola: non si ingrandisce. Chi guarda vedra' l'originale,
        // che e' gia' della misura giusta.
        if ($larghezza <= $lato && $altezza <= $lato) {
            $saltate++;
            continue;
        }

        if ($prova) {
            $generate++;
            continue;
        }

        if (! is_dir(dirname($destinazione)) && ! @mkdir(dirname($destinazione), 0755, true)) {
            $fallite++;
            continue;
        }

        $sorgente = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($percorso),
            IMAGETYPE_PNG  => @imagecreatefrompng($percorso),
            IMAGETYPE_GIF  => @imagecreatefromgif($percorso),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($percorso) : false,
            default        => false,
        };

        if (! $sorgente instanceof GdImage) {
            $fallite++;
            continue;
        }

        // Le foto verticali fatte col telefono sono salvate orizzontali con
        // un'etichetta EXIF che dice di ruotarle: i browser la leggono, GD no.
        if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($percorso);
            $gradi = match ((int) ($exif['Orientation'] ?? 0)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($gradi !== 0) {
                $ruotata = @imagerotate($sorgente, $gradi, 0);
                if ($ruotata instanceof GdImage) {
                    imagedestroy($sorgente);
                    $sorgente = $ruotata;
                }
            }
        }

        $sorgenteL = imagesx($sorgente);
        $sorgenteA = imagesy($sorgente);
        $fattore   = $lato / max($sorgenteL, $sorgenteA);
        $nuovaL    = max(1, (int) round($sorgenteL * $fattore));
        $nuovaA    = max(1, (int) round($sorgenteA * $fattore));

        $ridotta = imagecreatetruecolor($nuovaL, $nuovaA);

        if (in_array($tipo, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            imagealphablending($ridotta, false);
            imagesavealpha($ridotta, true);
            imagefilledrectangle($ridotta, 0, 0, $nuovaL - 1, $nuovaA - 1, imagecolorallocatealpha($ridotta, 0, 0, 0, 127));
        }

        imagecopyresampled($ridotta, $sorgente, 0, 0, 0, 0, $nuovaL, $nuovaA, $sorgenteL, $sorgenteA);
        imagedestroy($sorgente);

        $scritta = match ($tipo) {
            IMAGETYPE_JPEG => @imagejpeg($ridotta, $destinazione, 78),
            IMAGETYPE_PNG  => @imagepng($ridotta, $destinazione, 6),
            IMAGETYPE_GIF  => @imagegif($ridotta, $destinazione),
            IMAGETYPE_WEBP => function_exists('imagewebp') && @imagewebp($ridotta, $destinazione, 80),
            default        => false,
        };

        imagedestroy($ridotta);

        $scritta ? $generate++ : $fallite++;
    }
}

$prefisso = $prova ? '[prova] ' : '';

echo $prefisso . "Foto esaminate:     {$esaminate}\n";
echo $prefisso . "Miniature generate: {$generate}\n";
echo "Saltate (gia' fatte, o gia' piccole): {$saltate}\n";

if ($fallite > 0) {
    echo "Non riuscite: {$fallite} (file illeggibili o permessi mancanti)\n";
}

echo "\nIl catalogo funziona comunque: dove la miniatura manca, la pagina mostra l'originale.\n";
