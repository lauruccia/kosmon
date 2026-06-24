<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $user_id
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $account
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountManager whereUserId($value)
 * @mixin \Eloquent
 */
class AccountManager extends Model
{
    protected $table = 'account_managers';

    protected $fillable = [
        'account_id',
        'user_id',
        'role',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }
}
