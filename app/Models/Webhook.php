<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $url
 * @property string $secret
 * @property array<array-key, mixed> $events
 * @property bool $is_active
 * @property int $failure_count
 * @property \Illuminate\Support\Carbon|null $last_triggered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WebhookDelivery> $deliveries
 * @property-read int|null $deliveries_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereEvents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereFailureCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereLastTriggeredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereUuid($value)
 * @mixin \Eloquent
 */
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
        'payment_request.paid'            => 'Richiesta di pagamento (QR/link/e-commerce) pagata',
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
