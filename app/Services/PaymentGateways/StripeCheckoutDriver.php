<?php

namespace App\Services\PaymentGateways;

use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver Stripe: usa la Checkout Session REST API di Stripe direttamente via
 * Http:: (niente SDK Composer, per compatibilità col deploy cPanel che non
 * esegue "composer install"). Autenticazione con la secret key PROPRIA
 * dell'azienda venditrice — il pagamento arriva sul suo account Stripe.
 */
class StripeCheckoutDriver implements PaymentGatewayDriver
{
    private const API_BASE = 'https://api.stripe.com/v1';

    public function initiate(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): ?string {
        $secretKey = $gateway->credential('secret_key');

        $productName = $payment->listing?->title ?? 'Ordine Kosmopay #' . $payment->uuid;

        $response = Http::asForm()
            ->withToken($secretKey)
            ->post(self::API_BASE . '/checkout/sessions', [
                'mode'                                              => 'payment',
                'success_url'                                       => $successUrl,
                'cancel_url'                                        => $cancelUrl,
                'client_reference_id'                                => $payment->uuid,
                'metadata[marketplace_order_payment_id]'             => (string) $payment->id,
                'line_items[0][quantity]'                            => 1,
                'line_items[0][price_data][currency]'                => strtolower($payment->currency_code),
                'line_items[0][price_data][unit_amount]'             => $payment->amount,
                'line_items[0][price_data][product_data][name]'      => $productName,
            ]);

        if ($response->failed()) {
            Log::error('Stripe checkout session creation failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            $payment->update(['status' => MarketplaceOrderPayment::STATUS_FAILED]);

            return null;
        }

        $session = $response->json();

        $payment->update([
            'provider'           => PaymentGateway::PROVIDER_STRIPE,
            'payment_gateway_id' => $gateway->id,
            'provider_reference' => $session['id'] ?? null,
            'status'             => MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION,
        ]);

        return $session['url'] ?? null;
    }

    public function verify(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        Request $request
    ): bool {
        if (! $payment->provider_reference) {
            return false;
        }

        $secretKey = $gateway->credential('secret_key');

        $response = Http::withToken($secretKey)
            ->get(self::API_BASE . '/checkout/sessions/' . $payment->provider_reference);

        if ($response->failed()) {
            Log::error('Stripe checkout session verify failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            return false;
        }

        $session = $response->json();

        if (($session['payment_status'] ?? null) === 'paid') {
            $payment->update([
                'status'  => MarketplaceOrderPayment::STATUS_PAID,
                'paid_at' => now(),
            ]);

            return true;
        }

        return false;
    }
}
