<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubAccountLimitRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sub_account_id',
        'requested_by_user_id',
        'decided_by_user_id',
        'type',
        'requested_amount',
        'reason',
        'status',
        'decision_note',
        'overdraft_expires_at',
        'overdraft_used',
        'overdraft_transfer_id',
    ];

    protected $casts = [
        'requested_amount'    => 'integer',
        'overdraft_expires_at' => 'datetime',
        'overdraft_used'      => 'boolean',
    ];

    // ─── Relazioni ──────────────────────────────────────────────────────────

    public function subAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sub_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function overdraftTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'overdraft_transfer_id');
    }

    // ─── Stato ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isTemporaryOverdraft(): bool
    {
        return $this->type === 'temporary_overdraft';
    }

    /**
     * Un overdraft approvato è ancora utilizzabile se non scaduto e non consumato.
     */
    public function isOverdraftUsable(): bool
    {
        return $this->isApproved()
            && $this->isTemporaryOverdraft()
            && ! $this->overdraft_used
            && ($this->overdraft_expires_at === null || $this->overdraft_expires_at->isFuture());
    }

    // ─── Label UI ────────────────────────────────────────────────────────────

    public function typeLabel(): string
    {
        return match ($this->type) {
            'spending_limit_increase'  => 'Aumento limite per pagamento',
            'daily_limit_increase'     => 'Aumento limite giornaliero',
            'monthly_limit_increase'   => 'Aumento limite mensile',
            'temporary_overdraft'      => 'Sforamento una-tantum',
            default                    => $this->type,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'  => 'In attesa',
            'approved' => 'Approvata',
            'rejected' => 'Rifiutata',
            default    => $this->status,
        };
    }
}
