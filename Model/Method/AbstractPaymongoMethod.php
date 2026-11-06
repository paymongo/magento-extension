<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

use PayMongo\Payment\Helper\Config;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Abstract base class for all individual PayMongo payment methods.
 *
 * Each concrete subclass represents one PayMongo payment method type
 * (e.g. GCash, GrabPay, Card) and declares only its method code and the
 * corresponding PayMongo API payment_method_type string. All shared behaviour —
 * availability checks, redirect URL, order initialisation, capture, and refund —
 * lives here and is inherited by every method.
 *
 * Shared API credentials (secret key, public key, webhook secret) are read from
 * the parent payment/paymongo/* config scope via the Config helper.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
abstract class AbstractPaymongoMethod extends AbstractMethod
{
    /**
     * The PayMongo API payment_method_type string for this method.
     * Must be overridden in every subclass.
     *
     * @var string
     */
    protected string $paymongoMethodType = '';

    /** @var bool Payment is processed via an external hosted gateway */
    protected $_isGateway = true;

    /** @var bool Supports the order action */
    protected $_canOrder = true;

    /** @var bool Supports capture */
    protected $_canCapture = true;

    /** @var bool Partial capture not supported (PayMongo hosted checkout is all-or-nothing) */
    protected $_canCapturePartial = false;

    /** @var bool Supports online refunds */
    protected $_canRefund = true;

    /** @var bool Supports partial invoice refunds */
    protected $_canRefundInvoicePartial = true;

    /** @var bool Available at the storefront checkout */
    protected $_canUseCheckout = true;

    /** @var bool Not available for admin orders */
    protected $_canUseInternal = false;

    /** @var bool Requires initialization (sets order state before redirect) */
    protected $_isInitializeNeeded = true;

    /** @var bool Payment is online, not offline */
    protected $_isOffline = false;

    /** @var Config */
    private Config $configHelper;

    /** @var CaptureAndRefund */
    private CaptureAndRefund $captureAndRefund;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentHelper $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param Config $configHelper
     * @param CaptureAndRefund $captureAndRefund
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        Config $configHelper,
        CaptureAndRefund $captureAndRefund,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->configHelper = $configHelper;
        $this->captureAndRefund = $captureAndRefund;
    }

    /**
     * Return the PayMongo API payment_method_type string for this method.
     *
     * Used by the Redirect controller to build a checkout session that only
     * presents the specific payment method the customer selected.
     *
     * @return string e.g. 'gcash', 'grab_pay', 'card'
     */
    public function getPaymongoMethodType(): string
    {
        return $this->paymongoMethodType;
    }

    /**
     * Return the PayMongo payment_method_types to offer on this method's Checkout Session.
     *
     * One type for every method except the retired Direct Online Banking, which can
     * offer both of PayMongo's DOB banks ('dob' is BPI, 'dob_ubp' is UnionBank) to the
     * orders placed with it.
     *
     * @return string[]
     */
    public function getCheckoutSessionTypes(): array
    {
        return $this->paymongoMethodType !== '' ? [$this->paymongoMethodType] : [];
    }

    /**
     * Check whether this PayMongo method is available for the given quote.
     *
     * Returns false if the method is disabled in config, if the cart currency is
     * not PHP (PayMongo only processes PHP), or if shared API keys are missing.
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        $storeId = $quote !== null ? (int) $quote->getStoreId() : null;

        if (!$this->configHelper->isModuleEnabled($storeId)) {
            return false;
        }

        if ($this->configHelper->isUnifiedDisplayMode($storeId)) {
            return false;
        }

        if (empty($this->configHelper->getSecretKey($storeId)) || empty($this->configHelper->getPublicKey($storeId))) {
            return false;
        }

        if ($quote !== null && $quote->getCurrency()->getQuoteCurrencyCode() !== 'PHP') {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * Apply the shared "Payment from Applicable Countries" restriction to this method.
     *
     * AbstractMethod::canUseForCountry() reads payment/<code>/allowspecific — here that
     * is payment/paymongo_gcash/allowspecific and friends, which etc/adminhtml/system.xml
     * exposes no field for and etc/config.xml leaves at 0. The one country control the
     * admin actually has lives under General > Shared Credentials on the shared
     * payment/paymongo scope, so without this override it governed the all-in-one
     * "paymongo" method alone and every individual method stayed available worldwide —
     * i.e. the restriction silently did nothing in Separate mode, which is the default.
     *
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country): bool
    {
        $storeId = $this->getStore() !== null && $this->getStore() !== ''
            ? (int) $this->getStore()
            : null;

        if (!$this->configHelper->isRestrictedToSpecificCountries($storeId)) {
            return true;
        }

        return in_array((string) $country, $this->configHelper->getAllowedCountries($storeId), true);
    }

    /**
     * Return the internal URL path for the payment redirect controller.
     *
     * Magento uses this to redirect the customer after order placement on Luma checkout.
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return 'paymongo/payment/redirect';
    }

    /**
     * Initialize the order state and status before the customer is redirected to PayMongo.
     *
     * Sets the order to PENDING_PAYMENT with the configured status so that the order
     * is not fulfilled until payment confirmation is received via webhook or success callback.
     *
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject): self
    {
        $stateObject->setData('state', \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $this->configHelper->getOrderStatus() ?: 'pending');
        $stateObject->setData('is_notified', false);

        return $this;
    }

    /**
     * Capture the invoice online: record its PayMongo transaction, taking a card hold's money first.
     *
     * See CaptureAndRefund::capture(), shared by every PayMongo method (PYM-33).
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException when PayMongo does not capture the hold
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->captureAndRefund->capture($payment, (float) $amount);

        return $this;
    }

    /**
     * Refund the payment through the PayMongo API (online refund through a credit memo).
     *
     * See CaptureAndRefund::refund(), shared by every PayMongo method (PYM-33).
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->captureAndRefund->refund($payment, (float) $amount);

        return $this;
    }
}
