<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Una consegna (riuscita o no) verso l'endpoint di un'applicazione del
 * circuito. È il registro che si guarda quando kshop dice "non mi è arrivato
 * niente": qui si vede se KMoney ha provato, quante volte, e cosa ha risposto
 * dall'altra parte.
 */
class ClientWebhookDelivery extends Model
{
    protected $fillable = [
        'uuid',
        'client_id',
        'event',
        'url',
        'body',
        'response_status',
        'response_body',
        'success',
        'attempts',
        'delivered_at',
    ];

    protected $casts = [
        'success'      => 'boolean',
        'attempts'     => 'integer',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClientWebhookDelivery $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Il corpo così com'è stato firmato e spedito. Volutamente NON un cast
     * `array`: la firma HMAC è calcolata sui byte esatti di questa stringa, e
     * ri-serializzare un array darebbe una firma diversa a parità di dati.
     */
    public function decodedBody(): array
    {
        $decoded = json_decode((string) $this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
