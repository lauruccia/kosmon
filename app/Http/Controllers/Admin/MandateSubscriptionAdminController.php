<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MandateSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Backoffice — tutti gli abbonamenti che i portali del circuito addebitano ai
 * conti KMoney: portale, utente, importo, cadenza, stato, rinnovi.
 *
 * Sola lettura, di proposito: il blocco è una decisione dell'utente
 * (`blocked_at` lo scrive soltanto lui) e il portale è l'unico a poter
 * annullare o mettere in pausa. L'admin guarda e capisce; non decide al posto
 * di nessuno dei due.
 */
class MandateSubscriptionAdminController extends Controller
{
    public function index(Request $request): View
    {
        $appNames = $this->appNames();

        $base = MandateSubscription::query();

        $query = MandateSubscription::query()
            ->with('user')
            ->withCount('charges')
            ->withSum('charges', 'amount');

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        $stato = (string) $request->input('stato', '');
        match ($stato) {
            'blocked'   => $query->whereNotNull('blocked_at'),
            'active'    => $query->whereNull('blocked_at')->where('status', MandateSubscription::ACTIVE),
            'paused'    => $query->whereNull('blocked_at')->where('status', MandateSubscription::PAUSED),
            'cancelled' => $query->whereNull('blocked_at')->where('status', MandateSubscription::CANCELLED),
            'error'     => $query->whereNotNull('last_error'),
            default     => null,
        };

        if ($q = trim((string) $request->input('q', ''))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('title', 'like', $like)
                    ->orWhere('external_id', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like));
            });
        }

        // Riepilogo sull'insieme intero, non sulla pagina né sul filtro: è la
        // fotografia del circuito.
        $live = (clone $base)->whereNull('blocked_at')->where('status', MandateSubscription::ACTIVE);
        $monthly = (int) round((clone $live)->get(['amount', 'interval_months'])->sum(
            fn (MandateSubscription $s) => $s->amount !== null ? $s->amount / max(1, (int) $s->interval_months) : 0
        ));

        $summary = [
            'active'    => (clone $live)->count(),
            'paused'    => (clone $base)->whereNull('blocked_at')->where('status', MandateSubscription::PAUSED)->count(),
            'blocked'   => (clone $base)->whereNotNull('blocked_at')->count(),
            'cancelled' => (clone $base)->whereNull('blocked_at')->where('status', MandateSubscription::CANCELLED)->count(),
            'monthly'   => $monthly,
            'due_week'  => (clone $live)->whereNotNull('next_charge_at')->whereBetween('next_charge_at', [now(), now()->addDays(7)])->count(),
        ];

        return view('admin.mandate-subscriptions', [
            'pageTitle'     => 'Abbonamenti dei portali',
            'activeNav'     => 'admin-mandate-subscriptions',
            'subscriptions' => $query->orderByDesc('id')->paginate(50)->withQueryString(),
            'summary'       => $summary,
            'appNames'      => $appNames,
            'clientIds'     => MandateSubscription::query()->distinct()->pluck('client_id')->sort()->values(),
            'stato'         => $stato,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function appNames(): array
    {
        $names = [];

        foreach ((array) config('oauth.clients', []) as $client) {
            if (! empty($client['client_id'])) {
                $names[$client['client_id']] = (string) ($client['name'] ?? $client['client_id']);
            }
        }

        return $names;
    }
}
