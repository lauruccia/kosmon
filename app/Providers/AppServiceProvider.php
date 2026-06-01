<?php

namespace App\Providers;

use App\Listeners\LogLoginActivity;
use App\Listeners\SendWebPushAfterNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Forza HTTPS quando in produzione (necessario dietro reverse proxy)
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Usa il template di paginazione custom nel design KMoney
        Paginator::defaultView('vendor.pagination.default');
        Paginator::defaultSimpleView('vendor.pagination.default');

        // Web Push: invia push dopo ogni notifica database
        Event::listen(NotificationSent::class, SendWebPushAfterNotification::class);

        // Login activity log + alert nuovo IP
        Event::listen(Login::class, LogLoginActivity::class);

        // Rate limiter per pagamenti sensibili
        // 15 req/min per utente autenticato, oppure per IP se ospite
        RateLimiter::for('payments', function ($request) {
            return $request->user()
                ? Limit::perMinute(15)->by($request->user()->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiter per generazione QR/NFC (meno critico, piu' permissivo)
        RateLimiter::for('incasso', function ($request) {
            return $request->user()
                ? Limit::perMinute(20)->by($request->user()->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiter per piani rateali e compensazioni (bassa frequenza attesa)
        RateLimiter::for('financial_ops', function ($request) {
            return $request->user()
                ? Limit::perMinute(10)->by($request->user()->id)
                : Limit::perMinute(3)->by($request->ip());
        });
    }
}
