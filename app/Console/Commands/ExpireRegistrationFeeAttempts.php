<?php

namespace App\Console\Commands;

use App\Models\RegistrationFeePayment;
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

    protected $description = 'Chiude i tentativi di pagamento della quota di iscrizione rimasti in sospeso';

    public function handle(RegistrationFeeService $fees): int
    {
        $ore   = max(1, (int) $this->option('ore'));
        $prova = (bool) $this->option('dry-run');

        $tentativi = RegistrationFeePayment::query()
            ->where('status', RegistrationFeePayment::STATUS_PENDING)
            // Ridondante — un bonifico non e' mai in `pending` — ma scritto
            // lo stesso: se un giorno gli stati cambiassero, la riga che
            // protegge i bonifici deve essere qui, non nella memoria di
            // chi ha scritto il resto.
            ->where('payment_method', '!=', RegistrationFeePayment::METHOD_BANK_TRANSFER)
            ->where('created_at', '<=', now()->subHours($ore))
            ->with('user')
            ->get();

        if ($tentativi->isEmpty()) {
            $this->info('Nessun tentativo da chiudere.');

            return self::SUCCESS;
        }

        $motivo = 'Tentativo abbandonato: nessun pagamento entro ' . $ore . ' ore.';
        $chiusi = 0;

        foreach ($tentativi as $tentativo) {
            $this->line(sprintf(
                '%s  %s  %s  %s',
                $tentativo->uuid,
                str_pad((string) $tentativo->payment_method, 14),
                $tentativo->user?->email ?? '(utente sparito)',
                $tentativo->created_at?->format('d/m/Y H:i'),
            ));

            if ($prova) {
                continue;
            }

            $fees->markFailed($tentativo, $motivo);
            $chiusi++;
        }

        $this->info($prova
            ? count($tentativi) . ' tentativi verrebbero chiusi (dry-run).'
            : $chiusi . ' tentativi chiusi.');

        return self::SUCCESS;
    }
}
