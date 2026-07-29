<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Segnalazione di un'azienda da parte di un cliente KMoney (feature
 * "segnalazione azienda" richiesta da Laura il 29/07/2026): il cliente
 * indica in testo libero nome/citta/note di un'azienda dove vorrebbe
 * spendere il proprio saldo KY. La segnalazione viene instradata
 * all'agente di riferimento del cliente (snapshot su agent_user_id al
 * momento della creazione, vedi CompanyReportService::resolveAgentFor())
 * e resa visibile in copia a tutti gli admin.
 *
 * Stati: pending -> contract_signed (bonus KY erogato al segnalante,
 * vedi CompanyReportService::markContractSigned()) oppure pending ->
 * rejected (nessun bonus, richiede una nota dell'agente).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $agent_user_id
 * @property string $company_name
 * @property string|null $company_city
 * @property string|null $company_notes
 * @property string $status
 * @property string|null $agent_notes
 * @property int|null $actioned_by
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property int|null $bonus_transfer_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $reporter
 * @property-read \App\Models\User|null $agent
 * @property-read \App\Models\User|null $actionedBy
 * @property-read \App\Models\Transfer|null $bonusTransfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyReport pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyReport forAgent(int $agentId)
 * @mixin \Eloquent
 */
class CompanyReport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONTRACT_SIGNED = 'contract_signed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'user_id',
        'agent_user_id',
        'company_name',
        'company_city',
        'company_notes',
        'status',
        'agent_notes',
        'actioned_by',
        'actioned_at',
        'bonus_transfer_id',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
            $model->status ??= self::STATUS_PENDING;
        });
    }

    // ---- Relations --------------------------------------------------------

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function bonusTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'bonus_transfer_id');
    }

    // ---- Scopes -------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_user_id', $agentId);
    }

    // ---- Helpers ------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isContractSigned(): bool
    {
        return $this->status === self::STATUS_CONTRACT_SIGNED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
