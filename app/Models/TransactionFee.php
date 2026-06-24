<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $operation_kind
 * @property string $fee_type
 * @property numeric $fee_value
 * @property int $min_fee
 * @property int|null $max_fee
 * @property bool $is_active
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereFeeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereFeeValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereMaxFee($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereMinFee($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereOperationKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TransactionFee whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class TransactionFee extends Model
{
    protected $fillable = [
        'operation_kind', 'fee_type', 'fee_value', 'min_fee', 'max_fee', 'is_active', 'description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'fee_value'  => 'decimal:4',
        'min_fee'    => 'integer',
        'max_fee'    => 'integer',
    ];

    public static function calculate(string $kind, int $amount): int
    {
        // Il cashback non è mai soggetto a commissione (evita loop di fee su fee)
        if ($kind === 'portal_cashback' || $kind === 'portal_fee') {
            return 0;
        }

        $fee = static::where('operation_kind', $kind)->where('is_active', true)->first()
            ?? static::where('operation_kind', '*')->where('is_active', true)->first();

        if (! $fee) {
            return 0;
        }

        if ($fee->fee_type === 'percentage') {
            $computed = (int) round($amount * ((float) $fee->fee_value / 100));
            $computed = max($computed, $fee->min_fee ?? 0);
            if ($fee->max_fee !== null) {
                $computed = min($computed, $fee->max_fee);
            }
            return $computed;
        }

        // flat
        return (int) round((float) $fee->fee_value);
    }

    public static function kindOptions(): array
    {
        return [
            'portal_payment'         => 'Pagamento diretto',
            'portal_payment_request' => 'Pagamento via Payment Request API (W3C)',
            'portal_text_request'    => 'Richiesta testo approvata',
            'portal_qr_payment'      => 'QR Incasso',
            'nfc'                    => 'NFC (smartphone-to-smartphone)',
            'nfc_card'               => 'Pagamento Card NFC fisica',
            'portal_installment'     => 'Rata piano rateale',
            'portal_netting'         => 'Compensazione (netting)',
            'portal_credit_note'     => 'Nota di credito',
            'api_payment'            => 'Pagamento API',
            'portal_cashback'        => 'Cashback (non soggetto a commissione — escluso automaticamente)',
            '*'                      => 'Default (tutti i tipi non coperti)',
        ];
    }
}
