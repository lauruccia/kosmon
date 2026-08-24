<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token di accesso emesso a un'applicazione del circuito per conto di un utente.
 *
 * Token *opaco*: è una stringa casuale senza significato, di cui qui resta solo
 * l'hash. Non è un JWT firmato, e la scelta è voluta — chi lo riceve lo verifica
 * chiedendolo a KMoney (`GET /api/v1/userinfo`), quindi una revoca ha effetto
 * immediato invece di aspettare la scadenza della firma.
 */
class OAuthAccessToken extends Model
{
    protected $table = 'oauth_access_tokens';

    protected $fillable = [
        'uuid',
        'token_hash',
        'refresh_hash',
        'chain_uuid',
        'client_id',
        'user_id',
        'scopes',
        'expires_at',
        'refresh_expires_at',
        'revoked_at',
        'last_used_at',
        'created_ip',
    ];

    protected $casts = [
        'scopes'             => 'array',
        'expires_at'         => 'datetime',
        'refresh_expires_at' => 'datetime',
        'revoked_at'         => 'datetime',
        'last_used_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (OAuthAccessToken $token): void {
            $token->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Il permesso richiesto è fra quelli concessi?
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }
}
