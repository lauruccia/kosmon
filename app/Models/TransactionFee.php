<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
            'portal_payment'        => 'Pagamento diretto',
            'portal_text_request'   => 'Richiesta testo approvata',
            'portal_qr_payment'     => 'QR Incasso',
            'nfc'                   => 'NFC',
            'portal_installment'    => 'Rata piano rateale',
            'portal_netting'        => 'Compensazione (netting)',
            'portal_credit_note'    => 'Nota di credito',
            'api_payment'           => 'Pagamento API',
            '*'                     => 'Default (tutti i tipi non coperti)',
        ];
    }
}
