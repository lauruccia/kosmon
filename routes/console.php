<?php

use App\Jobs\ProcessDueInstallments;
use App\Jobs\ExpirePaymentRequests;
use App\Jobs\ProcessScheduledPayments;
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

// Esegue i pagamenti programmati ogni minuto — comando diretto, no coda, no mutex
Schedule::command('payments:run-scheduled')->everyMinute()->withoutOverlapping(5)->appendOutputTo(storage_path('logs/payments-scheduled.log'));

// Resoconto mensile il 1 del mese alle 08:00
Schedule::job(new SendMonthlyStatements())->monthlyOn(1, '08:00')->name('send-monthly-statements')->withoutOverlapping();

// Controlla gli avvisi saldo ogni ora
Schedule::job(new CheckBalanceAlerts())->hourly()->name('check-balance-alerts')->withoutOverlapping();
