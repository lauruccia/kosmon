<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcCardLog extends Model
{
    protected $fillable = [
        'nfc_card_id', 'event', 'merchant_company_id', 'amount', 'ip', 'notes',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class, 'nfc_card_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'merchant_company_id');
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'tap'            => 'Avvicinamento card',
            'auth_ok'        => 'PIN corretto',
            'auth_fail'      => 'PIN errato',
            'pin_locked'     => 'Card bloccata (PIN)',
            'blocked'        => 'Tap su card bloccata',
            'revoked'        => 'Tap su card revocata',
            'limit_exceeded' => 'Limite superato',
            'payment_ok'     => 'Pagamento eseguito',
            'payment_fail'   => 'Pagamento fallito',
            default          => $this->event,
        };
    }
}
