<?php
$appRoot = '/home2/kosmopay/kosmon';
header('Content-Type: text/plain');
ob_implicit_flush(true); @ob_end_flush();
function out($s) { echo $s . "\n"; }

define('LARAVEL_START', microtime(true));
require "$appRoot/vendor/autoload.php";
$app = require_once "$appRoot/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

out("APP_DEBUG: " . (config('app.debug') ? 'true' : 'false'));
out("APP_ENV:   " . config('app.env'));
out("APP_URL:   " . config('app.url'));

// Connessione DB
try {
    $pdo = DB::connection()->getPdo();
    out("DB: connesso");

    $tables = DB::select("SHOW TABLES LIKE 'nfc_cards'");
    out("Tabella nfc_cards: " . (count($tables) ? 'ESISTE' : 'NON ESISTE - MIGRAZIONE MANCANTE'));

    $tables2 = DB::select("SHOW TABLES LIKE 'sessions'");
    out("Tabella sessions: " . (count($tables2) ? 'ESISTE' : 'NON ESISTE'));

    // Conta utenti e companies
    $users = DB::table('users')->count();
    out("Utenti nel DB: $users");

    $companies = DB::table('companies')->count();
    out("Companies nel DB: $companies");

    // Verifica se ci sono utenti senza company_id
    $noCompany = DB::table('users')->whereNull('company_id')->count();
    out("Utenti senza company_id: $noCompany");

} catch (\Throwable $e) {
    out("DB ERRORE: " . $e->getMessage());
}
