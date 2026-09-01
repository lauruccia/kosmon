<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Diagnosi del pagamento con carta (01/09/2026).
 *
 * PERCHE' ESISTE. In produzione il checkout Stripe non si apre — ne' dalla
 * quota di iscrizione ne' dalla ricarica KYCard — e nel log non compare
 * niente. Senza log ogni ipotesi vale l'altra: libreria mancante, chiavi
 * sbagliate, rete in uscita chiusa, log non scrivibile. Questa pagina fa
 * dire al server quale delle quattro, in una schermata sola.
 *
 * E' DI SOLA LETTURA sul circuito: non tocca conti, non muove KY, non salva
 * nessun pagamento. L'unica cosa che crea, e solo se glielo si chiede con
 * ?sessione=1, e' una sessione di Checkout su Stripe — che non e' un
 * incasso: e' un modulo di pagamento vuoto, e se nessuno lo compila scade da
 * solo. Nessuna chiave viene mai mostrata per intero.
 */
class StripeDiagnosticsController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $esiti = [];

        // ── 1. La libreria c'e'? ────────────────────────────────────────────
        // Sul server il vendor/ arriva da un archivio e non da composer
        // install: e' il primo sospetto, ed e' anche quello che spiegherebbe
        // il silenzio nei log del codice vecchio (un \Error non e' una
        // \Exception e passava attraverso il catch).
        $libreria = class_exists(\Stripe\Stripe::class);
        $esiti['Libreria Stripe installata sul server'] = $libreria
            ? ['ok', 'Sì — ' . (defined('\Stripe\Stripe::VERSION') ? 'versione ' . \Stripe\Stripe::VERSION : 'versione ignota')]
            : ['ko', 'NO: la cartella vendor/stripe manca sul server. È questa la causa.'];

        // ── 2. Le chiavi ────────────────────────────────────────────────────
        $secret  = (string) config('services.stripe.secret');
        $public  = (string) config('services.stripe.key');
        $webhook = (string) config('services.stripe.webhook_secret');

        $esiti['Chiave segreta (STRIPE_SECRET)'] = $secret === ''
            ? ['ko', 'Manca in configurazione.']
            : ['ok', $this->descriviChiave($secret)];

        $esiti['Chiave pubblica (STRIPE_KEY)'] = $public === ''
            ? ['warn', 'Manca (non serve al checkout ospitato, ma è un segnale).']
            : ['ok', $this->descriviChiave($public)];

        $esiti['Segreto del webhook'] = $webhook === ''
            ? ['warn', 'Manca: i pagamenti verrebbero accreditati solo dalla pagina di ritorno.']
            : ['ok', 'Configurato.'];

        // Test e live insieme non funzionano mai: una sessione creata con una
        // chiave test non si apre da un sito che rimanda a URL live e
        // viceversa.
        if ($secret !== '' && $public !== '') {
            $modoSecret = str_contains($secret, '_test_') ? 'test' : 'live';
            $modoPublic = str_contains($public, '_test_') ? 'test' : 'live';
            $esiti['Le due chiavi sono dello stesso ambiente'] = $modoSecret === $modoPublic
                ? ['ok', 'Sì, entrambe in modalità ' . $modoSecret . '.']
                : ['ko', "NO: la segreta è $modoSecret e la pubblica è $modoPublic. Vanno rifatte tutte e due nello stesso ambiente."];
        }

        // ── 3. Il server riesce a parlare con Stripe? ───────────────────────
        // Distingue tre cose che da fuori sembrano uguali: rete in uscita
        // chiusa dall'hosting, certificati non verificabili, chiave rifiutata.
        if ($libreria && $secret !== '') {
            try {
                \Stripe\Stripe::setApiKey($secret);
                $account = \Stripe\Account::retrieve();
                $esiti['Il server raggiunge Stripe e la chiave è valida'] = ['ok',
                    'Sì — account ' . ($account->id ?? '?')
                    . ($account->charges_enabled ?? false ? ', incassi attivi' : ', ATTENZIONE: incassi NON attivi su questo account'),
                ];

                if (($account->charges_enabled ?? false) === false) {
                    $esiti['Account Stripe abilitato a incassare'] = ['ko',
                        "L'account esiste ma non può ancora incassare: finché è così il checkout non si apre. Si sblocca dal cruscotto Stripe completando l'attivazione."];
                }
            } catch (\Throwable $e) {
                $esiti['Il server raggiunge Stripe e la chiave è valida'] = ['ko',
                    class_basename($e) . ' — ' . $e->getMessage()];
            }
        }

        // ── 4. Una sessione di checkout vera, se richiesta ──────────────────
        if ($request->boolean('sessione') && $libreria && $secret !== '') {
            try {
                \Stripe\Stripe::setApiKey($secret);
                $sessione = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'line_items'           => [[
                        'price_data' => [
                            'currency'     => 'eur',
                            'unit_amount'  => 100,
                            'product_data' => ['name' => 'Prova tecnica KMoney'],
                        ],
                        'quantity' => 1,
                    ]],
                    'mode'        => 'payment',
                    'success_url' => url('/dashboard'),
                    'cancel_url'  => url('/dashboard'),
                ]);

                $esiti['Prova: creazione di una sessione di pagamento'] = ['ok',
                    'Riuscita. Indirizzo restituito: ' . ($sessione->url ?: 'VUOTO — ed è il problema')];
            } catch (\Throwable $e) {
                $esiti['Prova: creazione di una sessione di pagamento'] = ['ko',
                    class_basename($e) . ' — ' . $e->getMessage()];
            }
        }

        // ── 5. Il log si scrive davvero? ────────────────────────────────────
        // Se la risposta e' no, il silenzio nei log non significa "nessun
        // errore": significa che gli errori non li vediamo, ed e' la prima
        // cosa da riparare.
        $file = storage_path('logs/laravel.log');
        $prima = is_file($file) ? (int) filesize($file) : -1;
        Log::error('Diagnosi Stripe: riga di prova scritta dal backoffice.');
        clearstatcache(true, $file);
        $dopo = is_file($file) ? (int) filesize($file) : -1;

        $esiti['Il file di log è scrivibile'] = $dopo > $prima
            ? ['ok', 'Sì: la riga di prova è finita in ' . $file]
            : ['ko', 'NO: scrivendo una riga di prova il file non è cambiato (' . $file . '). Gli errori non vengono registrati da nessuna parte.'];

        $esiti['Canale di log configurato'] = ['info',
            'LOG_CHANNEL=' . config('logging.default') . ' — cartella ' . storage_path('logs')
            . (is_writable(storage_path('logs')) ? ' (scrivibile)' : ' (NON scrivibile)')];

        // ── 6. Contorno ─────────────────────────────────────────────────────
        $esiti['Estensione cURL'] = extension_loaded('curl') ? ['ok', 'Presente.'] : ['ko', 'Manca: nessuna chiamata a Stripe può partire.'];
        $esiti['Estensione OpenSSL'] = extension_loaded('openssl') ? ['ok', 'Presente.'] : ['ko', 'Manca: le connessioni HTTPS falliscono.'];
        $esiti['PHP'] = ['info', PHP_VERSION];
        $esiti['Cache del codice (OPcache)'] = ['info',
            function_exists('opcache_get_status') && @opcache_get_status(false)
                ? 'Attiva: dopo un deploy può servire un riavvio di PHP perché il codice nuovo entri in funzione.'
                : 'Non attiva.'];

        return view('admin.stripe-diagnostics', [
            'pageTitle' => 'Diagnosi pagamenti con carta',
            'activeNav' => 'stripe-diagnostics',
            'esiti'     => $esiti,
            'sessione'  => $request->boolean('sessione'),
        ]);
    }

    /** Mostra abbastanza per riconoscere la chiave, mai abbastanza per usarla. */
    private function descriviChiave(string $chiave): string
    {
        $ambiente = str_contains($chiave, '_test_') ? 'modalità TEST' : 'modalità LIVE';
        $tipo     = str_starts_with($chiave, 'sk_') ? 'chiave segreta standard'
            : (str_starts_with($chiave, 'rk_') ? 'chiave ristretta (rk_): può non avere il permesso sui Checkout' : 'formato inatteso');

        return $ambiente . ', ' . $tipo . ' — ' . substr($chiave, 0, 8) . '…' . substr($chiave, -4);
    }
}
