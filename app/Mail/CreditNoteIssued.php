<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreditNoteIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User      $recipient,
        public readonly Transfer  $creditNote,
        public readonly Account   $fromAccount,
        public readonly Account   $toAccount,
        public readonly int       $balanceAfter,
        public readonly ?Transfer $originalTransfer = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: 'Nota di credito ricevuta: ' . ky_format($this->creditNote->amount) . ' KY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-received',
            with: [
                'emailType'    => 'Nota di credito',
                'emailTitle'   => 'Hai ricevuto una nota di credito',
                'recipient'    => $this->recipient,
                'transfer'     => $this->creditNote,
                'fromAccount'  => $this->fromAccount,
                'toAccount'    => $this->toAccount,
                'balanceAfter' => $this->balanceAfter,
            ],
        );
    }
}
