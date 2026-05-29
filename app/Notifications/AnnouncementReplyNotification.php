<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\AnnouncementReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AnnouncementReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Announcement      $announcement,
        public readonly AnnouncementReply $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '💬',
            'title' => 'Nuova risposta all\'annuncio',
            'body'  => sprintf(
                'Hai ricevuto una risposta all\'annuncio "%s".',
                \Illuminate\Support\Str::limit($this->announcement->title, 60),
            ),
            'link'  => route('portal.announcements.show', $this->announcement),
        ];
    }
}
