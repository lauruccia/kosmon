<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BalanceAlert extends Model
{
    protected $fillable = [
        'uuid',
        'account_id',
        'threshold_amount',
        'notify_email',
        'notify_inapp',
        'cooldown_hours',
        'last_triggered_at',
        'is_active',
    ];

    protected $casts = [
        'last_triggered_at' => 'datetime',
        'notify_email'      => 'boolean',
        'notify_inapp'      => 'boolean',
        'is_active'         => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Importo soglia formattato (es. "50,00 KY") */
    public function thresholdFormatted(): string
    {
        return number_format($this->threshold_amount / 100, 2, ',', '.') . ' KY';
    }

    /** Importo soglia in KY con decimali (float) */
    public function thresholdKy(): float
    {
        return $this->threshold_amount / 100;
    }

    /**
     * L'alert puo' sparare ora?
     * Condizioni: attivo E (mai sparato OPPURE cooldown scaduto)
     */
    public function canTrigger(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if (! $this->last_triggered_at) {
            return true;
        }
        return $this->last_triggered_at->addHours($this->cooldown_hours)->isPast();
    }
}
