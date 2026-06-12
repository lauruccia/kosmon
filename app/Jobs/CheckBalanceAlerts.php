<?php

namespace App\Jobs;

use App\Models\BalanceAlert;
use App\Notifications\BalanceThresholdNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckBalanceAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        BalanceAlert::where('is_active', true)
            ->with(['account.ownerUser'])
            ->chunk(200, function ($alerts) {
                foreach ($alerts as $alert) {
                    $account = $alert->account;
                    if (! $account) {
                        continue;
                    }

                    $balance = $account->available_balance; // centesimi

                    // Saldo tornato sopra soglia: resetta lo stato di allerta
                    if ($balance >= $alert->threshold_amount && $alert->is_in_alert) {
                        $alert->update(['is_in_alert' => false]);
                        continue;
                    }

                    // Saldo sotto soglia: notifica solo se non siamo già in stato di allerta
                    if ($balance < $alert->threshold_amount && $alert->canTrigger()) {
                        $user = $account->ownerUser;
                        if ($user) {
                            try {
                                $user->notify(new BalanceThresholdNotification($alert, $balance));
                            } catch (\Throwable $e) {
                                Log::warning("CheckBalanceAlerts: notifica fallita per alert {$alert->id}", [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        $alert->update([
                            'last_triggered_at' => now(),
                            'is_in_alert'       => true,
                        ]);
                    }
                }
            });
    }
}
