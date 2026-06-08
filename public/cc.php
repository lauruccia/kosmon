<?php
if (($_GET['k'] ?? '') !== 'km2026') { http_response_code(403); die(); }
$f = __DIR__ . '/../resources/views/layouts/portal.blade.php';
$content = file_get_contents($f);
// Cerca le righe chiave CSS
preg_match('/\.app-shell\s*\{[^}]+\}/s', $content, $m1);
preg_match('/@media\s*\(max-width:\s*1280px\)[^{]*\{.*?\.app-shell[^}]+\}/s', $content, $m2);
preg_match('/grid-template-columns:\s*272px[^;]+;/', $content, $m3);
echo "=== app-shell base ===\n" . ($m1[0] ?? 'NOT FOUND') . "\n\n";
echo "=== grid-template-columns ===\n" . ($m3[0] ?? 'NOT FOUND') . "\n\n";
echo "=== 1280px media ===\n" . (substr($m2[0] ?? 'NOT FOUND', 0, 300)) . "\n\n";
echo "=== File size: " . strlen($content) . " bytes, Lines: " . substr_count($content, "\n") . "\n";
echo "=== Last modified: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
