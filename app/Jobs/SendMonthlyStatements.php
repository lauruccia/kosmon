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
 * NON e' stato toccato CHI riceve il resoconto: la selezione dei conti e le
 * preferenze di notifica sono identiche a prima. Cambia solo QUANDO parte
 * ciascuna email. (Nota per un domani: quel `whereHas('company')` esclude i
 * conti dei privati, che quindi il resoconto non lo ricevono affatto. Se e'
 * una svista va corretta a parte, perche' allargherebbe il volume e va
 * deciso insieme al tetto orario.)
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

        // chunkById e non get(): mille conti con azienda e intestatario
        // caricati tutti insieme sono un picco di memoria inutile su un
        // hosting condiviso, ed e' proprio il momento in cui il server ha
        // gia' da fare.
        Account::query()
            ->whereNull('parent_account_id')
            ->with(['company', 'ownerUser'])
            ->whereHas('company', fn ($q) => $q->where('kyc_status', 'approved'))
            ->chunkById(200, function (Collection $accounts) use (
                $monthStart, $monthEnd, $label, $perOra, &$spedite, &$esaminati
            ): void {
                foreach ($accounts as $account) {
                    $esaminati++;

                    $user = $account->ownerUser ?? $account->company?->users()->first();
                    if (! $user) {
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
