<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot immutabile del contratto di nomina ad Agente KNM firmato da un
 * utente. Speculare a ContractSignature (contratto di adesione principale).
 *
 * 2026-08-07: la stessa firma OTP copre anche l'accettazione delle
 * "Direttive e Procedure Kosmos" (documento distinto, mostrato insieme al
 * contratto sulla stessa pagina di firma) — congelate qui in
 * directives_version/directives_html_snapshot, speculari alle colonne del
 * contratto. Nullable perché le firme precedenti a questa data non le hanno.
 *
 * @property int $id
 * @property int $user_id
 * @property int $contract_version
 * @property string $contract_html_snapshot
 * @property int|null $directives_version
 * @property string|null $directives_html_snapshot
 * @property array|null $signer_data_snapshot
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
        'directives_version',
        'directives_html_snapshot',
        'signer_data_snapshot',
        'signed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'signer_data_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
