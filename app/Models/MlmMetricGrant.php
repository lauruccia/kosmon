<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * "Punti/agenti omaggio" assegnati manualmente da un admin a un agente
 * (vedi migration 2026_07_14_090000_create_mlm_metric_grants_table). Non
 * scadono mai: si sommano ai valori reali calcolati da MlmRankEngine ed
 * User::mlmActivePoints() finche' non vengono revocati esplicitamente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string $metric 'points'|'level1_basic_count'
 * @property int $amount
 * @property string|null $reason
 * @property int|null $granted_by_admin_id
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by_admin_id
 * @property-read User $agent
 * @property-read User|null $grantedBy
 * @property-read User|null $revokedBy
 */
class MlmMetricGrant extends Model
{
    protected $table = 'mlm_metric_grants';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'metric',
        'amount',
        'reason',
        'granted_by_admin_id',
        'revoked_at',
        'revoked_by_admin_id',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->uuid ??= (string) Str::uuid();
        });
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    /** Somma dei grant ATTIVI (non revocati) di una certa metrica per un agente. */
    public static function activeSumFor(int $agentUserId, string $metric): int
    {
        return (int) static::query()
            ->where('agent_user_id', $agentUserId)
            ->where('metric', $metric)
            ->active()
            ->sum('amount');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_admin_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_admin_id');
    }
}
