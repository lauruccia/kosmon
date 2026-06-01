<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationPreferencesController extends Controller
{
    private function resolveAccount(\App\Models\User $user): ?Account
    {
        if ($user->managed_account_id !== null) {
            return Account::with(['company', 'ownerUser'])->find($user->managed_account_id);
        }
        if ($user->company_id !== null) {
            return Account::query()
                ->with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->first();
        }
        return Account::query()
            ->with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    /**
     * All manageable notification events with their default channels.
     */
    public static function events(): array
    {
        return [
            'payment_received'        => ['label' => 'Pagamento ricevuto',                  'default' => ['database', 'mail']],
            'payment_sent'            => ['label' => 'Pagamento inviato (conferma)',         'default' => ['database']],
            'cashback_received'       => ['label' => 'Cashback accreditato',                'default' => ['database', 'mail']],
            'credit_limit'            => ['label' => 'Fido (richiesta/approvazione)',        'default' => ['database', 'mail']],
            'installment_paid'        => ['label' => 'Rata piano rateale eseguita',         'default' => ['database']],
            'installment_failed'      => ['label' => 'Rata piano rateale fallita',          'default' => ['database', 'mail']],
            'payment_plan_proposed'   => ['label' => 'Piano rateale proposto',              'default' => ['database']],
            'payment_plan_approved'   => ['label' => 'Piano rateale approvato/rifiutato',   'default' => ['database']],
            'netting_proposed'        => ['label' => 'Compensazione proposta',              'default' => ['database']],
            'netting_accepted'        => ['label' => 'Compensazione accettata/rifiutata',   'default' => ['database']],
            'text_request_sent'       => ['label' => 'Richiesta pagamento testuale',        'default' => ['database']],
            'text_request_approved'   => ['label' => 'Richiesta testo approvata/rifiutata', 'default' => ['database']],
            'scheduled_payment'       => ['label' => 'Pagamento programmato eseguito',      'default' => ['database', 'mail']],
            'payment_request'         => ['label' => 'QR/NFC incasso pagato',              'default' => ['database', 'mail']],
            'new_ip_login'            => ['label' => 'Accesso da nuovo IP (sicurezza)',     'default' => ['mail']],
            'announcement_reply'      => ['label' => 'Risposta ad annuncio',               'default' => ['database']],
            'monthly_statement'       => ['label' => 'Resoconto mensile via email',         'default' => ['mail']],
            'kycard_credited'         => ['label' => 'Ricarica KY accreditata',             'default' => ['database', 'mail']],
        ];
    }

    public function index(Request $request): View
    {
        $user  = $request->user();
        $prefs = $user->notification_preferences ?? [];
        $events = self::events();

        return view('portal.notification-preferences', [
            'pageTitle'  => 'Preferenze notifiche',
            'activeNav'  => 'settings',
            'events'     => $events,
            'prefs'      => $prefs,
            'currentAccount' => $this->resolveAccount($request->user()),
            'currentUser'    => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user   = $request->user();
        $events = self::events();
        $prefs  = [];

        foreach ($events as $key => $meta) {
            $channels = [];
            if ($request->boolean("event_{$key}_database")) {
                $channels[] = 'database';
            }
            if ($request->boolean("event_{$key}_mail")) {
                $channels[] = 'mail';
            }
            // Always persist the event key even if all channels are disabled
            $prefs[$key] = $channels;
        }

        $user->update(['notification_preferences' => $prefs]);

        return redirect()->route('portal.notification-preferences')
            ->with('success', 'Preferenze notifiche aggiornate.');
    }
}
