<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/**
 * Turns a confirmed KMoney payment_request.paid event into a Magento invoice.
 *
 * Shared by Controller/Return/Index (customer comes back from KMoney) and
 * Controller/Webhook/Index (server-to-server notification). Both paths can
 * race each other, so this is written to be idempotent: it does nothing if
 * the order is already invoiced.
 */
class OrderFinalizer
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $dbTransaction,
        private readonly InvoiceSender $invoiceSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Order $order
     * @param array $paymentRequest Decoded "PaymentRequest" payload from the KMoney API
     *                              (either the GET /payment-requests/{uuid} response, or
     *                              the "payload" object of a payment_request.paid webhook).
     */
    public function markPaid(Order $order, array $paymentRequest): void
    {
        if ($order->hasInvoices() || !$order->canInvoice()) {
            // Already handled by the other path (return controller vs. webhook), or
            // the order is in a state that no longer accepts an invoice. Not an error.
            return;
        }

        $transferUuid = $paymentRequest['transfer_uuid'] ?? ($paymentRequest['uuid'] ?? null);

        $payment = $order->getPayment();
        if ($payment && $transferUuid) {
            $payment->setTransactionId($transferUuid);
            $payment->setAdditionalInformation('kmoney_transfer_uuid', $transferUuid);
            $payment->setIsTransactionClosed(true);
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->getOrder()->setIsInProcess(true);

        $this->dbTransaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        try {
            $this->invoiceSender->send($invoice);
        } catch (\Throwable $e) {
            // Never fail order finalization because of a broken email transport.
            $this->logger->warning('Kmoney: invoice email not sent', ['error' => $e->getMessage()]);
        }

        $order->addCommentToStatusHistory(
            __('KMoney: payment confirmed, order invoiced (transfer %1).', $transferUuid ?? 'n/a')
        )->setIsCustomerNotified(true)->save();
    }
}
