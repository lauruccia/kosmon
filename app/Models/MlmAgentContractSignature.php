<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot immutabile del contratto di nomina ad Agente KNM firmato da un
 * utente. Speculare a ContractSignature (contratto di adesione principale).
 *
 * @property int $id
 * @property int $user_id
 * @property int $contract_version
 * @property string $contract_html_snapshot
 * @property \Illuminate\Support\Carbon $signed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property-read \App\Models\User $user
 */
class MlmAgentContractSignature extends Model
{
    protected $fillable = [
        'user_id',
        'contract_version',
        'contract_html_snapshot',
        'signed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
