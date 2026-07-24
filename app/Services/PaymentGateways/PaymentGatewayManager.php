<?php

namespace App\Services\PaymentGateways;

use App\Models\PaymentGateway;
use InvalidArgumentException;

/**
 * Risolve il driver corretto in base al provider di un PaymentGateway.
 */
class PaymentGatewayManager
{
    public function driver(string $provider): PaymentGatewayDriver
    {
        return match ($provider) {
            PaymentGateway::PROVIDER_STRIPE        => new StripeCheckoutDriver(),
            PaymentGateway::PROVIDER_PAYPAL        => new PayPalOrdersDriver(),
            PaymentGateway::PROVIDER_BANK_TRANSFER => new BankTransferDriver(),
            default => throw new InvalidArgumentException("Provider di pagamento sconosciuto: {$provider}"),
        };
    }

    public function forGateway(PaymentGateway $gateway): PaymentGatewayDriver
    {
        return $this->driver($gateway->provider);
    }
}
