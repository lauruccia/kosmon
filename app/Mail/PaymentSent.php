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

class PaymentSent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $sender,
        public readonly Transfer $transfer,
        public readonly Account $fromAccount,
        public readonly Account $toAccount,
        public readonly int $balanceAfter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->sender->email,
            subject: 'Pagamento di ' . number_format($this->transfer->amount, 2, ',', '.') . ' KY inviato',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-sent',
            with: [
                'emailType'    => 'Pagamento inviato',
                'emailTitle'   => 'Conferma pagamento',
                'sender'       => $this->sender,
                'transfer'     => $this->transfer,
                'fromAccount'  => $this->fromAccount,
                'toAccount'    => $this->toAccount,
                'balanceAfter' => $this->balanceAfter,
            ],
        );
    }
}
