<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RegistrationFeeReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sollecita, UNA VOLTA SOLA, chi ha la quota di iscrizione ancora da saldare.
 *
 * IL CASO CHE CHIUDE. Ci si registra, si vede il banner rosso, si rimanda. Da
 * quel momento in poi il circuito non dice piu' niente: nessuna mail, nessun
 * promemoria, e un conto aperto che non puo' pagare, incassare o comprare.
 * Chi si e' iscritto pensa di essere dentro, e non lo e'.
 *
 * TRE SCELTE CHE VALE LA PENA CONOSCERE
 *
 * 1. **Una volta sola.** Un conto fermo per un mese genererebbe trenta mail
 *    identiche, e la trentesima non convince nessuno piu' della prima: fa solo
 *    finire il circuito nello spam. La mail stessa lo dichiara ("questo e'
 *    l'unico promemoria che riceverai"), che e' anche il modo di non dover
 *    scrivere un giorno un secondo sollecito.
 *
 * 2. **La memoria sta nell'AUDIT LOG, non in una colonna nuova e non nella
 *    tabella delle notifiche.** Non in una colonna perche' avrebbe voluto dire
 *    una migrazione su due server con database diversi, per una riga sola;
 *    non in `notifications` perche' quella si popola solo finche' l'utente
 *    tiene acceso il canale `database` — chi lo spegne verrebbe sollecitato
 *    ogni notte, cioe' proprio chi ha gia' detto che vuole meno messaggi.
 *    L'audit log e' nostro, non lo governa l'utente, e sopravvive a
 *    qualunque cambio di canale.
 *
 * 3. **Chi e' stato appena avvisato non si sollecita.** L'admin che mette la
 *    quota in carico, e la quota che si accende dopo una rinuncia al percorso
 *    agente, mandano gia' la loro mail: un promemoria il giorno dopo sarebbe
 *    la stessa cosa detta due volte a distanza di ore.
 */
class RemindRegistrationFees extends Command
{
    protected $signature = 'quote:solleciti-iscrizione
                            {--giorni=5 : Dopo quanti giorni dalla registrazione sollecitare}
                            {--dry-run : Elenca soltanto, senza mandare niente}';

    protected $description = 'Sollecita una volta sola i privati con la quota di iscrizione da saldare';

    /** Eventi che significano "gliel'abbiamo appena detto". */
    private const APPENA_AVVISATI = [
        'registration_fee.requested_by_admin',
        'registration_fee.resumed_after_agent_path',
    ];

    public function handle(): int
    {
        $giorni = max(1, (int) $this->option('giorni'));
        $prova  = (bool) $this->option('dry-run');

        $utenti = User::query()
            ->whereNotNull('registration_fee_due_cents')
            // `> 0` e non solo "non nullo": lo zero e' la quota SOSPESA di chi
            // e' entrato dalla porta dell'agente, e quella non si sollecita.
            ->where('registration_fee_due_cents', '>', 0)
            ->whereNull('registration_fee_paid_at')
            ->where('created_at', '<=', now()->subDays($giorni))
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('audit_logs')
                ->whereColumn('audit_logs.auditable_id', 'users.id')
                ->where('audit_logs.auditable_type', User::class)
                ->where('audit_logs.event', 'registration_fee.reminded'))
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('audit_logs')
                ->whereColumn('audit_logs.auditable_id', 'users.id')
                ->where('audit_logs.auditable_type', User::class)
                ->whereIn('audit_logs.event', self::APPENA_AVVISATI)
                ->where('audit_logs.created_at', '>=', now()->subDays($giorni)))
            ->orderBy('id')
            ->get();

        if ($utenti->isEmpty()) {
            $this->info('Nessuno da sollecitare.');

            return self::SUCCESS;
        }

        $mandati = 0;

        foreach ($utenti as $utente) {
            $importo = (int) $utente->registration_fee_due_cents;

            $this->line(sprintf(
                '%s  %s  iscritto il %s',
                str_pad(ky_format($importo) . ' KY', 14),
                $utente->email,
                $utente->created_at?->format('d/m/Y'),
            ));

            if ($prova) {
                continue;
            }

            try {
                $utente->notify(new RegistrationFeeReminderNotification($importo));

                // Scritto DOPO l'invio: se la mail non parte, il sollecito non
                // e' avvenuto e domani si riprova. Scriverlo prima vorrebbe
                // dire bruciare l'unica occasione su un invio fallito.
                AuditLog::create([
                    'actor_user_id'  => null,
                    'event'          => 'registration_fee.reminded',
                    'auditable_type' => User::class,
                    'auditable_id'   => $utente->id,
                    'context'        => ['amount' => $importo],
                ]);

                $mandati++;
            } catch (\Throwable $e) {
                // Un indirizzo sbagliato non deve fermare la coda degli altri.
                Log::warning('Quota iscrizione: sollecito non inviato', [
                    'user'  => $utente->id,
                    'error' => $e->getMessage(),
                ]);

                $this->warn('  non inviato: ' . $e->getMessage());
            }
        }

        $this->info($prova
            ? count($utenti) . ' solleciti verrebbero inviati (dry-run).'
            : $mandati . ' solleciti inviati.');

        return self::SUCCESS;
    }
}
