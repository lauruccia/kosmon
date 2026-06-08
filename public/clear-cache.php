<?php
// File temporaneo - si autoelimina dopo l'uso
if (file_exists(__DIR__ . '/../bootstrap/cache/config.php')) {
    @unlink(__DIR__ . '/../bootstrap/cache/config.php');
}
if (file_exists(__DIR__ . '/../bootstrap/cache/routes-v7.php')) {
    @unlink(__DIR__ . '/../bootstrap/cache/routes-v7.php');
}
if (file_exists(__DIR__ . '/../bootstrap/cache/services.php')) {
    @unlink(__DIR__ . '/../bootstrap/cache/services.php');
}

// Svuota storage/framework/views/
$viewsDir = __DIR__ . '/../storage/framework/views/';
$count = 0;
if (is_dir($viewsDir)) {
    foreach (glob($viewsDir . '*.php') as $file) {
        @unlink($file);
        $count++;
    }
}

// Svuota storage/framework/cache/data/
$cacheDir = __DIR__ . '/../storage/framework/cache/data/';
$cacheCount = 0;
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            @unlink($file->getPathname());
            $cacheCount++;
        }
    }
}

// Elimina questo file
@unlink(__FILE__);

echo "✅ Cache pulita!<br>";
echo "View compilate eliminate: <b>$count</b><br>";
echo "Cache files eliminati: <b>$cacheCount</b><br>";
echo "Questo file si è autodistrutto.";
