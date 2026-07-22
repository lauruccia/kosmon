<?php

declare(strict_types=1);

namespace Kmoney\Payment\Observer;

use Kmoney\Payment\Model\Pairing;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * Fires when Stores > Configuration > Sales > Payment Methods is opened.
 * If a KMoney account pairing is pending, checks its status; once approved,
 * the API token and webhook secret were already retrieved and stored by
 * Pairing::maybePoll() — this observer only surfaces the resulting notice
 * (pending / connected / failed) to the admin.
 */
class PollPairingOnConfigEdit implements ObserverInterface
{
    public function __construct(
        private readonly Pairing $pairing,
        private readonly RequestInterface $request,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->request->getParam('section') !== 'payment') {
            return;
        }

        $this->pairing->maybePoll();

        $notice = $this->pairing->consumeNotice();
        if ($notice === null) {
            return;
        }

        switch ($notice['type']) {
            case 'success':
                $this->messageManager->addSuccessMessage($notice['message']);
                break;
            case 'warning':
                $this->messageManager->addWarningMessage($notice['message']);
                break;
            default:
                $this->messageManager->addErrorMessage($notice['message']);
                break;
        }
    }
}
