<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Links the store's KMoney account using only the account number (pairing).
 *
 * Same flow as the KMoney WooCommerce plugin: the merchant enters only the
 * KMoney account number (KYB... or KYP...) in the payment method settings
 * and saves. This module then sends a connection request to KMoney
 * (POST /api/v1/ecommerce/pairings) with a claim secret generated here. The
 * circuit administrator approves the request from /admin/companies/{id} on
 * the KMoney side; the next time the configuration page is opened (or
 * saved), this module checks the request status, retrieves the API token
 * and webhook signing secret exactly once (authenticating with the claim
 * secret) and stores them itself, encrypted like any other "obscure"
 * Magento config field. The merchant never copies or pastes a token or a
 * webhook secret.
 */
class Pairing
{
    private const CONFIG_PATH_STATE          = 'payment/kmoney/pairing_state';
    private const CONFIG_PATH_ACCOUNT_NUMBER = 'payment/kmoney/account_number';
    private const CONFIG_PATH_API_TOKEN      = 'payment/kmoney/api_token';
    private const CONFIG_PATH_WEBHOOK_SECRET = 'payment/kmoney/webhook_secret';
    private const PLATFORM                   = 'magento';

    public function __construct(
        private readonly ConfigHelper $configHelper,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Called right after the payment settings are saved. Starts (or
     * restarts) the pairing if an account number was entered but there is
     * no token yet, or if the account number changed since the previous
     * request.
     */
    public function maybeStart(): void
    {
        $accountNumber = $this->normalizeAccountNumber(
            (string) $this->scopeConfig->getValue(self::CONFIG_PATH_ACCOUNT_NUMBER)
        );

        if ($accountNumber === '') {
            $this->clearState();
            return;
        }

        $state = $this->readState();
        $accountChanged = isset($state['account_number']) && $state['account_number'] !== $accountNumber;
        $hasToken = $this->configHelper->getApiToken() !== '';

        // Already linked with this exact account, or a request is already
        // in flight: nothing to do.
        if (!$accountChanged && ($hasToken || (($state['status'] ?? null) === 'pending'))) {
            return;
        }

        $baseUrl = $this->configHelper->getApiBaseUrl();
        if ($baseUrl === '') {
            $this->writeState([
                'status'  => 'error',
                'message' => 'KMoney API base URL is missing.',
            ]);
            return;
        }

        $claimSecret = bin2hex(random_bytes(20));
        $siteUrl = rtrim($this->getStoreBaseUrl(), '/') . '/';
        $webhookUrl = rtrim($siteUrl, '/') . '/kmoney/webhook/index';

        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            $curl->setTimeout(20);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
            $curl->post($baseUrl . '/ecommerce/pairings', $this->json->serialize([
                'account_number' => $accountNumber,
                'site_url'       => $siteUrl,
                'webhook_url'    => $webhookUrl,
                'claim_secret'   => $claimSecret,
                'platform'       => self::PLATFORM,
            ]));

            $status = (int) $curl->getStatus();
            $decoded = $this->decodeBody((string) $curl->getBody());
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney pairing: unable to reach KMoney', ['error' => $e->getMessage()]);
            $this->writeState([
                'account_number' => $accountNumber,
                'status'         => 'error',
                'message'        => 'Could not reach KMoney: ' . $e->getMessage(),
            ]);
            return;
        }

        if ($status === 201 && is_array($decoded) && !empty($decoded['uuid'])) {
            $this->writeState([
                'uuid'           => (string) $decoded['uuid'],
                'claim_secret'   => $claimSecret,
                'account_number' => $accountNumber,
                'status'         => 'pending',
            ]);
            return;
        }

        $message = is_array($decoded) && !empty($decoded['error'])
            ? (string) $decoded['error']
            : ('Unexpected response from KMoney (HTTP ' . $status . ').');

        $this->writeState([
            'account_number' => $accountNumber,
            'status'         => 'error',
            'message'        => $message,
        ]);
    }

