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

class PaymentRequestConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Transfer $transfer,
        public readonly Account $fromAccount,
        public readonly Account $toAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: 'Richiesta confermata — ' . ky_format($this->transfer->amount) . ' KY ricevuti',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request-confirmed',
            with: [
                'emailType'   => 'Richiesta confermata',
                'emailTitle'  => 'Il tuo incasso è stato confermato',
                'recipient'   => $this->recipient,
                'transfer'    => $this->transfer,
                'fromAccount' => $this->fromAccount,
                'toAccount'   => $this->toAccount,
            ],
        );
    }
}
