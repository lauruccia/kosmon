<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $credit_limit
 * @property int|null $daily_outgoing_limit
 * @property int|null $single_transfer_limit
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $account
 * @method static \Database\Factories\CreditLimitFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereCreditLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereDailyOutgoingLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereSingleTransferLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CreditLimit whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class CreditLimit extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'credit_limit',
        'daily_outgoing_limit',
        'single_transfer_limit',
        'status',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}