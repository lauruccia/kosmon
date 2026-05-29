<?php

namespace App\Notifications\Concerns;

trait RespectsNotificationPreferences
{
    protected function resolveChannels(object $notifiable, string $eventKey, array $defaultChannels, array $allowed = ['database', 'mail']): array
    {
        $prefs    = $notifiable->notification_preferences ?? null;
        $channels = is_array($prefs) && array_key_exists($eventKey, $prefs)
            ? $prefs[$eventKey]
            : $defaultChannels;

        return array_values(array_intersect($channels, $allowed));
    }
}
