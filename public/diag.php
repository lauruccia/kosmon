<?php
header('Content-Type: text/plain');
$appRoot = '/home2/kosmopay/kosmon';
$pubHtml = '/home2/kosmopay/public_html';

echo "=== PERCORSI ===\n";
echo "Questo file e' in: " . __DIR__ . "\n";
echo "public_html esiste: " . (is_dir($pubHtml) ? 'SI' : 'NO') . "\n";
echo "kosmon/public esiste: " . (is_dir("$appRoot/public") ? 'SI' : 'NO') . "\n";

echo "\n=== public_html/index.php ===\n";
$idx = "$pubHtml/index.php";
if (file_exists($idx)) {
    echo file_get_contents($idx);
} else {
    echo "NON TROVATO\n";
}

echo "\n=== public_html/.htaccess (prime 30 righe) ===\n";
$ht = "$pubHtml/.htaccess";
if (file_exists($ht)) {
    $lines = file($ht);
    echo implode('', array_slice($lines, 0, 30));
} else {
    echo "NON TROVATO\n";
}
