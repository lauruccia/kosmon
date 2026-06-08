<?php
$dashboard = __DIR__ . '/../resources/views/portal/dashboard.blade.php';
$portal = __DIR__ . '/../resources/views/layouts/portal.blade.php';

$dashLines = file_exists($dashboard) ? count(file($dashboard)) : 'NON TROVATO';
$portalLines = file_exists($portal) ? count(file($portal)) : 'NON TROVATO';
$dashEnd = file_exists($dashboard) ? implode('', array_slice(file($dashboard), -3)) : '';
$gitHead = @file_get_contents(__DIR__ . '/../.git/HEAD');
$gitLog = @file_get_contents(__DIR__ . '/../.git/COMMIT_EDITMSG');

@unlink(__FILE__);

echo "<pre>";
echo "dashboard.blade.php: $dashLines righe\n";
echo "Ultime 3 righe dashboard:\n" . htmlspecialchars($dashEnd) . "\n";
echo "portal.blade.php: $portalLines righe\n";
echo "Git HEAD: $gitHead";
echo "Ultimo commit msg: $gitLog";
echo "</pre>";
