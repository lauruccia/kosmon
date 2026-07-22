<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * KMoney payment method.
 *
 * This is intentionally a thin, offline-style method: it never authorizes or
 * captures anything itself. The real payment happens on the KMoney hosted
 * checkout page (see Controller/Redirect/Index), and the order is invoiced
 * only after KMoney confirms the payment via webhook or via the return
 * controller's server-side verification (see Model/OrderFinalizer).
 */
class Kmoney extends AbstractMethod
{
    public const CODE = 'kmoney';

    protected $_code = self::CODE;

    protected $_isOffline = false;
    protected $_isInitializeNeeded = false;

    protected $_canOrder = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canCapturePartial = false;
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canFetchTransactionInfo = false;
}
