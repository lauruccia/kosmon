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

class PaymentReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Transfer $transfer,
        public readonly Account $fromAccount,
        public readonly Account $toAccount,
        public readonly int $balanceAfter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: 'Hai ricevuto ' . number_format($this->transfer->amount, 2, ',', '.') . ' KY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-received',
            with: [
                'emailType'    => 'Pagamento ricevuto',
                'emailTitle'   => 'Nuovo accredito sul tuo conto',
                'recipient'    => $this->recipient,
                'transfer'     => $this->transfer,
                'fromAccount'  => $this->fromAccount,
                'toAccount'    => $this->toAccount,
                'balanceAfter' => $this->balanceAfter,
            ],
        );
    }
}
