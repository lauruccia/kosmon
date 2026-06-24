<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nfc_card_id
 * @property string $event
 * @property int|null $merchant_company_id
 * @property int|null $amount
 * @property string|null $ip
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\NfcCard $card
 * @property-read \App\Models\Company|null $merchant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereMerchantCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereNfcCardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardLog whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
