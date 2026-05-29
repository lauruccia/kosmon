<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * SendWebPushAfterNotification
 *
 * Si aggancia all'evento NotificationSent: ogni volta che una notifica
 * viene salvata nel canale 'database', invia anche una Web Push al
 * dispositivo dell'utente (se ha una subscription attiva).
 *
 * Non modifica le singole classi Notification — basta aggiungere
 * questo listener al corrispondente evento in EventServiceProvider.
 */
class SendWebPushAfterNotification
{
    public function __construct(private readonly WebPushService $pushService)
    {}

    public function handle(NotificationSent $event): void
    {
        // Solo canale database; altri canali (mail, ecc.) li gestiamo altrove
        if ($event->channel !== 'database') {
            return;
        }

        // Il notificabile deve essere un User
        if (! ($event->notifiable instanceof User)) {
            return;
        }

        $notifiable = $event->notifiable;

        // Recupera i dati dell'array (title, body, link, icon)
        $data = method_exists($event->notification, 'toArray')
            ? $event->notification->toArray($notifiable)
            : [];

        $title = $data['title'] ?? 'KMoney';
        $body  = $data['body']  ?? '';
        $url   = $data['link']  ?? '/dashboard';

        // Rimuovi eventuali emoji dal titolo per compatibilita' con tutti i browser
        $title = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $title);
        $title = trim($title) ?: 'KMoney';

        $this->pushService->notifyUser($notifiable, $title, $body, [
            'url' => $url,
            'tag' => class_basename($event->notification),
        ]);
    }
}
