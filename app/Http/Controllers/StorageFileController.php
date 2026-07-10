<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fallback per i file del disco "public" (loghi, banner, branding).
 *
 * In produzione (hosting condiviso cPanel) la webroot è /home/kmoney/public_html
 * mentre l'app vive in /home/kmoney/kosmon_git: il symlink public/storage non
 * esiste e non può essere creato (nessun accesso al terminale), quindi le
 * richieste a /storage/... non trovano un file statico, il .htaccess le gira a
 * index.php e finiscono su questa route, che legge il file da
 * storage/app/public e lo restituisce.
 *
 * In locale (Laragon) il symlink esiste: il webserver serve il file statico
 * direttamente e questa route non viene mai raggiunta.
 */
class StorageFileController extends Controller
{
    public function __invoke(string $path): Response
    {
        // Difesa esplicita da path traversal — non ci affidiamo solo a Flysystem.
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        // I file caricati hanno nomi hash univoci: se cambiano, cambia l'URL.
        // Possiamo quindi permettere una cache lunga lato browser/proxy.
        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
