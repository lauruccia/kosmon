<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $webhook_id
 * @property string $event
 * @property array<array-key, mixed> $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property bool $success
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Webhook $webhook
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereResponseBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereResponseStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereSuccess($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookDelivery whereWebhookId($value)
 * @mixin \Eloquent
 */
class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_id', 'event', 'payload',
        'response_status', 'response_body', 'success', 'delivered_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'success'      => 'boolean',
        'delivered_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
