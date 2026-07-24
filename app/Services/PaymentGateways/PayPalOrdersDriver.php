<?php

namespace App\Services\PaymentGateways;

use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver PayPal: usa le Orders v2 REST API di PayPal direttamente via Http::
 * (niente SDK Composer). Autenticazione OAuth2 client-credentials con il
 * client_id/client_secret PROPRI dell'azienda venditrice (app PayPal
 * indipendente, non una relazione di marketplace-partner con Kosmopay) — il
 * pagamento arriva sul suo account PayPal.
 */
class PayPalOrdersDriver implements PaymentGatewayDriver
{
    private function apiBase(PaymentGateway $gateway): string
    {
        return $gateway->credential('mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(PaymentGateway $gateway): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth($gateway->credential('client_id'), $gateway->credential('client_secret'))
            ->post($this->apiBase($gateway) . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            Log::error('PayPal OAuth token request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }

    public function initiate(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): ?string {
        $token = $this->accessToken($gateway);

        if (! $token) {
            $payment->update(['status' => MarketplaceOrderPayment::STATUS_FAILED]);

            return null;
        }

        $response = Http::withToken($token)
            ->post($this->apiBase($gateway) . '/v2/checkout/orders', [
                'intent'              => 'CAPTURE',
                'purchase_units'      => [[
                    'reference_id' => $payment->uuid,
                    'amount'       => [
                        'currency_code' => $payment->currency_code,
                        'value'          => number_format($payment->amount / 100, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                ],
            ]);

        if ($response->failed()) {
            Log::error('PayPal order creation failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            $payment->update(['status' => MarketplaceOrderPayment::STATUS_FAILED]);

            return null;
        }

        $order = $response->json();

        $approveUrl = collect($order['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        $payment->update([
            'provider'           => PaymentGateway::PROVIDER_PAYPAL,
            'payment_gateway_id' => $gateway->id,
            'provider_reference' => $order['id'] ?? null,
            'status'             => MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION,
        ]);

        return $approveUrl;
    }

    public function verify(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        Request $request
    ): bool {
        if (! $payment->provider_reference) {
            return false;
        }

        $token = $this->accessToken($gateway);

        if (! $token) {
            return false;
        }

        $response = Http::withToken($token)
            ->post($this->apiBase($gateway) . '/v2/checkout/orders/' . $payment->provider_reference . '/capture');

        // PayPal risponde 422 UNPROCESSABLE_ENTITY se l'ordine è già stato
        // catturato in precedenza: trattiamo come "già pagato", non errore.
        if ($response->failed() && $response->status() !== 422) {
            Log::error('PayPal order capture failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            return false;
        }

        $order = $response->json();
        $status = $order['status'] ?? null;

        if ($status === 'COMPLETED') {
            $payment->update([
                'status'  => MarketplaceOrderPayment::STATUS_PAID,
                'paid_at' => now(),
            ]);

            return true;
        }

        return false;
    }
}
