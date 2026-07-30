<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Riga del "cassetto kmoney" dell'agente (2026-07-30, vedi MlmWalletService).
 * Append-only: amount_cents positivo = accredito (compenso maturato),
 * negativo = riserva/rilascio per un prelievo. La somma delle righe di un
 * agente, limitata dal saldo KY realmente disponibile sul conto, e'
 * l'importo che l'agente puo' ancora convertire in euro (vedi
 * MlmWalletService::withdrawableBalance()).
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string|null $category diretta|indiretta|estesa|bonus, null per le righe di prelievo
 * @property int $amount_cents
 * @property string $source_type commission|bonus_payout|withdrawal_reserve|withdrawal_release
 * @property int|null $source_id
 * @property int|null $transfer_id
 * @property string $idempotency_key
 * @property-read User $agent
 * @property-read Transfer|null $transfer
 */
class MlmWalletLedgerEntry extends Model
{
    protected $table = 'mlm_wallet_ledger_entries';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'category',
        'amount_cents',
        'source_type',
        'source_id',
        'transfer_id',
        'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
