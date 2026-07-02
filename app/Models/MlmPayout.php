<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Liquidazione EUR aggregata per agente/periodo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property \Illuminate\Support\Carbon $period_from
 * @property \Illuminate\Support\Carbon $period_to
 * @property int $commissions_total_eur_cents
 * @property int $bonus_total_eur_cents
 * @property int $total_eur_cents
 * @property string $status
 * @property int|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $payment_reference
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $admin_notes
 * @property-read User $agent
 * @property-read User|null $approvedBy
 */
class MlmPayout extends Model
{
    protected $table = 'mlm_payouts';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'period_from',
        'period_to',
        'commissions_total_eur_cents',
        'bonus_total_eur_cents',
        'total_eur_cents',
        'status',
        'approved_by_user_id',
        'approved_at',
        'payment_reference',
        'paid_at',
        'admin_notes',
    ];

    protected $casts = [
        'period_from'  => 'date',
        'period_to'    => 'date',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payout): void {
            $payout->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function commissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MlmCommission::class, 'mlm_payout_id');
    }

    public function bonusPayouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MlmBonusPayout::class, 'mlm_payout_id');
    }
}
