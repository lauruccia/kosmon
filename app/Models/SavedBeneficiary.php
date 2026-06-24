<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $owner_account_id
 * @property int $beneficiary_account_id
 * @property string|null $alias
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $beneficiaryAccount
 * @property-read string $display_name
 * @property-read \App\Models\Account $ownerAccount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereAlias($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereBeneficiaryAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereOwnerAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SavedBeneficiary whereUuid($value)
 * @mixin \Eloquent
 */
class SavedBeneficiary extends Model
{
    protected $fillable = [
        'uuid',
        'owner_account_id',
        'beneficiary_account_id',
        'alias',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (SavedBeneficiary $b) {
            $b->uuid ??= (string) Str::uuid();
        });
    }

    public function ownerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'owner_account_id');
    }

    public function beneficiaryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'beneficiary_account_id')
            ->with(['company', 'ownerUser']);
    }

    /**
     * Etichetta visualizzata: alias se impostato, altrimenti nome azienda/utente.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->alias) {
            return $this->alias;
        }

        $account = $this->beneficiaryAccount;
        if (! $account) {
            return 'N/D';
        }

        return $account->company?->name
            ?? $account->ownerUser?->name
            ?? 'N/D';
    }
}
