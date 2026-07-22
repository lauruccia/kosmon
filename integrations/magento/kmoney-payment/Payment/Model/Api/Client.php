<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model\Api;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Thin client for KMoney API v1 (https://<host>/api/v1).
 *
 * Auth: "Authorization: Bearer km_..." token, generated on the KMoney portal
 * under /api-tokens with the "write" ability. Amounts are always integer
 * cents of KY (e.g. 5000 = 50,00 KY) — never send floats.
 */
class Client
{
    public function __construct(
        private readonly ConfigHelper $configHelper,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /**
     * POST /payment-requests
     *
     * Creates a hosted payment request and returns the decoded "data" object,
     * which includes "uuid", "token" and "pay_url" — redirect the customer's
     * browser to pay_url to complete the payment on KMoney's own domain.
     *
     * external_reference should be the Magento order increment_id: if a
     * pending request with the same external_reference + amount already
     * exists, the API returns that one instead of creating a duplicate
     * (safe to call again on checkout retries).
     */
    public function createPaymentRequest(
        int $amountCents,
        string $description,
        string $externalReference,
        string $returnUrl,
        string $cancelUrl,
        int $expiresInMinutes = 30
    ): array {
        return $this->request('POST', '/payment-requests', [
            'amount'             => $amountCents,
            'description'        => $description,
            'external_reference' => $externalReference,
            'return_url'         => $returnUrl,
            'cancel_url'         => $cancelUrl,
            'expires_in_minutes' => $expiresInMinutes,
        ]);
    }

    /**
     * GET /payment-requests/{uuid}
     *
     * Used by the return controller to verify server-side that a payment
     * request is really "paid" before invoicing — never trust the browser
     * redirect query string alone.
     */
    public function getPaymentRequest(string $uuid): array
    {
        return $this->request('GET', '/payment-requests/' . rawurlencode($uuid));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $baseUrl = $this->configHelper->getApiBaseUrl();
        $token   = $this->configHelper->getApiToken();

        if (!$baseUrl || !$token) {
            throw new \RuntimeException('KMoney API is not configured (base URL / token missing).');
        }

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('Accept', 'application/json');
        $curl->setTimeout(20);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);

        $url = $baseUrl . $path;

        if ($method === 'POST') {
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($url, $this->json->serialize($body ?? []));
        } else {
            $curl->get($url);
        }

        $status = (int) $curl->getStatus();
        $rawBody = (string) $curl->getBody();

        $decoded = [];
        if ($rawBody !== '') {
            try {
                $decoded = $this->json->unserialize($rawBody);
            } catch (\Throwable $e) {
                throw new \RuntimeException('KMoney API returned an invalid response (HTTP ' . $status . ').');
            }
        }

        if ($status >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('KMoney API error (' . $status . '): ' . $message);
        }

        return $decoded['data'] ?? $decoded;
    }
}
