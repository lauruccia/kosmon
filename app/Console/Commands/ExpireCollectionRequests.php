<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Transfer;
use App\Notifications\PaymentRequestExpiredNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fa scadere le richieste di incasso rimaste senza risposta.
 *
 * IL CASO CHE CHIUDE. Una richiesta creata da /incassa e' un Transfer in stato
 * `pending`: non muove un solo KY finche' il debitore non la conferma. Ma se
 * non la conferma e non la rifiuta — che e' quello che succede quasi sempre —
 * resta li' per sempre. Il QR dinamico scade in 10 minuti (ExpirePaymentRequests);
 * questa, che e' la stessa domanda fatta per iscritto, non scadeva mai. Il
 * risultato e' un elenco movimenti in cui «In attesa» significa tutto e niente,
 * e un pulsante «Conferma» che dopo mesi puo' addebitare a sorpresa un conto
 * che nel frattempo ha fatto altri piani.
 *
 * PERCHE' NON SI PERDE NIENTE. La richiesta non e' un credito: e' l'invito a
 * pagare. Farla scadere non cancella nessun debito e non tocca nessun saldo —
 * il Transfer passa da `pending` a `expired` e basta, senza scrittura contabile
 * (le partite doppie di una richiesta nascono solo alla conferma, in
 * TransferBookingService::confirmRequest). Chi l'ha mandata viene avvisato e
 * puo' rifarla in un clic.
 *
 * PERCHE' `expired` E NON `rejected`. Sono due fatti diversi: «ha detto no» e
 * «non ha risposto». Scriverli con la stessa parola vorrebbe dire raccontare al
 * creditore un rifiuto che non c'e' mai stato. Lo stato e' una stringa libera
 * (transfers.status), quindi non serve nessuna migrazione; le viste che
 * mostrano lo stato hanno tutte un ramo `default` — l'etichetta italiana
 * «Scaduta» e' stata aggiunta dove serviva.
 */
class ExpireCollectionRequests extends Command
{
    protected $signature = 'incassi:scadi-richieste
                            {--giorni=15 : Dopo quanti giorni una richiesta senza risposta scade}
                            {--dry-run : Elenca soltanto, senza chiudere niente}';

    protected $description = 'Fa scadere le richieste di incasso rimaste in attesa oltre la finestra prevista';

    public function handle(): int
    {
        $giorni = max(1, (int) $this->option('giorni'));
        $prova  = (bool) $this->option('dry-run');

        $richieste = Transfer::query()
            ->where('status', 'pending')
            ->where('kind', 'portal_collection_request')
            ->where('created_at', '<=', now()->subDays($giorni))
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser'])
            ->get();

        if ($richieste->isEmpty()) {
            $this->info('Nessuna richiesta di incasso da far scadere.');

            return self::SUCCESS;
        }

        foreach ($richieste as $richiesta) {
            $this->line(sprintf(
                '  %s  %s KY  da %s a %s  (%s)',
                $richiesta->uuid,
                ky_format($richiesta->amount),
                $richiesta->toAccount?->display_name ?? '?',
                $richiesta->fromAccount?->display_name ?? '?',
                $richiesta->created_at?->format('d/m/Y H:i'),
            ));

            if ($prova) {
                continue;
            }

            // Il lock e il ricontrollo dello stato dentro la transazione: fra
            // la lettura dell'elenco e questo punto il debitore puo' aver
            // confermato. Senza il ricontrollo, un pagamento appena
            // contabilizzato tornerebbe indietro a «scaduta» con le sue due
            // scritture contabili ancora al loro posto.
            $scaduta = DB::transaction(function () use ($richiesta) {
                $fresca = Transfer::query()->lockForUpdate()->find($richiesta->id);

                if (! $fresca || $fresca->status !== 'pending') {
                    return false;
                }

                $fresca->forceFill(['status' => 'expired'])->save();

                AuditLog::create([
                    'actor_user_id'  => null,
                    'event'          => 'transfer.request_expired',
                    'auditable_type' => Transfer::class,
                    'auditable_id'   => $fresca->id,
                    'ip_address'     => null,
                    'context'        => [
                        'from_account_id' => $fresca->from_account_id,
                        'to_account_id'   => $fresca->to_account_id,
                        'amount'          => $fresca->amount,
                        'giorni'          => (int) $this->option('giorni'),
                    ],
                ]);

                return true;
            });

            if (! $scaduta) {
                $this->line('    (nel frattempo era stata confermata o rifiutata: lasciata com\'era)');

                continue;
            }

            // Avvisa il creditore, cioe' chi la richiesta l'ha mandata.
            $toAccount = $richiesta->toAccount;
            $creditore = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();

            if ($creditore && $toAccount && $richiesta->fromAccount) {
                $creditore->notify(new PaymentRequestExpiredNotification(
                    transfer: $richiesta,
                    fromAccount: $richiesta->fromAccount,
                    toAccount: $toAccount,
                    giorni: $giorni,
                ));
            }
        }

        $this->info($prova
            ? $richieste->count() . ' richieste scadrebbero (dry-run).'
            : 'Richieste esaminate: ' . $richieste->count() . '.');

        return self::SUCCESS;
    }
}
