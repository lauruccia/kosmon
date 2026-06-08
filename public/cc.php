<?php
if (($_GET['k'] ?? '') !== 'km2026') { http_response_code(403); die(); }
$dir = __DIR__ . '/../storage/framework/views';
$n = 0;
foreach (glob($dir . '/*.php') as $f) { unlink($f) && $n++; }
echo "OK: $n view cancellate.";
