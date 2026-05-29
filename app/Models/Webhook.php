<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'url', 'secret', 'events',
        'is_active', 'failure_count', 'last_triggered_at',
    ];

    protected $casts = [
        'events'             => 'array',
        'is_active'          => 'boolean',
        'last_triggered_at'  => 'datetime',
        'failure_count'      => 'integer',
    ];

    // Dopo 10 fallimenti consecutivi il webhook viene disattivato automaticamente
    public const MAX_FAILURES = 10;

    // Eventi supportati
    public const EVENTS = [
        'transfer.booked'                 => 'Pagamento eseguito',
        'transfer.failed'                 => 'Pagamento fallito',
        'payment_request.approved'        => 'Richiesta testo approvata',
        'payment_request.rejected'        => 'Richiesta testo rifiutata',
        'scheduled_payment.executed'      => 'Pagamento programmato eseguito',
        'scheduled_payment.failed'        => 'Pagamento programmato fallito',
        'payment_plan.approved'           => 'Piano rateale approvato',
        'netting.accepted'                => 'Compensazione accettata',
        'kyc.approved'                    => 'KYC approvato',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid   ??= (string) Str::uuid();
            $model->secret ??= Str::random(32);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        return in_array('*', $this->events ?? [], true)
            || in_array($event, $this->events ?? [], true);
    }
}
