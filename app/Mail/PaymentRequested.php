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

class PaymentRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Transfer $transfer,
        public readonly Account $fromAccount,
        public readonly Account $toAccount,
        public readonly string $requesterName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: $this->requesterName . ' ti ha richiesto ' . ky_format($this->transfer->amount) . ' KY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-requested',
            with: [
                'emailType'     => 'Richiesta pagamento',
                'emailTitle'    => 'Hai una nuova richiesta di pagamento',
                'recipient'     => $this->recipient,
                'transfer'      => $this->transfer,
                'fromAccount'   => $this->fromAccount,
                'toAccount'     => $this->toAccount,
                'requesterName' => $this->requesterName,
            ],
        );
    }
}
