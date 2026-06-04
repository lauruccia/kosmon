<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
