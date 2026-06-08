<?php
echo "<pre>";

// Esplora kosmon
$kosmon = '/home2/kosmopay/kosmon';
echo "=== /home2/kosmopay/kosmon ===\n";
$items = @scandir($kosmon);
if ($items) {
    foreach ($items as $f) {
        if ($f[0] === '.') continue;
        echo "  $f\n";
    }
} else {
    echo "  NON ACCESSIBILE\n";
}

// Esplora public_html
echo "\n=== /home2/kosmopay/public_html ===\n";
$items2 = @scandir('/home2/kosmopay/public_html');
if ($items2) {
    foreach ($items2 as $f) {
        if ($f[0] === '.') continue;
        echo "  $f\n";
    }
}

// Cerca dashboard.blade.php
echo "\n=== Cerca dashboard.blade.php ===\n";
$paths = [
    '/home2/kosmopay/kosmon/resources/views/portal/dashboard.blade.php',
    '/home2/kosmopay/public_html/resources/views/portal/dashboard.blade.php',
    '/home2/kosmopay/resources/views/portal/dashboard.blade.php',
];
foreach ($paths as $p) {
    if (file_exists($p)) {
        echo "TROVATO: $p\n";
        echo "Righe: " . count(file($p)) . "\n";
        echo "Ultime 3 righe:\n" . htmlspecialchars(implode('', array_slice(file($p), -3))) . "\n";
    } else {
        echo "NO: $p\n";
    }
}

// Git info
echo "\n=== Git info ===\n";
foreach (['/home2/kosmopay/kosmon/.git/COMMIT_EDITMSG', '/home2/kosmopay/.git/COMMIT_EDITMSG'] as $g) {
    if (file_exists($g)) echo "Ultimo commit ($g): " . file_get_contents($g) . "\n";
}

@unlink(__FILE__);
echo "</pre>";