    /**
     * Called when the payment settings page is opened. If a request is
     * pending, checks its status with KMoney; if approved, retrieves the
     * credentials and stores them.
     */
    public function maybePoll(): void
    {
        $state = $this->readState();

        if (($state['status'] ?? null) !== 'pending' || empty($state['uuid']) || empty($state['claim_secret'])) {
            return;
        }

        $baseUrl = $this->configHelper->getApiBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->addHeader('Accept', 'application/json');
            $curl->setTimeout(20);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
            $curl->get(
                $baseUrl . '/ecommerce/pairings/' . rawurlencode((string) $state['uuid'])
                . '?claim_secret=' . rawurlencode((string) $state['claim_secret'])
            );

            $http = (int) $curl->getStatus();
            $decoded = $this->decodeBody((string) $curl->getBody());
        } catch (\Throwable $e) {
            // Temporary network error: this will be retried the next time
            // the page is opened.
            $this->logger->warning('Kmoney pairing: poll failed', ['error' => $e->getMessage()]);
            return;
        }

        if (!is_array($decoded)) {
            return;
        }

        if ($http === 404) {
            // The request no longer exists on the KMoney side (e.g. it was
            // replaced by a newer one): starts over on the next save.
            $state['status'] = 'error';
            $state['message'] = 'This connection request is no longer valid: save the settings again to send a new one.';
            $this->writeState($state);
            return;
        }

        $remoteStatus = $decoded['status'] ?? '';

        if ($remoteStatus === 'approved' && !empty($decoded['api_token'])) {
            // One-time delivery: store the credentials right away, encrypted
            // the same way as any other "obscure" field of the payment method.
            $this->writeEncryptedConfig(self::CONFIG_PATH_API_TOKEN, (string) $decoded['api_token']);
            if (!empty($decoded['webhook_secret'])) {
                $this->writeEncryptedConfig(self::CONFIG_PATH_WEBHOOK_SECRET, (string) $decoded['webhook_secret']);
            }

            $this->writeState([
                'account_number' => $state['account_number'] ?? '',
                'status'         => 'linked',
                'just_linked'    => true,
            ]);
            return;
        }

        if ($remoteStatus === 'approved' && !empty($decoded['claimed'])) {
            // Credentials were already retrieved elsewhere (e.g. another
            // installation) but are not present here: a new pairing is needed.
            $state['status'] = 'error';
            $state['message'] = 'The credentials for this connection have already been retrieved elsewhere. Save the settings again to send a new connection request.';
            $this->writeState($state);
            return;
        }

        if ($remoteStatus === 'rejected') {
            $state['status'] = 'rejected';
            $state['message'] = 'The circuit administrator rejected the connection request. Check the account number or contact KMoney support.';
            $this->writeState($state);
        }
        // "pending": nothing changed, this will be retried on the next page load.
    }

    /**
     * Notice to show at the top of the configuration page (the success
     * notice is shown only once). Returns null if there is nothing to show.
     *
     * @return array{type: string, message: string}|null
     */
    public function consumeNotice(): ?array
    {
        $state = $this->readState();

        if (empty($state['status'])) {
            return null;
        }

        if ($state['status'] === 'pending') {
            return [
                'type'    => 'warning',
                'message' => (string) __(
                    'KMoney: the connection request for account %1 is awaiting approval from the circuit administrator. Once approved, this module configures itself automatically — reopen this page to check.',
                    $state['account_number'] ?? ''
                ),
            ];
        }

        if ($state['status'] === 'linked' && !empty($state['just_linked'])) {
            unset($state['just_linked']);
            $this->writeState($state);

            return [
                'type'    => 'success',
                'message' => (string) __('KMoney: account connected! The API token and webhook were configured automatically.'),
            ];
        }

        if (in_array($state['status'], ['error', 'rejected'], true)) {
            return [
                'type'    => 'error',
                'message' => (string) __('KMoney: the connection could not be completed. %1', $state['message'] ?? ''),
            ];
        }

        return null;
    }

    private function normalizeAccountNumber(string $accountNumber): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $accountNumber));
    }

    private function getStoreBaseUrl(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function clearState(): void
    {
        $this->configWriter->delete(self::CONFIG_PATH_STATE);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::CONFIG_PATH_STATE);
        if ($raw === '') {
            return [];
        }

        try {
            $decrypted = $this->encryptor->decrypt($raw);
            $decoded = $this->json->unserialize($decrypted);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $encrypted = $this->encryptor->encrypt($this->json->serialize($state));
        $this->configWriter->save(self::CONFIG_PATH_STATE, $encrypted, 'default', 0);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    private function writeEncryptedConfig(string $path, string $value): void
    {
        $this->configWriter->save($path, $this->encryptor->encrypt($value), 'default', 0);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    /** @return array<string, mixed>|null */
    private function decodeBody(string $rawBody): ?array
    {
        if ($rawBody === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($rawBody);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
