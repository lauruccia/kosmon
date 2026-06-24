<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string|null $public_key
 * @property string|null $auth_token
 * @property string $content_encoding
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereAuthToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereContentEncoding($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereUserId($value)
 * @mixin \Eloquent
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
