<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $account_id
 * @property int $invited_by
 * @property string $email
 * @property string $token
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $account
 * @property-read \App\Models\User $invitedBy
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereInvitedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountInvitation whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SubAccountInvitation extends Model
{
    protected $fillable = [
        'account_id',
        'invited_by',
        'email',
        'token',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(7);
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
