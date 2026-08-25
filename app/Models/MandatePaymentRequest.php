<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Il ponte fra "l'addebito automatico non si può fare" e "l'utente lo conferma".
 *
 * Non è una seconda richiesta di pagamento: la richiesta vera è una
 * `PaymentRequest` normale, con la sua pagina, la sua scadenza e il suo webhook.
 * Questa riga è il contesto che la richiesta da sola non saprebbe portare —
 * quale mandato, quale ordine di kshop, con quale chiave di idempotenza, e
 * perché è servito disturbare l'utente.
 */
class MandatePaymentRequest extends Model
{
    protected $fillable = [
        'uuid',
        'payment_mandate_id',
        'payment_request_id',
        'client_id',
        'seller_account_number',
        'amount',
        'external_order_uuid',
        'order_title',
        'quantity',
        'idempotency_key',
        'reason',
        'return_url',
        'confirmed_at',
        'payment_mandate_charge_id',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'quantity'     => 'integer',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Le frasi con cui la pagina di conferma spiega all'utente perché non è
     * bastato un clic. Sono le stesse `reason` che viaggiano nel 402 verso
     * kshop: un solo vocabolario, dalla API alla schermata.
     */
    public const REASON_LABELS = [
        'amount_above_limit'   => 'L\'importo supera il tetto che hai autorizzato per singolo acquisto.',
        'seller_not_authorized' => 'È il tuo primo acquisto da questo venditore.',
        'mandate_suspended'    => 'L\'autorizzazione è sospesa per attività insolita.',
        'mandate_expired'      => 'L\'autorizzazione è scaduta.',
        'mandate_revoked'      => 'Hai revocato l\'autorizzazione a questa applicazione.',
        'limit_exceeded'       => 'Il pagamento automatico non è passato: controlla il saldo disponibile.',
        'payment_refused'      => 'Il pagamento automatico non è passato.',
    ];

    protected static function booted(): void
    {
        static::creating(function (MandatePaymentRequest $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(PaymentMandate::class, 'payment_mandate_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentMandateCharge::class, 'payment_mandate_charge_id');
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? 'Questo pagamento va confermato da te.';
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
