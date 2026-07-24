<?php

namespace App\Services\PaymentGateways;

use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Driver Bonifico: nessuna API esterna. L'acquirente vede IBAN/intestatario
 * dell'azienda e paga fuori piattaforma; la conferma non avviene tramite
 * verify() (che qui non fa nulla) ma tramite un'azione dedicata cliccata
 * dall'azienda venditrice stessa — vedi
 * PaymentController::confirmBankTransfer().
 */
class BankTransferDriver implements PaymentGatewayDriver
{
    public function initiate(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): ?string {
        $payment->update([
            'provider'           => PaymentGateway::PROVIDER_BANK_TRANSFER,
            'payment_gateway_id' => $gateway->id,
            'status'             => MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION,
        ]);

        // Nessun redirect esterno: si torna alla pagina dell'ordine, che
        // mostrerà le istruzioni di bonifico (IBAN, intestatario, causale).
        return null;
    }

    public function verify(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        Request $request
    ): bool {
        // La conferma bonifico è manuale, ad opera dell'azienda venditrice
        // (PaymentController::confirmBankTransfer), non passa da qui.
        return $payment->isPaid();
    }
}
