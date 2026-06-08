<?php
// Trova la struttura reale del server
$base = __DIR__;
echo "<pre>__DIR__ = $base\n\n";

// Esplora cartelle padre
for ($i = 1; $i <= 5; $i++) {
    $path = $base . str_repeat('/../..', $i - 1) . '/..';
    $real = realpath($base . str_repeat('/..', $i));
    if (!$real) break;
    echo "Livello -$i: $real\n";
    $items = @scandir($real);
    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            echo "  $item\n";
        }
    }
    echo "\n";
    if (in_array('resources', $items ?: [])) {
        echo "*** TROVATO 'resources' a livello -$i ***\n";
        $dashPath = $real . '/resources/views/portal/dashboard.blade.php';
        echo "dashboard.blade.php esiste: " . (file_exists($dashPath) ? 'SI (' . count(file($dashPath)) . ' righe)' : 'NO') . "\n";
        break;
    }
}

@unlink(__FILE__);
echo "</pre>";
