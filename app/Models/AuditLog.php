<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $actor_user_id
 * @property string $event
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $ip_address
 * @property array<array-key, mixed>|null $context
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereActorUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAuditableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAuditableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereUuid($value)
 * @mixin \Eloquent
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'actor_user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $auditLog): void {
            $auditLog->uuid ??= (string) Str::uuid();
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}