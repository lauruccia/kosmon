<?php

namespace App\Mail;

use App\Models\MlmInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MlmInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly MlmInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->invitation->email,
            subject: $this->invitation->agent->name . ' ti invita su KMoney',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mlm-invitation',
            with: [
                'emailType'   => 'Invito',
                'emailTitle'  => 'Sei stato invitato su KMoney',
                'invitation'  => $this->invitation,
                'agent'       => $this->invitation->agent,
                'registerUrl' => $this->invitation->agent->referralUrl(),
            ],
        );
    }
}
