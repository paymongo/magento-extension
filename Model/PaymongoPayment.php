<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Model\Method\CaptureAndRefund;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;

/**
 * PayMongo payment method implementation.
 *
 * Registers the payment method with the Magento payment framework, manages the
 * redirect flow to the PayMongo hosted checkout, and handles online capture
 * and refund operations via the PayMongo API.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymongoPayment extends AbstractMethod
{
    /** @var string Payment method code used across the Magento system */
    public const CODE = 'paymongo';

    /** @var string */
    protected $_code = self::CODE;

    /** @var bool Payment is processed via an external gateway */
    protected $_isGateway = true;

    /** @var bool Supports the order action */
    protected $_canOrder = true;

    /** @var bool Supports capture */
    protected $_canCapture = true;

    /** @var bool Partial capture not supported */
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
     * Check whether PayMongo is available for the given quote.
     *
     * Returns false if the method is disabled in config, if either API key is missing,
     * or if the cart currency is not PHP (PayMongo only processes PHP).
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

        if (!$this->configHelper->isUnifiedDisplayMode($storeId)) {
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
     * Return the internal URL path for the payment redirect controller.
     *
     * Magento uses this to redirect the customer after order placement.
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
     * is not fulfilled until payment confirmation is received.
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
