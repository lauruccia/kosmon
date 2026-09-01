<?php

use App\Jobs\ProcessDueInstallments;
use App\Jobs\ExpirePaymentRequests;
use App\Jobs\RemindPaymentRequests;
use App\Jobs\CheckBalanceAlerts;
use App\Jobs\SendMonthlyStatements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Processa le rate scadute ogni giorno alle 06:00
Schedule::job(new ProcessDueInstallments())->dailyAt('06:00')->name('process-due-installments')->withoutOverlapping();

// Scade le PaymentRequest (QR dinamico) ogni minuto
Schedule::job(new ExpirePaymentRequests())->everyMinute()->name('expire-payment-requests')->withoutOverlapping();

// Promemoria scadenza richieste di pagamento (24h e 1h prima) — ogni 5 minuti
Schedule::job(new RemindPaymentRequests())->everyFiveMinutes()->name('remind-payment-requests')->withoutOverlapping();

// Esegue i pagamenti programmati ogni minuto — comando diretto, no coda, no mutex
Schedule::command('payments:run-scheduled')->everyMinute()->withoutOverlapping(5)->appendOutputTo(storage_path('logs/payments-scheduled.log'));

// Resoconto mensile il 1 del mese alle 08:00
Schedule::job(new SendMonthlyStatements())->monthlyOn(1, '08:00')->name('send-monthly-statements')->withoutOverlapping();

// Controlla gli avvisi saldo ogni ora
Schedule::job(new CheckBalanceAlerts())->hourly()->name('check-balance-alerts')->withoutOverlapping();

// MLM: rileva gli agenti diventati BasiQ (12 punti entro 30gg dall'attivazione) - ogni notte
// Tutti i job MLM sono condizionati a config('kmoney.mlm_enabled') (env
// MLM_ENABLED): su installazioni con MLM disattivato non girano mai, anche
// se lo scheduler resta lo stesso ovunque.
Schedule::command('mlm:recalculate-points')
    ->dailyAt('03:00')
    ->name('mlm-recalculate-points')
    ->withoutOverlapping()
    ->when(fn () => config('kmoney.mlm_enabled'))
    ->appendOutputTo(storage_path('logs/mlm-points.log'));

// NB (2026-07-29): questo orario (02:00) resta PRIMA di mlm:recalculate-points
// (03:00) qui sopra, quindi sullo stesso giorno 1 l'ordine del cron da solo
// non garantirebbe qualifiche aggiornate al momento del calcolo. Non e' un
// problema: CalculateMlmCommissions ora chiama internamente
// mlm:recalculate-points come primo passo, quindi il calcolo commissioni usa
// sempre lo stato di fatto del giorno di calcolo indipendentemente
// dall'orario effettivo del cron (utile anche perche' in produzione il cron
// non e' sempre configurato, vedi i pulsanti admin "applica subito").
Schedule::command('mlm:calculate-commissions')
    ->monthlyOn(1, '02:00')
    ->name('mlm-calculate-commissions')
    ->withoutOverlapping()
    ->when(fn () => config('kmoney.mlm_enabled'))
    ->appendOutputTo(storage_path('logs/mlm-commissions.log'));

// MLM: calcola e accredita i bonus settimanali (cascata di struttura, bonus
// diretti, extra bonus grado) - ogni mercoledi' (3 = Wednesday), come da
// disegno originale MLM_PROPOSAL.md §9. Il rilevamento (BasiQ, qualifiche)
// resta nel job giornaliero sopra; qui si calcolano/accreditano solo gli
// importi EUR.
Schedule::command('mlm:calculate-weekly-bonuses')
    ->weeklyOn(3, '04:00')
    ->name('mlm-calculate-weekly-bonuses')
    ->withoutOverlapping()
    ->when(fn () => config('kmoney.mlm_enabled'))
    ->appendOutputTo(storage_path('logs/mlm-bonuses.log'));

// Verifica integrità contabile COMPLETA ogni notte alle 02:00 (controlli pesanti)
Schedule::command('accounting:verify-integrity')
    ->dailyAt('02:00')
    ->name('accounting-integrity-check')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/accounting-integrity.log'));

// Verifica RAPIDA oraria del solo invariante somma-saldi=0 (rilevamento veloce
// dei disallineamenti + heartbeat che alimenta il dead-man's switch in /health)
Schedule::command('accounting:verify-integrity --quick')
    ->hourly()
    ->name('accounting-integrity-quick')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/accounting-integrity.log'));

// Processa la coda ogni minuto (compatibile con hosting shared senza supervisor)
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()
    ->name('queue-worker')
    ->withoutOverlapping(2)
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// Monitora la contesa sul lock del conto sistema (fee/cashback) — segnala via
// log+Sentry quando ci si avvicina alla soglia di serializzazione documentata in
// TransferBookingService::bookFee(). Sola lettura: nessun impatto su saldi/lock.
Schedule::command('accounting:check-contention')
    ->everyFifteenMinutes()
    ->name('accounting-contention-check')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/accounting-contention.log'));

// Solleciti della quota in euro (fase C, 27/08/2026).
//
// Una volta al giorno, di mattina: gli ordini fermi in attesa del pagamento in
// euro vengono sollecitati UNA VOLTA SOLA (`orders.euro_reminder_sent_at`).
// L'orario non è casuale: una email che arriva alle 9 si legge, una che arriva
// alle 3 di notte finisce sepolta sotto quelle del mattino.
Schedule::command('shop:solleciti-quota-euro')
    ->dailyAt('09:00')
    ->name('shop-euro-quota-reminders')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/shop-solleciti.log'));

// Quota di iscrizione dei privati (01/09/2026).
//
// Due comandi diversi che non vanno confusi:
//
//   - `quote:scadi-tentativi` chiude le righe di pagamento rimaste appese
//     (un click su "paga con carta" e poi piu' niente). Di notte, quando non
//     c'e' nessuno a meta' di un checkout. Non tocca MAI i bonifici, che
//     aspettare e' il loro mestiere, e chiudere un tentativo non impedisce
//     di accreditarlo se il pagamento arriva lo stesso: webhook e pagina di
//     successo accreditano qualunque riga non saldata purche' Stripe
//     confermi l'incasso.
//   - `quote:solleciti-iscrizione` scrive alle PERSONE che non hanno ancora
//     saldato, una volta sola in tutto. Alle 9, per lo stesso motivo del
//     sollecito qui sopra: una mail delle 3 di notte non la legge nessuno.
Schedule::command('quote:scadi-tentativi')
    ->dailyAt('04:30')
    ->name('registration-fee-expire-attempts')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/quote-iscrizione.log'));

Schedule::command('quote:solleciti-iscrizione')
    ->dailyAt('09:15')
    ->name('registration-fee-reminders')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/quote-iscrizione.log'));
