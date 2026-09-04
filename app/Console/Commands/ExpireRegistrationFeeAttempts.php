<?php

namespace App\Console\Commands;

use App\Models\AgentCodeFeePayment;
use App\Models\CompanyAccountFeePayment;
use App\Models\RegistrationFeePayment;
use App\Services\AgentCodeFeeService;
use App\Services\CompanyAccountFeeService;
use App\Services\RegistrationFeeService;
use Illuminate\Console\Command;

/**
 * Chiude i tentativi di pagamento della quota di iscrizione rimasti a meta'.
 *
 * IL CASO CHE CHIUDE. Ogni click su "paga con carta" apre una riga nuova, ed
 * e' voluto: riusare la riga sovrascriverebbe la sessione Stripe, e un
 * pagamento fatto su quella vecchia non verrebbe mai accreditato. Il prezzo e'
 * che chi cambia idea tre volte lascia tre righe `pending` che non si chiudono
 * da sole, e in `/admin/quote-iscrizione` la colonna "Stato" smette di dire
 * qualcosa: nessuno sa piu' quali righe stanno davvero aspettando qualcosa.
 *
 * PERCHE' SI PUO' FARE SENZA PERDERE SOLDI. Marcare `failed` un tentativo non
 * lo mette piu' fuori gioco: dal 01/09 il webhook Stripe e la pagina di
 * successo accreditano qualunque riga non sia gia' saldata o annullata, a
 * patto che Stripe confermi l'incasso (StripeCheckoutVerifier). Se dunque un
 * pagamento arriva dopo la scadenza, viene accreditato lo stesso. La finestra
 * di default e' comunque piu' larga della vita di una sessione di checkout
 * Stripe, che scade da sola dopo 24 ore.
 *
 * DAL 03/09/2026 LE QUOTE SONO TRE: si e' aggiunta l'apertura conto delle
 * aziende, e questo comando legge anche la sua tabella. Quando ne nascera' una
 * quarta, l'elenco da allungare e' quello dentro handle() — non esiste nessun
 * registro che le enumeri da solo.
 *
 * VALE PER TUTTE E DUE LE QUOTE (02/09/2026). Nato per i 30 dei privati, per
 * tre giorni ha ignorato le righe della quota codice agente, che quindi in
 * /admin/quote-codice-agente non si chiudevano mai: la colonna "Stato" era
 * piena di `pending` di gente che aveva cambiato idea, e smetteva di dire
 * qualcosa proprio dove serviva di piu' (480 euro a riga). Le due tabelle si
 * comportano allo stesso identico modo, e questo comando le legge insieme.
 *
 * I BONIFICI NON SI TOCCANO, MAI. Vivono in uno stato loro
 * (`pending_bank_transfer`) e aspettare e' esattamente il loro mestiere: chi
 * ha in mano una causale puo' andare in banca dopo una settimana. Chiuderli
 * d'ufficio vorrebbe dire far arrivare un bonifico vero su una richiesta che
 * il circuito ha gia' buttato via.
 */
class ExpireRegistrationFeeAttempts extends Command
{
    protected $signature = 'quote:scadi-tentativi
                            {--ore=24 : Dopo quante ore un tentativo non pagato si considera abbandonato}
                            {--dry-run : Elenca soltanto, senza chiudere niente}';

    protected $description = 'Chiude i tentativi di pagamento delle quote (iscrizione, codice agente e apertura conto) rimasti in sospeso';

    public function handle(RegistrationFeeService $fees, AgentCodeFeeService $quoteAgente, CompanyAccountFeeService $quoteAzienda): int
    {
        $ore   = max(1, (int) $this->option('ore'));
        $prova = (bool) $this->option('dry-run');

        $motivo = 'Tentativo abbandonato: nessun pagamento entro ' . $ore . ' ore.';
        $totale = 0;

        // Le due quote, con lo stesso identico trattamento. La chiusura la fa
        // il servizio di ciascuna e non un update diretto: e' il servizio a
        // sapere che una riga gia' saldata non si tocca.
        $code = [
            ['Quota di iscrizione', RegistrationFeePayment::query()
                ->where('status', RegistrationFeePayment::STATUS_PENDING)
                // Ridondante — un bonifico non e' mai in `pending` — ma scritto
                // lo stesso: se un giorno gli stati cambiassero, la riga che
                // protegge i bonifici deve essere qui, non nella memoria di
                // chi ha scritto il resto.
                ->where('payment_method', '!=', RegistrationFeePayment::METHOD_BANK_TRANSFER)
                ->where('created_at', '<=', now()->subHours($ore))
                ->with('user')
                ->get(), fn ($riga) => $fees->markFailed($riga, $motivo)],

            ['Quota codice agente', AgentCodeFeePayment::query()
                ->where('status', AgentCodeFeePayment::STATUS_PENDING)
                // Ridondante quanto la gemella qui sopra, e tenuta per lo
                // stesso identico motivo: un bonifico vive in uno stato suo e
                // in `pending` non ci finisce mai, quindi nessun test la puo'
                // distinguere e la mutazione che la toglie sopravvive. Resta
                // scritta perche' se un giorno gli stati cambiassero, la riga
                // che protegge i bonifici deve stare qui e non nella memoria
                // di chi ha scritto il resto.
                ->where('payment_method', '!=', AgentCodeFeePayment::METHOD_BANK_TRANSFER)
                ->where('created_at', '<=', now()->subHours($ore))
                ->with('user')
                ->get(), fn ($riga) => $quoteAgente->markFailed($riga, $motivo)],

            ['Quota apertura conto', CompanyAccountFeePayment::query()
                ->where('status', CompanyAccountFeePayment::STATUS_PENDING)
                // Ridondante come le due gemelle qui sopra, e tenuta per lo
                // stesso motivo: la riga che protegge i bonifici deve stare
                // qui, non nella memoria di chi ha scritto il resto.
                ->where('payment_method', '!=', CompanyAccountFeePayment::METHOD_BANK_TRANSFER)
                ->where('created_at', '<=', now()->subHours($ore))
                ->with('user')
                ->get(), fn ($riga) => $quoteAzienda->markFailed($riga, $motivo)],
        ];

        foreach ($code as [$etichetta, $tentativi, $chiudi]) {
            if ($tentativi->isEmpty()) {
                continue;
            }

            $this->line($etichetta . ':');

            foreach ($tentativi as $tentativo) {
                $this->line(sprintf(
                    '  %s  %s  %s  %s',
                    $tentativo->uuid,
                    str_pad((string) $tentativo->payment_method, 14),
                    $tentativo->user?->email ?? '(utente sparito)',
                    $tentativo->created_at?->format('d/m/Y H:i'),
                ));

                if ($prova) {
                    continue;
                }

                $chiudi($tentativo);
            }

            $totale += $tentativi->count();
        }

        if ($totale === 0) {
            $this->info('Nessun tentativo da chiudere.');

            return self::SUCCESS;
        }

        $this->info($prova
            ? $totale . ' tentativi verrebbero chiusi (dry-run).'
            : $totale . ' tentativi chiusi.');

        return self::SUCCESS;
    }
}
