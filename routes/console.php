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

// Verifica integrità contabile ogni notte alle 02:00
Schedule::command('accounting:verify-integrity')
    ->dailyAt('02:00')
    ->name('accounting-integrity-check')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/accounting-integrity.log'));

// Processa la coda ogni minuto (compatibile con hosting shared senza supervisor)
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()
    ->name('queue-worker')
    ->withoutOverlapping(2)
    ->appendOutputTo(storage_path('logs/queue-worker.log'));
