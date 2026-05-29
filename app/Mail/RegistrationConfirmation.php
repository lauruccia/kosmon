<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly ?Account $account = null,
        public readonly ?Company $company = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->user->email,
            subject: 'Benvenuto nel circuito KMoney — conto aperto',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration-confirmation',
            with: [
                'emailType'  => 'Registrazione',
                'emailTitle' => 'Conto KMoney aperto con successo',
                'user'       => $this->user,
                'account'    => $this->account,
                'company'    => $this->company,
            ],
        );
    }
}
