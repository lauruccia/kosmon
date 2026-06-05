<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\ScheduledPayment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecurringPaymentScheduled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  ScheduledPayment[]  $payments
     */
    public function __construct(
        public readonly User    $recipient,
        public readonly Account $fromAccount,
        public readonly Account $toAccount,
        public readonly array   $payments,      // ScheduledPayment[]
        public readonly string  $recurrenceType,
        public readonly bool    $isPayer,        // true = chi paga, false = chi riceve
    ) {}

    public function envelope(): Envelope
    {
        $total = count($this->payments);
        $label = match ($this->recurrenceType) {
            'weekly'   => 'settimanali',
            'biweekly' => 'bisettimanali',
            default    => 'mensili',
        };

        $subject = $this->isPayer
            ? "Piano pagamenti ricorrenti: {$total} rate {$label} programmate"
            : "Riceverai {$total} pagamenti ricorrenti {$label}";

        return new Envelope(
            to: $this->recipient->email,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recurring-payment-scheduled',
            with: [
                'emailType'      => $this->isPayer ? 'Pagamenti programmati' : 'Pagamenti in arrivo',
                'emailTitle'     => $this->isPayer
                    ? 'Piano pagamenti ricorrenti attivato'
                    : 'Riceverai pagamenti ricorrenti',
                'recipient'      => $this->recipient,
                'fromAccount'    => $this->fromAccount,
                'toAccount'      => $this->toAccount,
                'payments'       => $this->payments,
                'recurrenceType' => $this->recurrenceType,
                'isPayer'        => $this->isPayer,
            ],
        );
    }
}
