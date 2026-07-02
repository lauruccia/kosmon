<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invito email inviato da un agente MLM con il proprio link referral.
 * Stato: pending (in attesa di registrazione) | registered.
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string $email
 * @property string|null $name
 * @property string $status
 * @property int|null $registered_user_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $registered_at
 * @property-read User $agent
 * @property-read User|null $registeredUser
 */
class MlmInvitation extends Model
{
    protected $table = 'mlm_invitations';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'email',
        'name',
        'status',
        'registered_user_id',
        'sent_at',
        'registered_at',
    ];

    protected $casts = [
        'sent_at'       => 'datetime',
        'registered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function registeredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Marca come registrate tutte le pendenti per questa email
     * (chiamato alla registrazione del nuovo utente).
     */
    public static function markRegistered(User $user): void
    {
        static::where('email', $user->email)
            ->where('status', 'pending')
            ->update([
                'status'             => 'registered',
                'registered_user_id' => $user->id,
                'registered_at'      => now(),
            ]);
    }
}
