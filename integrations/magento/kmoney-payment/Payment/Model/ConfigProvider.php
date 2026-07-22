<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Escaper;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Exposes the KMoney title/instructions to the checkout JS layer
 * (window.checkoutConfig.payment.kmoney).
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly PaymentHelper $paymentHelper,
        private readonly Escaper $escaper
    ) {
    }

    public function getConfig(): array
    {
        $method = $this->paymentHelper->getMethodInstance(Kmoney::CODE);

        if (!$method->isAvailable()) {
            return [];
        }

        return [
            'payment' => [
                Kmoney::CODE => [
                    'title'        => $this->escaper->escapeHtml((string) $method->getTitle()),
                    'instructions' => $this->escaper->escapeHtml((string) $method->getConfigData('instructions')),
                ],
            ],
        ];
    }
}
