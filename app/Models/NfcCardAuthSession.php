<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $nonce
 * @property int $nfc_card_id
 * @property int|null $merchant_company_id
 * @property int $amount
 * @property string|null $description
 * @property string $status
 * @property string|null $transfer_uuid
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $merchant_account_id
 * @property-read \App\Models\NfcCard $card
 * @property-read \App\Models\Company|null $merchant
 * @property-read \App\Models\Account|null $merchantAccount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereMerchantAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereMerchantCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereNfcCardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereTransferUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCardAuthSession whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class NfcCardAuthSession extends Model
{
    protected $fillable = [
        'nonce', 'nfc_card_id', 'merchant_company_id', 'merchant_account_id',
        'amount', 'description', 'status', 'transfer_uuid', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            if (empty($s->nonce)) {
                $s->nonce = (string) Str::uuid();
            }
        });
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class, 'nfc_card_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'merchant_company_id');
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'merchant_account_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }
}
