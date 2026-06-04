<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NettingProposal extends Model
{
    protected $fillable = [
        'uuid',
        'proposer_account_id',
        'counterparty_account_id',
        'proposer_transfer_ids',
        'counterparty_transfer_ids',
        'proposer_total',
        'counterparty_total',
        'currency_code',
        'net_payer_account_id',
        'net_amount',
        'description',
        'status',
        'net_transfer_id',
        'actioned_by',
        'actioned_at',
        'proposed_by',
        'expires_at',
    ];

    protected $casts = [
        'proposer_transfer_ids'    => 'array',
        'counterparty_transfer_ids' => 'array',
        'proposer_total'           => 'integer',
        'counterparty_total'       => 'integer',
        'net_amount'               => 'integer',
        'actioned_at'              => 'datetime',
        'expires_at'               => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relazioni ──────────────────────────────────────────────────────────────

    public function proposerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proposer_account_id');
    }

    public function counterpartyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterparty_account_id');
    }

    public function netPayerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'net_payer_account_id');
    }

    public function netTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'net_transfer_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    // ── Helper queries ─────────────────────────────────────────────────────────

    /** Trasferimenti lato proposer (crediti del proposer verso counterparty). */
    public function proposerTransfers(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->proposer_transfer_ids)) {
            return collect();
        }
        return Transfer::whereIn('id', $this->proposer_transfer_ids)->get();
    }

    /** Trasferimenti lato counterparty (crediti del counterparty verso proposer). */
    public function counterpartyTransfers(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->counterparty_transfer_ids)) {
            return collect();
        }
        return Transfer::whereIn('id', $this->counterparty_transfer_ids)->get();
    }

    // ── Stato ─────────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' ||
               ($this->isPending() && $this->expires_at && $this->expires_at->isPast());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'  => 'In attesa',
            'accepted' => 'Accettata',
            'rejected' => 'Rifiutata',
            'expired'  => 'Scaduta',
            default    => $this->status,
        };
    }

    public function statusChipClass(): string
    {
        return match ($this->status) {
            'accepted' => 'chip success',
            'rejected' => 'chip pink',
            'expired'  => 'chip',
            default    => 'chip',
        };
    }

    /** Quale parte paga la differenza, in termini leggibili. */
    public function netPayerLabel(): string
    {
        if ($this->net_amount === 0) {
            return 'Pareggio perfetto — nessun pagamento netto';
        }
        return $this->netPayerAccount?->display_name . ' paga ' .
               ky_format($this->net_amount) . ' KY';
    }

    /** L'account dato è il proposer? */
    public function isProposer(Account $account): bool
    {
        return $this->proposer_account_id === $account->id;
    }

    /** L'account dato è il counterparty? */
    public function isCounterparty(Account $account): bool
    {
        return $this->counterparty_account_id === $account->id;
    }
}
