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

class RefundIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User     $recipient,
        public readonly Transfer $refundTransfer,
        public readonly Transfer $originalTransfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
        public readonly int      $balanceAfter,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->recipient->email,
            subject: 'Rimborso ricevuto: ' . number_format($this->refundTransfer->amount, 2, ',', '.') . ' KY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-received',
            with: [
                'emailType'    => 'Rimborso ricevuto',
                'emailTitle'   => 'Hai ricevuto un rimborso',
                'recipient'    => $this->recipient,
                'transfer'     => $this->refundTransfer,
                'fromAccount'  => $this->fromAccount,
                'toAccount'    => $this->toAccount,
                'balanceAfter' => $this->balanceAfter,
            ],
        );
    }
}
