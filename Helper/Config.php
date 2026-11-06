<?php

declare(strict_types=1);

namespace PayMongo\Payment\Helper;

use PayMongo\Payment\Model\Config\Source\Environment;
use PayMongo\Payment\Model\Config\Source\PaymentDisplayMode;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration helper for the PayMongo payment method.
 *
 * Reads store-scoped config values from Stores → Configuration → Payment Methods → PayMongo
 * and decrypts the encrypted API keys before returning them.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'payment/paymongo/enabled';
    private const XML_PATH_TITLE = 'payment/paymongo/title';
    private const XML_PATH_DISPLAY_MODE = 'payment/paymongo/display_mode';
    private const XML_PATH_SHOW_ICONS = 'payment/paymongo/show_icons';
    private const XML_PATH_ENVIRONMENT = 'payment/paymongo/environment';
    private const XML_PATH_ORDER_STATUS = 'payment/paymongo/order_status';
    private const XML_PATH_CARD_PAYMENT_ACTION = 'payment/paymongo_card/payment_action';
    private const XML_PATH_ALLOW_SPECIFIC = 'payment/paymongo/allowspecific';
    private const XML_PATH_SPECIFIC_COUNTRY = 'payment/paymongo/specificcountry';

    private const XML_PATH_SANDBOX_SECRET_KEY = 'payment/paymongo/sandbox_secret_key';
    private const XML_PATH_SANDBOX_PUBLIC_KEY = 'payment/paymongo/sandbox_public_key';
    private const XML_PATH_SANDBOX_WEBHOOK_SECRET = 'payment/paymongo/sandbox_webhook_secret';
    private const XML_PATH_PRODUCTION_SECRET_KEY = 'payment/paymongo/production_secret_key';
    private const XML_PATH_PRODUCTION_PUBLIC_KEY = 'payment/paymongo/production_public_key';
    private const XML_PATH_PRODUCTION_WEBHOOK_SECRET = 'payment/paymongo/production_webhook_secret';

    /** @var EncryptorInterface */
    private EncryptorInterface $encryptor;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    /**
     * Check whether the PayMongo module is enabled at all — the master switch.
     *
     * When false, no PayMongo payment option (individual or all-in-one) should
     * be available at checkout regardless of display mode or per-method toggles.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isModuleEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the configured payment display mode: 'separate' or 'unified'.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDisplayMode(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: PaymentDisplayMode::SEPARATE;
    }

    /**
     * Check whether checkout should show a single all-in-one PayMongo option
     * instead of each individually-enabled method.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isUnifiedDisplayMode(?int $storeId = null): bool
    {
        return $this->getDisplayMode($storeId) === PaymentDisplayMode::UNIFIED;
    }

    /**
     * Whether PayMongo is limited to the countries listed by getAllowedCountries().
     *
     * This is the "Payment from Applicable Countries" setting under General > Shared
     * Credentials. It is deliberately read from the shared payment/paymongo scope for
     * every method — see AbstractPaymongoMethod::canUseForCountry().
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isRestrictedToSpecificCountries(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ALLOW_SPECIFIC,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The country codes PayMongo may be offered in, when the restriction is on.
     *
     * Stored by the multiselect as a comma-separated list; an empty selection means no
     * country qualifies, which is why the caller must check
     * isRestrictedToSpecificCountries() first rather than treating [] as "no limit".
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getAllowedCountries(?int $storeId = null): array
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SPECIFIC_COUNTRY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($configured === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured)), 'strlen'));
    }

    /**
     * Whether to show an icon next to each PayMongo method at checkout (Hyvä Checkout and Luma).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function showPaymentIcons(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_ICONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the payment method title shown to customers at checkout.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the configured environment: 'sandbox' (default) or 'production'.
     *
     * Determines which of the two stored credential sets getSecretKey(),
     * getPublicKey(), and getWebhookSecret() actually return.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEnvironment(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: Environment::SANDBOX;
    }

    /**
     * Whether the module is currently configured for the Production (live) environment.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isProductionEnvironment(?int $storeId = null): bool
    {
        return $this->getEnvironment($storeId) === Environment::PRODUCTION;
    }

    /**
     * Return the decrypted PayMongo secret key (sk_test_… or sk_live_…) for whichever
     * environment is currently configured.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSecretKey(?int $storeId = null): string
    {
        $path = $this->isProductionEnvironment($storeId)
            ? self::XML_PATH_PRODUCTION_SECRET_KEY
            : self::XML_PATH_SANDBOX_SECRET_KEY;

        $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $this->decryptValue($value);
    }

    /**
     * Return the decrypted PayMongo public key (pk_test_… or pk_live_…) for whichever
     * environment is currently configured.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPublicKey(?int $storeId = null): string
    {
        $path = $this->isProductionEnvironment($storeId)
            ? self::XML_PATH_PRODUCTION_PUBLIC_KEY
            : self::XML_PATH_SANDBOX_PUBLIC_KEY;

        $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $this->decryptValue($value);
    }

    /**
     * Decrypt value from encrypted config (Secret/Public key use Backend\Encrypted).
     * Returns trimmed key so PayMongo receives correct format (sk_test_... or sk_live_...).
     */
    private function decryptValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = trim($value);
        try {
            $decrypted = $this->encryptor->decrypt($value);
            return $decrypted !== null ? trim((string) $decrypted) : $value;
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Return the order status to set on new PayMongo orders (before payment confirmation).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOrderStatus(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ORDER_STATUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the card payment action: 'authorize_capture' (default) or 'authorize'.
     *
     * 'authorize' creates a manual-capture checkout session; the card is charged
     * only when the merchant creates a shipment. Cards only — all other methods
     * always capture immediately.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCardPaymentAction(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CARD_PAYMENT_ACTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'authorize_capture';
    }

    /**
     * Return the decrypted PayMongo webhook secret used for HMAC-SHA256 signature
     * verification, for whichever environment is currently configured.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWebhookSecret(?int $storeId = null): string
    {
        $path = $this->isProductionEnvironment($storeId)
            ? self::XML_PATH_PRODUCTION_WEBHOOK_SECRET
            : self::XML_PATH_SANDBOX_WEBHOOK_SECRET;

        $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $this->decryptValue($value);
    }
}
