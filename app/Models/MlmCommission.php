<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $mlm_commission_run_id
 * @property int $agent_user_id
 * @property string $type
 * @property int $source_client_id
 * @property int|null $source_agent_id
 * @property int|null $level
 * @property int $base_amount_eur_cents
 * @property numeric $percentage
 * @property int $amount_eur_cents
 * @property string $status
 * @property string $idempotency_key
 * @property-read User $agent
 * @property-read User $sourceClient
 * @property-read User|null $sourceAgent
 * @property-read MlmCommissionRun $run
 */
class MlmCommission extends Model
{
    protected $table = 'mlm_commissions';

    protected $fillable = [
        'uuid',
        'mlm_commission_run_id',
        'agent_user_id',
        'type',
        'source_client_id',
        'source_agent_id',
        'level',
        'base_amount_eur_cents',
        'percentage',
        'amount_eur_cents',
        'status',
        'mlm_payout_id',
        'idempotency_key',
    ];

    protected $casts = [
        'percentage' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $commission): void {
            $commission->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function sourceClient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_client_id');
    }

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_agent_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MlmCommissionRun::class, 'mlm_commission_run_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MlmPayout::class, 'mlm_payout_id');
    }
}
