<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $credential_source
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereCredentialSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebAuthnCredential whereUserId($value)
 * @mixin \Eloquent
 */
class WebAuthnCredential extends Model
{
    protected $table = 'webauthn_credentials';

    protected $fillable = [
        'user_id',
        'credential_id',
        'credential_source',
        'name',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
