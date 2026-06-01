<?php
// FILE DIAGNOSTICO TEMPORANEO - ELIMINA DOPO L'USO
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

header('Content-Type: text/plain');

// 1. Versione PHP
echo "PHP: " . PHP_VERSION . "\n";

// 2. Ultimo commit git
$commit = trim(shell_exec('cd ' . dirname(__DIR__) . ' && git log --oneline -1 2>/dev/null') ?? '');
echo "Git HEAD: " . ($commit ?: 'N/A') . "\n";

// 3. File web.php ultime righe
$webphp = file(dirname(__DIR__) . '/routes/web.php');
$total = count($webphp);
echo "web.php righe: $total\n";
echo "Ultima riga: " . trim($webphp[$total-1]) . "\n";
echo "Penultima: " . trim($webphp[$total-2]) . "\n";

// 4. Cache rotte
$cacheDir = dirname(__DIR__) . '/bootstrap/cache/';
$files = glob($cacheDir . 'routes*');
echo "Cache rotte: " . ($files ? implode(', ', array_map('basename', $files)) : 'NESSUNA') . "\n";

// 5. Prova a caricare le rotte
try {
    $kernel->bootstrap();
    $router = $app->make('router');
    $routes = $router->getRoutes();
    $found = false;
    foreach ($routes as $route) {
        if (str_contains($route->uri(), 'nfc-cards') && $route->methods()[0] === 'GET' && !str_contains($route->uri(), '{')) {
            $found = true;
            break;
        }
    }
    echo "Rotta /nfc-cards registrata: " . ($found ? 'SI' : 'NO') . "\n";
} catch (\Throwable $e) {
    echo "ERRORE caricamento app: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
