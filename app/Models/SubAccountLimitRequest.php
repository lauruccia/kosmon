<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $sub_account_id
 * @property int $requested_by_user_id
 * @property int|null $decided_by_user_id
 * @property string $type
 * @property int $requested_amount
 * @property string $reason
 * @property string $status
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon|null $overdraft_expires_at
 * @property bool $overdraft_used
 * @property int|null $overdraft_transfer_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $decidedBy
 * @property-read \App\Models\Transfer|null $overdraftTransfer
 * @property-read \App\Models\User $requestedBy
 * @property-read \App\Models\Account $subAccount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereDecidedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereDecisionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereOverdraftExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereOverdraftTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereOverdraftUsed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereRequestedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereRequestedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereSubAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubAccountLimitRequest withoutTrashed()
 * @mixin \Eloquent
 */
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
