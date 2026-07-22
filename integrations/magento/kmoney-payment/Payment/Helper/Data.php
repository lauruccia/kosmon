<?php

declare(strict_types=1);

namespace Kmoney\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    private const XML_PATH_API_BASE_URL   = 'payment/kmoney/api_base_url';
    private const XML_PATH_API_TOKEN      = 'payment/kmoney/api_token';
    private const XML_PATH_WEBHOOK_SECRET = 'payment/kmoney/webhook_secret';
    private const XML_PATH_ACCOUNT_NUMBER = 'payment/kmoney/account_number';

    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        $url = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return rtrim($url, '/');
    }

    /**
     * Both the account-number pairing (Model\Pairing) and a merchant pasting
     * a token manually store it through Magento's "obscure" field encryption
     * (backend_model Encrypted on save). Decrypt here so callers always get
     * the plain Bearer token.
     */
    public function getApiToken(?int $storeId = null): string
    {
        return $this->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_PATH_API_TOKEN, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        return $this->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_SECRET, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    /**
     * KMoney account number entered by the merchant (KYB.../KYP...). It is
     * the only thing the merchant has to provide: the actual connection
     * (API token + webhook secret) is retrieved automatically — see
     * Model\Pairing.
     */
    public function getAccountNumber(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ACCOUNT_NUMBER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $decrypted = $this->encryptor->decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }

        // A value that fails to decrypt into something meaningful is
        // treated as already-plain (e.g. a fresh install before the first
        // encrypted save) rather than silently discarded.
        return $decrypted !== '' ? $decrypted : $value;
    }
}
