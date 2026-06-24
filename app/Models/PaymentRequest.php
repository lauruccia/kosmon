<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $token
 * @property int $to_account_id
 * @property int $amount
 * @property string|null $description
 * @property string $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property int|null $transfer_id
 * @property int|null $from_account_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $kind
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $reminder_24h_sent_at
 * @property \Illuminate\Support\Carbon|null $reminder_1h_sent_at
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\Account|null $fromAccount
 * @property-read \App\Models\Account $toAccount
 * @property-read \App\Models\Transfer|null $transfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereCreatedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereFromAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereReminder1hSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereReminder24hSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereToAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentRequest whereUuid($value)
 * @mixin \Eloquent
 */
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
