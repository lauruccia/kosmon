<?php

namespace App\Mail;

use App\Models\Announcement;
use App\Models\AnnouncementReply;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnouncementReplyNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,         // il pubblicatore dell'annuncio
        public readonly Announcement $announcement,
        public readonly AnnouncementReply $reply,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: 'Nuova risposta al tuo annuncio: ' . $this->announcement->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.announcement-reply',
            with: [
                'emailType'    => 'Risposta annuncio',
                'emailTitle'   => 'Hai ricevuto una risposta',
                'recipient'    => $this->recipient,
                'announcement' => $this->announcement,
                'reply'        => $this->reply,
            ],
        );
    }
}
