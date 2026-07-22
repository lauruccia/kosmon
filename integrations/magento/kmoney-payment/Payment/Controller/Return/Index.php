<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Return;

use Kmoney\Payment\Model\Api\Client;
use Kmoney\Payment\Model\OrderFinalizer;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

/**
 * GET /kmoney/return/index
 *
 * KMoney redirects the customer's browser back here after a successful
 * payment (return_url passed when the payment request was created), with
 * kmoney_status / kmoney_pr_uuid / kmoney_transfer_uuid query parameters.
 *
 * IMPORTANT: those query parameters are NOT trusted on their own — anyone
 * could craft a matching URL. This controller always calls back to the
 * KMoney API (GET /payment-requests/{uuid}) to verify the real status
 * server-side before invoicing the order. The webhook controller
 * (Controller/Webhook/Index) is the fully authoritative confirmation and
 * will do the same thing independently if this controller is never hit
 * (e.g. the customer closes the tab before the redirect completes).
 */
class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly Client $apiClient,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->redirectFactory->create();
        $prUuid = (string) $this->getRequest()->getParam('kmoney_pr_uuid', '');
        $order = $this->checkoutSession->getLastRealOrder();

        if ($prUuid === '' || !$order || !$order->getId()) {
            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            $paymentRequest = $this->apiClient->getPaymentRequest($prUuid);
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney: unable to verify payment request on return', [
                'pr_uuid' => $prUuid,
                'order'   => $order->getIncrementId(),
                'error'   => $e->getMessage(),
            ]);
            $this->messageManager->addNoticeMessage(
                __('We could not confirm your KMoney payment yet. If the payment succeeded, your order will be updated automatically within a few minutes.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        $isPaid = ($paymentRequest['status'] ?? null) === 'paid';
        $matchesOrder = ($paymentRequest['external_reference'] ?? null) === $order->getIncrementId();

        if ($isPaid && $matchesOrder) {
            $this->orderFinalizer->markPaid($order, $paymentRequest);
            return $resultRedirect->setPath('checkout/onepage/success');
        }

        $this->messageManager->addNoticeMessage(
            __('Your KMoney payment was not completed. You can try again or choose another payment method.')
        );
        return $resultRedirect->setPath('checkout/cart');
    }
}
