<?php

declare(strict_types=1);

namespace Kmoney\Payment\Observer;

use Kmoney\Payment\Model\Pairing;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Fires after the admin saves Stores > Configuration > Sales > Payment
 * Methods. If an account number was entered but there is no API token yet
 * (or the account number changed), starts the KMoney account pairing —
 * same behavior as the KMoney WooCommerce plugin: no token or webhook
 * secret to copy and paste.
 */
class StartPairingOnConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly Pairing $pairing,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->request->getParam('section') !== 'payment') {
            return;
        }

        $this->pairing->maybeStart();
    }
}
