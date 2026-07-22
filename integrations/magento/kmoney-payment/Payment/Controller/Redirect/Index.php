<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Redirect;

use Kmoney\Payment\Model\Api\Client;
use Kmoney\Payment\Model\Kmoney as KmoneyMethod;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /kmoney/redirect/index
 *
 * Called by the checkout JS right after the order is placed
 * (see view/frontend/web/js/view/payment/method-renderer/kmoney-method.js,
 * afterPlaceOrder()). Creates the KMoney payment request for the last order
 * in the checkout session and 302-redirects the browser to the hosted
 * payment page (pay_url) on the KMoney domain, where the customer logs in
 * with their own KMoney credentials (2FA/passkey) and confirms the amount.
 *
 * This store never sees or handles the customer's KMoney credentials.
 */
class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RedirectFactory $redirectFactory,
        private readonly Client $apiClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId() || $order->getPayment()->getMethod() !== KmoneyMethod::CODE) {
            $this->messageManager->addErrorMessage(__('Unable to start the KMoney payment for this order.'));
            return $resultRedirect->setPath('checkout/cart');
        }

        // KMoney amounts are integer cents of KY; Magento's grand total is a
        // decimal in the order currency. This assumes a 1:1 KY <> store
        // currency mapping — adjust the conversion here if that is not the case.
        $amountCents = (int) round(((float) $order->getGrandTotal()) * 100);

        try {
            $paymentRequest = $this->apiClient->createPaymentRequest(
                amountCents: $amountCents,
                description: (string) __('Order #%1', $order->getIncrementId()),
                externalReference: (string) $order->getIncrementId(),
                returnUrl: $this->_url->getUrl('kmoney/return/index', ['_secure' => true]),
                cancelUrl: $this->_url->getUrl('checkout/cart', ['_secure' => true])
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney: unable to create payment request', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);

            $order->addCommentToStatusHistory(
                __('KMoney: unable to create the payment request (%1). Customer was sent back to the cart.', $e->getMessage())
            );
            $this->orderRepository->save($order);

            $this->messageManager->addErrorMessage(
                __('KMoney payment is currently unavailable. Please choose another payment method.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        if (empty($paymentRequest['pay_url'])) {
            $this->logger->error('Kmoney: createPaymentRequest response has no pay_url', ['order' => $order->getIncrementId()]);
            $this->messageManager->addErrorMessage(__('KMoney payment is currently unavailable. Please choose another payment method.'));
            return $resultRedirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('kmoney_pr_uuid', $paymentRequest['uuid'] ?? null);
        $payment->setAdditionalInformation('kmoney_pr_token', $paymentRequest['token'] ?? null);

        $order->addCommentToStatusHistory(
            __('KMoney: payment request %1 created, customer redirected to KMoney to pay.', $paymentRequest['uuid'] ?? '?')
        );
        $this->orderRepository->save($order);

        return $resultRedirect->setUrl($paymentRequest['pay_url']);
    }
}
