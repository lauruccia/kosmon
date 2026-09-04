<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\CompanyAccountFeeReminderNotification;
use App\Notifications\RegistrationFeeReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sollecita, UNA VOLTA SOLA, chi ha una quota d'ingresso ancora da saldare:
 * i privati con la quota di iscrizione, e — dal 03/09/2026 — le aziende con la
 * quota di apertura conto.
 *
 * IL CASO CHE CHIUDE. Ci si registra, si vede il banner, si rimanda. Da quel
 * momento in poi il circuito non dice piu' niente: nessuna mail, nessun
 * promemoria. Chi si e' iscritto pensa di essere a posto, e non lo e'.
 *
 * PER LE AZIENDE PESA DI PIU'. La quota dei privati ha alle spalle un conto
 * bloccato: prima o poi l'utente ci sbatte contro da solo e capisce. Quella di
 * apertura conto no — decisione di Laura del 03/09, l'azienda continua a
 * operare — quindi questa mail e il banner sono gli unici due modi in cui il
 * circuito chiede davvero quei 600 euro. Vale la pena saperlo prima di
 * spegnere questo comando.
 *
 * LA QUOTA DEL CODICE AGENTE NON C'E', ed e' una scelta del 31/08 rimasta
 * valida: quel percorso lo si segue di persona.
 *
 * IL NOME DEL COMANDO E' RIMASTO `quote:solleciti-iscrizione` anche ora che le
 * quote sollecitate sono due. Rinominarlo avrebbe voluto dire toccare la
 * schedulazione su due server in produzione per un'etichetta: il rischio non
 * vale la parola.
 *
 * TRE SCELTE CHE VALE LA PENA CONOSCERE
 *
 * 1. **Una volta sola, per quota.** Un conto fermo per un mese genererebbe
 *    trenta mail identiche, e la trentesima non convince nessuno piu' della
 *    prima: fa solo finire il circuito nello spam. La mail stessa lo dichiara
 *    ("questo e' l'unico promemoria che riceverai"), che e' anche il modo di
 *    non dover scrivere un giorno un secondo sollecito.
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

    protected $description = 'Sollecita una volta sola chi ha la quota di iscrizione o quella di apertura conto da saldare';

    /**
     * Le quote sollecitate, con tutto cio' che cambia da una all'altra.
     * Aggiungerne una vuol dire aggiungere una riga qui, e nient'altro.
     *
     * @return list<array{etichetta:string, dovuti:string, saldata:string, evento:string, appenaAvvisati:list<string>, notifica:class-string}>
     */
    private function quote(): array
    {
        return [
            [
                'etichetta'      => 'Quota di iscrizione',
                'dovuti'         => 'registration_fee_due_cents',
                'saldata'        => 'registration_fee_paid_at',
                'evento'         => 'registration_fee.reminded',
                'appenaAvvisati' => [
                    'registration_fee.requested_by_admin',
                    'registration_fee.resumed_after_agent_path',
                ],
                'notifica'       => RegistrationFeeReminderNotification::class,
            ],
            [
                'etichetta'      => 'Quota apertura conto',
                'dovuti'         => 'company_account_fee_due_cents',
                'saldata'        => 'company_account_fee_paid_at',
                'evento'         => 'company_account_fee.reminded',
                'appenaAvvisati' => [
                    'company_account_fee.requested_by_admin',
                ],
                'notifica'       => CompanyAccountFeeReminderNotification::class,
            ],
        ];
    }

    public function handle(): int
    {
        $giorni = max(1, (int) $this->option('giorni'));
        $prova  = (bool) $this->option('dry-run');

        $trovati = 0;
        $mandati = 0;

        foreach ($this->quote() as $quota) {
            $utenti = User::query()
                ->whereNotNull($quota['dovuti'])
                // `> 0` e non solo "non nullo": lo zero e' un terzo stato
                // (quota sospesa o esonerata, a seconda della quota) e non si
                // sollecita.
                ->where($quota['dovuti'], '>', 0)
                ->whereNull($quota['saldata'])
                ->where('created_at', '<=', now()->subDays($giorni))
                ->whereNotExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('audit_logs')
                    ->whereColumn('audit_logs.auditable_id', 'users.id')
                    ->where('audit_logs.auditable_type', User::class)
                    ->where('audit_logs.event', $quota['evento']))
                ->whereNotExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('audit_logs')
                    ->whereColumn('audit_logs.auditable_id', 'users.id')
                    ->where('audit_logs.auditable_type', User::class)
                    ->whereIn('audit_logs.event', $quota['appenaAvvisati'])
                    ->where('audit_logs.created_at', '>=', now()->subDays($giorni)))
                ->orderBy('id')
                ->get();

            if ($utenti->isEmpty()) {
                continue;
            }

            $this->line($quota['etichetta'] . ':');
            $trovati += $utenti->count();

            foreach ($utenti as $utente) {
                $importo = (int) $utente->{$quota['dovuti']};

                $this->line(sprintf(
                    '  %s  %s  iscritto il %s',
                    str_pad(ky_format($importo) . ' KY', 14),
                    $utente->email,
                    $utente->created_at?->format('d/m/Y'),
                ));

                if ($prova) {
                    continue;
                }

                try {
                    $notifica = $quota['notifica'];
                    $utente->notify(new $notifica($importo));

                    // Scritto DOPO l'invio: se la mail non parte, il sollecito
                    // non e' avvenuto e domani si riprova. Scriverlo prima
                    // vorrebbe dire bruciare l'unica occasione su un invio
                    // fallito.
                    AuditLog::create([
                        'actor_user_id'  => null,
                        'event'          => $quota['evento'],
                        'auditable_type' => User::class,
                        'auditable_id'   => $utente->id,
                        'context'        => ['amount' => $importo],
                    ]);

                    $mandati++;
                } catch (\Throwable $e) {
                    // Un indirizzo sbagliato non deve fermare la coda degli altri.
                    Log::warning('Quote: sollecito non inviato', [
                        'quota' => $quota['evento'],
                        'user'  => $utente->id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->warn('  non inviato: ' . $e->getMessage());
                }
            }
        }

        if ($trovati === 0) {
            $this->info('Nessuno da sollecitare.');

            return self::SUCCESS;
        }

        $this->info($prova
            ? $trovati . ' solleciti verrebbero inviati (dry-run).'
            : $mandati . ' solleciti inviati.');

        return self::SUCCESS;
    }
}
