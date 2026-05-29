<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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