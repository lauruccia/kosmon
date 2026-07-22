<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Webhook;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Kmoney\Payment\Model\OrderFinalizer;
use Psr\Log\LoggerInterface;

/**
 * POST /kmoney/webhook/index
 *
 * Receives "payment_request.paid" webhooks pushed by kmoney-app
 * (see Webhook::EVENTS / SendWebhookJob in the KMoney codebase). This is the
 * authoritative, asynchronous confirmation that a payment succeeded — do not
 * rely on the customer's browser redirect alone (Controller/Return/Index)
 * because the customer may never come back to this domain.
 *
 * Register the webhook on the KMoney portal under Settings > Webhooks:
 *   URL:    https://your-store.com/kmoney/webhook/index
 *   Events: payment_request.paid
 * Copy the "secret" shown once at creation time into
 * Stores > Configuration > Sales > Payment Methods > KMoney > Webhook signing secret.
 *
 * This endpoint is called server-to-server by kmoney-app with no Magento
 * session / form key, so CSRF validation is explicitly disabled below;
 * the HMAC signature check is what protects it instead.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ConfigHelper $configHelper,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $rawBody = (string) $this->getRequest()->getContent();
        $signatureHeader = (string) $this->getRequest()->getHeader('X-KMoney-Signature');
        $secret = $this->configHelper->getWebhookSecret();

        if ($secret === '') {
            $this->logger->error('Kmoney webhook: no webhook secret configured in Magento admin');
            return $result->setHttpResponseCode(500)->setData(['error' => 'webhook not configured']);
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        if ($signatureHeader === '' || !hash_equals($expectedSignature, $signatureHeader)) {
            $this->logger->warning('Kmoney webhook: invalid or missing signature');
            return $result->setHttpResponseCode(401)->setData(['error' => 'invalid signature']);
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || ($payload['event'] ?? null) !== 'payment_request.paid') {
            // Acknowledge (200) so KMoney does not retry events this endpoint
            // does not care about — only report a real error for our own event.
            return $result->setHttpResponseCode(200)->setData(['ignored' => true]);
        }

        $paymentRequest = $payload['payload'] ?? [];
        $externalReference = $paymentRequest['external_reference'] ?? null;

        if (!$externalReference) {
            return $result->setHttpResponseCode(200)->setData(['ignored' => true, 'reason' => 'no external_reference']);
        }

        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $externalReference)
            ->setPageSize(1)
            ->getFirstItem();

        if (!$order || !$order->getId()) {
            $this->logger->warning('Kmoney webhook: no matching order', ['external_reference' => $externalReference]);
            return $result->setHttpResponseCode(200)->setData(['ignored' => true, 'reason' => 'order not found']);
        }

        try {
            $this->orderFinalizer->markPaid($order, $paymentRequest);
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney webhook: order finalization failed', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            // Non-2xx so kmoney-app's SendWebhookJob retries (it retries up to 3 times).
            return $result->setHttpResponseCode(500)->setData(['error' => 'processing failed']);
        }

        return $result->setHttpResponseCode(200)->setData(['ok' => true]);
    }
}
