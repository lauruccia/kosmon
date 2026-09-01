<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\PaymentPlanInstallment;
use App\Models\Transfer;
use App\Notifications\MonthlyStatementNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Il resoconto mensile, il 1 del mese alle 08:00.
 *
 * PERCHE' GLI INVII SONO DILUITI (01/09/2026). Fino a ieri questo job faceva
 * un `foreach` su tutti i conti e chiamava `notify()` per ciascuno: mille e
 * passa notifiche tutte disponibili nello stesso istante, che il worker
 * spediva una dietro l'altra il piu' in fretta possibile.
 *
 * Il 1 luglio 2026 e' andata esattamente cosi', e in `failed_jobs` sono
 * rimaste **1068 righe di quel solo giorno**, 1060 delle quali con
 * `Symfony\Component\Mailer\Exception\UnexpectedResponse`: il server di posta
 * le ha rifiutate in blocco. Gli hosting condivisi hanno un tetto orario di
 * invio, e chi lo supera non viene rallentato — viene respinto. Il resoconto
 * di luglio non e' arrivato a nessuno.
 *
 * Da qui il freno: `kmoney.mail_max_per_hour`. Ogni notifica parte con un
 * ritardo crescente, cosi' la coda si svuota al ritmo che la posta regge
 * invece che tutta insieme. Per un resoconto mensile ricevere l'email alle 9
 * o alle 15 non cambia niente; riceverla o non riceverla si'.
 *
 * CHI LO RICEVE (deciso da Laura il 01/09/2026): TUTTI, privati compresi.
 * Prima un `whereHas('company')` lo riservava alle sole aziende, e i conti
 * dei privati — la maggior parte del circuito — non lo ricevevano affatto.
 * Il volume triplica, ed e' il motivo per cui il job parte a mezzanotte: con
 * il tetto orario, le email dell'ultimo destinatario devono avere il tempo di
 * uscire prima di sera.
 */
class SendMonthlyStatements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $prevMonth  = Carbon::now()->subMonth();
        $monthStart = $prevMonth->copy()->startOfMonth();
        $monthEnd   = $prevMonth->copy()->endOfMonth();
        $label      = $prevMonth->locale('it')->translatedFormat('F Y');

        $perOra   = max(0, (int) config('kmoney.mail_max_per_hour', 150));
        $spedite  = 0;
        $esaminati = 0;

        // chunkById e non get(): duemila conti con azienda e intestatario
        // caricati tutti insieme sono un picco di memoria inutile su un
        // hosting condiviso, ed e' proprio il momento in cui il server ha
        // gia' da fare.
        Account::query()
            ->whereNull('parent_account_id')
            // Il conto deve essere vivo: mandare il rendiconto di un conto
            // chiuso non informa nessuno di niente.
            ->where('status', 'active')
            ->with(['company', 'ownerUser'])
            // CHI RICEVE (deciso da Laura il 01/09/2026: TUTTI).
            //
            // Fino a oggi c'era un `whereHas('company')` secco, e la
            // conseguenza era che il resoconto mensile arrivava SOLO alle
            // aziende: i conti dei privati — la maggior parte del circuito —
            // non lo ricevevano affatto, e nessuno se n'era accorto perche'
            // dal 1 luglio non partiva comunque niente (vedi il cron morto).
            //
            // Ora: un conto senza azienda (il privato) entra sempre; un conto
            // aziendale entra solo se il KYC dell'azienda e' approvato, che e'
            // la regola di prima e resta. `whereDoesntHave` e non
            // `whereNull('company_id')`: copre anche il conto che punta a
            // un'azienda che non c'e' piu'.
            ->where(fn ($q) => $q
                ->whereDoesntHave('company')
                ->orWhereHas('company', fn ($c) => $c->where('kyc_status', 'approved')))
            ->chunkById(200, function (Collection $accounts) use (
                $monthStart, $monthEnd, $label, $perOra, &$spedite, &$esaminati
            ): void {
                foreach ($accounts as $account) {
                    $esaminati++;

                    $user = $account->ownerUser ?? $account->company?->users()->first();
                    if (! $user) {
                        continue;
                    }

                    // Un utente disattivato non deve ricevere posta dal
                    // circuito. Conta doppio adesso che entrano anche i
                    // privati: fra gli iscritti importati ce ne sono di
                    // spenti, e ogni email a un indirizzo morto e' un rimbalzo
                    // che pesa sulla reputazione del dominio.
                    if (! $user->is_active) {
                        continue;
                    }

                    // Controlla preferenza opt-in
                    $prefs    = $user->notification_preferences ?? [];
                    $channels = $prefs['monthly_statement'] ?? ['mail'];
                    if (empty($channels)) {
                        continue;
                    }

                    $income  = (int) Transfer::where('to_account_id', $account->id)
                        ->where('status', 'booked')
                        ->whereBetween('booked_at', [$monthStart, $monthEnd])
                        ->sum('amount');

                    $expense = (int) Transfer::where('from_account_id', $account->id)
                        ->where('status', 'booked')
                        ->whereBetween('booked_at', [$monthStart, $monthEnd])
                        ->sum('amount');

                    // Rate in scadenza prossimi 7 giorni
                    $dueSoon = (int) PaymentPlanInstallment::query()
                        ->whereHas('paymentPlan', fn ($q) => $q->where('from_account_id', $account->id)->where('status', 'active'))
                        ->where('status', 'pending')
                        ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                        ->count();

                    $notifica = new MonthlyStatementNotification($account, [
                        'month_label'      => $label,
                        'balance'          => $account->available_balance,
                        'income'           => $income,
                        'expense'          => $expense,
                        'due_installments' => $dueSoon,
                    ]);

                    // Il ritardo si calcola sulle email GIA' messe in coda, non
                    // sui conti esaminati: chi viene saltato non deve lasciare
                    // un buco nella cadenza.
                    $attesa = $this->attesaPer($spedite, $perOra);
                    if ($attesa > 0) {
                        $notifica->delay(now()->addSeconds($attesa));
                    }

                    try {
                        $user->notify($notifica);
                        $spedite++;
                    } catch (\Throwable $e) {
                        Log::warning('[SendMonthlyStatements] account #' . $account->id . ': ' . $e->getMessage());
                    }
                }
            });

        Log::info(sprintf(
            '[SendMonthlyStatements] %s: %d conti esaminati, %d email in coda a %s. Ultima consegna prevista fra %d minuti.',
            $label,
            $esaminati,
            $spedite,
            $perOra > 0 ? $perOra . '/ora' : 'ritmo libero',
            (int) round($this->attesaPer(max(0, $spedite - 1), $perOra) / 60),
        ));
    }

    /**
     * Fra quanti secondi deve partire la n-esima email.
     *
     * Cadenza costante e non "a scaglioni ogni ora": mille email sparate
     * insieme allo scoccare di ogni ora sarebbero lo stesso picco di prima,
     * solo ripetuto.
     */
    private function attesaPer(int $indice, int $perOra): int
    {
        if ($perOra <= 0) {
            return 0;
        }

        return (int) floor($indice * 3600 / $perOra);
    }
}
