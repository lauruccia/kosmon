<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
