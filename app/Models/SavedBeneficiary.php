<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
