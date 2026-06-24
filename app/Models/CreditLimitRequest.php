<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $requested_amount
 * @property string|null $reason
 * @property string $status
 * @property int|null $approved_amount
 * @property string|null $admin_note
 * @property int|null $admin_user_id
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $account
 * @property-read \App\Models\User|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereActionedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereAdminNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereAdminUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereApprovedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereRequestedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimitRequest whereUuid($value)
 * @mixin \Eloquent
 */
class CreditLimitRequest extends Model
{
    protected $fillable = [
        'uuid',
        'account_id',
        'requested_amount',
        'reason',
        'status',
        'approved_amount',
        'admin_note',
        'admin_user_id',
        'actioned_at',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ---- Relations --------------------------------------------------------

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ---- Helpers ----------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function effectiveAmount(): int
    {
        return (int) ($this->approved_amount ?? $this->requested_amount);
    }
}
