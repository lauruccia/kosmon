<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codice di autorizzazione OAuth2 — il biglietto usa e getta che il browser
 * dell'utente porta a kshop dopo il consenso, e che kshop scambia sul retro con
 * un token vero.
 *
 * In tabella c'è solo l'hash: il codice in chiaro esiste per pochi secondi,
 * nella barra degli indirizzi, e non viene mai scritto da nessuna parte.
 */
class OAuthAuthorizationCode extends Model
{
    protected $table = 'oauth_authorization_codes';

    protected $fillable = [
        'code_hash',
        'chain_uuid',
        'client_id',
        'user_id',
        'scopes',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'consumed_at',
        'created_ip',
    ];

    protected $casts = [
        'scopes'      => 'array',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
