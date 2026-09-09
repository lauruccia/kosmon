<?php

namespace App\Console\Commands;

use App\Models\KyCardPurchase;
use Illuminate\Console\Command;

/**
 * Chiude i tentativi di ricarica KYCard rimasti a meta'.
 *
 * IL CASO CHE CHIUDE. Ogni click su «Acquista ora» apre una riga nuova in
 * `ky_card_purchases`, ed e' voluto: riusare la riga sovrascriverebbe la
 * sessione Stripe salvata sull'acquisto, e un pagamento fatto su quella
 * vecchia non verrebbe mai accreditato (StripeCheckoutVerifier rilegge la
 * sessione SALVATA, non quella che arriva dal browser). Il prezzo e' che chi
 * apre il checkout e cambia idea lascia una riga `pending` che non si chiude
 * da sola: in /admin/ky-cards/ordini il contatore «in lavorazione» le somma
 * tutte e smette di dire qualcosa.
 *
 * PERCHE' SI PUO' FARE SENZA PERDERE SOLDI. Marcare `failed` un tentativo non
 * lo mette fuori gioco: dal 01/09/2026 sia il webhook Stripe sia la pagina di
 * successo accreditano qualunque riga che non sia gia' `completed` o
 * `refunded`, purche' il gestore di pagamento confermi l'incasso. Se un
 * pagamento arriva dopo la scadenza viene accreditato lo stesso, e l'admin ha
 * comunque il pulsante «Riprova» (adminRetryCredit) che lavora proprio sulle
 * righe `failed` — e che dal 09/09/2026 chiede a Stripe/PayPal la prova
 * dell'incasso prima di accreditare, invece di fidarsi del click.
 *
 * DUE FINESTRE, PERCHE' SONO DUE COSE DIVERSE.
 *
 *   - Carta e PayPal: 24 ore. E' piu' della vita di una sessione di checkout
 *     Stripe, che scade da sola dopo 24 ore. Chi non ha pagato entro allora
 *     non pagherà più su quella riga.
 *
 *   - Bonifico: 60 giorni (scelta di Laura, 09/09/2026). Aspettare e' il
 *     mestiere di un bonifico — chi ha in mano una causale puo' andare in
 *     banca dopo una settimana — quindi la finestra e' lunghissima apposta, e
 *     resta il caso normale che a chiudere un bonifico sia una persona dalla
 *     pagina «Bonifici in attesa». Questa e' solo la scopa che passa dietro:
 *     due mesi senza che quei soldi siano mai arrivati, e la riga smette di
 *     gonfiare il contatore rosso. Se il bonifico arriva dopo, l'admin ha
 *     ancora «Riprova»: per i bonifici quel pulsante accredita, perche' li' la
 *     prova e' l'estratto conto che ha davanti.
 *     NB: qui NON parte nessuna email al cliente. La notifica che esiste
 *     (KyCardBankTransferRejected) dice «bonifico non ricevuto o non
 *     conforme», che a chi non ha mai bonificato niente suonerebbe come
 *     un'accusa per una cosa che non ha fatto.
 *
 * NEMMENO LE RIGHE GIA' ACCREDITATE. Il filtro su `transfer_id` e' una cintura
 * sopra la bretella dello stato: se un accredito e' avvenuto ma lo stato non
 * fosse stato allineato, quella riga non deve finire fra i tentativi morti.
 */
class ExpireKyCardAttempts extends Command
{
    protected $signature = 'ricarica:scadi-tentativi
                            {--ore=24 : Dopo quante ore un tentativo con carta o PayPal si considera abbandonato}
                            {--giorni-bonifico=60 : Dopo quanti giorni un bonifico mai arrivato si chiude da solo}
                            {--dry-run : Elenca soltanto, senza chiudere niente}';

    protected $description = 'Chiude i tentativi di ricarica KYCard rimasti in sospeso (carta e PayPal dopo ore, bonifici dopo giorni)';

    public function handle(): int
    {
        $ore    = max(1, (int) $this->option('ore'));
        $giorni = max(1, (int) $this->option('giorni-bonifico'));
        $prova  = (bool) $this->option('dry-run');

        $totale = 0;

        $gruppi = [
            [
                'Carta e PayPal',
                'Tentativo abbandonato: nessun pagamento entro ' . $ore . ' ore.',
                KyCardPurchase::query()
                    ->where('status', 'pending')
                    // Ridondante — un bonifico nasce in `pending_bank_transfer`
                    // — ma scritto lo stesso: le righe di bonifico rimaste in
                    // `pending` esistono davvero (nate prima che lo stato
                    // dedicato esistesse, vedi scopeAwaitingBankTransfer), e
                    // vanno trattate col calendario dei bonifici, non con le
                    // 24 ore delle carte.
                    ->where('payment_method', '!=', 'bank_transfer')
                    ->whereNull('transfer_id')
                    ->where('created_at', '<=', now()->subHours($ore)),
            ],
            [
                'Bonifici mai arrivati',
                'Bonifico mai ricevuto: chiuso automaticamente dopo ' . $giorni . ' giorni.',
                KyCardPurchase::query()
                    // La stessa definizione usata dalla pagina «Bonifici in
                    // attesa» e dal suo contatore: se la si riscrive a mano,
                    // prima o poi diverge (09/09/2026, il pulsante diceva 8 e
                    // la pagina «Nessun bonifico in attesa»).
                    ->awaitingBankTransfer()
                    ->whereNull('transfer_id')
                    ->where('created_at', '<=', now()->subDays($giorni)),
            ],
        ];

        foreach ($gruppi as [$etichetta, $motivo, $query]) {
            $tentativi = $query->with(['user', 'kyCard'])->get();

            if ($tentativi->isEmpty()) {
                continue;
            }

            $this->line($etichetta . ':');

            foreach ($tentativi as $tentativo) {
                $this->line(sprintf(
                    '  %s  %s  %s  %s  %s',
                    $tentativo->uuid,
                    str_pad((string) $tentativo->payment_method, 14),
                    str_pad($tentativo->kyCard?->name ?? '(card sparita)', 14),
                    $tentativo->user?->email ?? '(utente sparito)',
                    $tentativo->created_at?->format('d/m/Y H:i'),
                ));

                if ($prova) {
                    continue;
                }

                $tentativo->update([
                    'status'      => 'failed',
                    'admin_notes' => $tentativo->admin_notes ?: $motivo,
                ]);
            }

            $totale += $tentativi->count();
        }

        if ($totale === 0) {
            $this->info('Nessun tentativo di ricarica da chiudere.');

            return self::SUCCESS;
        }

        $this->info($prova
            ? $totale . ' tentativi verrebbero chiusi (dry-run).'
            : $totale . ' tentativi chiusi.');

        return self::SUCCESS;
    }
}
