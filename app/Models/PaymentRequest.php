<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentRequest extends Model
{
    protected $fillable = [
        'uuid',
        'token',
        'kind',
        'created_by_user_id',
        'to_account_id',
        'from_account_id',
        'amount',
        'description',
        'status',
        'expires_at',
        'paid_at',
        'transfer_id',
        'reminder_24h_sent_at',
        'reminder_1h_sent_at',
    ];

    protected $casts = [
        'expires_at'           => 'datetime',
        'paid_at'              => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_1h_sent_at'  => 'datetime',
        'amount'               => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $model): void {
            $model->uuid  ??= (string) Str::uuid();
            $model->token ??= static::generateUniqueToken();
        });
    }

    // ─── Relazioni ────────────────────────────────────────────────────────────

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    public function isLink(): bool
    {
        return $this->kind === 'link';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isExpired(): bool
    {
        if ($this->status === 'expired') {
            return true;
        }

        return $this->status === 'pending' && $this->expires_at->isPast();
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['paid', 'expired', 'cancelled'], true);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }
}
