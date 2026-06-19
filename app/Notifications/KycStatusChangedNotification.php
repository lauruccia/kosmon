<?php

namespace App\Notifications;

use App\Models\Company;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * Notifica in-app (database) al cambio di stato KYC.
 * L'email è già gestita separatamente da KycStatusChanged Mailable.
 */
class KycStatusChangedNotification extends Notification
{
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly Company $company,
        public readonly string  $newStatus,  // 'under_review' | 'approved' | 'rejected'
        public readonly ?string $adminNotes = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'kyc_status', ['database'], ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return match ($this->newStatus) {
            'under_review' => [
                'icon'  => '🔍',
                'title' => 'Verifica KYC in corso',
                'body'  => 'I tuoi documenti sono in fase di revisione. Ti avviseremo appena completata.',
                'link'  => route('onboarding.step3'),
            ],
            'approved' => [
                'icon'  => '✅',
                'title' => 'Account approvato!',
                'body'  => 'La verifica KYC è stata completata. Puoi ora accedere al circuito KMoney.',
                'link'  => route('portal.dashboard'),
            ],
            'rejected' => [
                'icon'  => '❌',
                'title' => 'Verifica KYC non approvata',
                'body'  => $this->adminNotes
                    ? 'Motivo: ' . $this->adminNotes . ' — Puoi caricare nuovi documenti.'
                    : 'La verifica non è stata approvata. Puoi caricare nuovi documenti.',
                'link'  => route('onboarding.step2'),
            ],
            default => [
                'icon'  => 'ℹ️',
                'title' => 'Aggiornamento pratica KYC',
                'body'  => 'Lo stato della tua verifica è stato aggiornato.',
                'link'  => route('onboarding.step3'),
            ],
        };
    }
}
