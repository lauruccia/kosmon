<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CreditLimitRequest extends Model
{
    protected $fillable = [
        'uuid',
        'account_id',
        'requested_amount',
        'reason',
        'status',
        'approved_amount',
        'admin_note',
        'admin_user_id',
        'actioned_at',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ---- Relations --------------------------------------------------------

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ---- Helpers ----------------------------------------------------------

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

    public function effectiveAmount(): int
    {
        return (int) ($this->approved_amount ?? $this->requested_amount);
    }
}
