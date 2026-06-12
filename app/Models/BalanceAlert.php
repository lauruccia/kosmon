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
        'is_in_alert',
    ];

    protected $casts = [
        'last_triggered_at' => 'datetime',
        'notify_email'      => 'boolean',
        'notify_inapp'      => 'boolean',
        'is_active'         => 'boolean',
        'is_in_alert'       => 'boolean',
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
        return ky_format($this->threshold_amount) . ' KY';
    }

    /** Importo soglia in KY con decimali (float) */
    public function thresholdKy(): float
    {
        return $this->threshold_amount / 100;
    }

    /**
     * L'alert può sparare ora?
     * Logica edge-triggered: scatta solo quando il saldo SCENDE sotto soglia,
     * non ad ogni ciclo mentre resta sotto. Si resetta quando torna sopra soglia.
     *
     * Condizioni: attivo E non già in stato di allerta (is_in_alert = false).
     * Il cooldown resta come protezione extra contro bug o doppi job concorrenti.
     */
    public function canTrigger(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Già in stato di allerta: non notificare di nuovo finché non si riprende
        if ($this->is_in_alert) {
            return false;
        }

        // Protezione anti-doppio: rispetta il cooldown anche in stato non-alert
        if ($this->last_triggered_at) {
            return $this->last_triggered_at->addHours($this->cooldown_hours)->isPast();
        }

        return true;
    }
}
