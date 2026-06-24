<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property int $contract_version
 * @property string $contract_html_snapshot
 * @property \Illuminate\Support\Carbon $signed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereContractHtmlSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereContractVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereSignedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractSignature whereUserId($value)
 * @mixin \Eloquent
 */
class ContractSignature extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
