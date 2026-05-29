<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycStatusChanged extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Company $company,
        public readonly string $newStatus,   // pending | under_review | approved | rejected
        public readonly ?string $adminNotes = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->newStatus) {
            'under_review' => 'Abbiamo ricevuto i tuoi documenti KYC',
            'approved'     => '✓ La tua azienda è stata verificata — KMoney',
            'rejected'     => 'Aggiornamento verifica KYC — KMoney',
            default        => 'Aggiornamento stato KYC — KMoney',
        };

        return new Envelope(
            to: $this->recipient->email,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyc-status-changed',
            with: [
                'emailType'  => 'Verifica aziendale KYC',
                'emailTitle' => match ($this->newStatus) {
                    'under_review' => 'Documenti ricevuti, revisione in corso',
                    'approved'     => 'Azienda verificata con successo',
                    'rejected'     => 'Verifica non completata',
                    default        => 'Aggiornamento stato KYC',
                },
                'recipient'   => $this->recipient,
                'company'     => $this->company,
                'newStatus'   => $this->newStatus,
                'adminNotes'  => $this->adminNotes,
                'statusLabel' => Company::KYC_STATUSES[$this->newStatus] ?? $this->newStatus,
            ],
        );
    }
}
