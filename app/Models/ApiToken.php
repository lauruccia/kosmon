<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $created_by
 * @property string $name
 * @property string $token_hash
 * @property string $token_prefix
 * @property array<array-key, mixed> $abilities
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $last_used_ip
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $creator
 * @method static \Database\Factories\ApiTokenFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereAbilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereLastUsedIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereTokenPrefix($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUuid($value)
 * @mixin \Eloquent
 */
class ApiToken extends Model
{
    use HasFactory;
    protected $fillable = [
        'uuid', 'company_id', 'created_by', 'name',
        'token_hash', 'token_prefix', 'abilities',
        'last_used_at', 'last_used_ip', 'expires_at',
    ];

    protected $casts = [
        'abilities'    => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? ['read'], true)
            || in_array('*', $this->abilities ?? [], true);
    }

    /**
     * Genera un nuovo token in chiaro e restituisce [token_in_chiaro, model_attributes].
     * Il token in chiaro viene mostrato UNA SOLA VOLTA.
     */
    public static function generateRaw(): array
    {
        $raw    = 'km_' . Str::random(40);
        $hash   = hash('sha256', $raw);
        $prefix = substr($raw, 0, 8);

        return [$raw, $hash, $prefix];
    }
}
