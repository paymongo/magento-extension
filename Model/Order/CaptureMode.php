<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Model\Config\Source\PaymentAction;
use PayMongo\Payment\Model\Method\Card;
use PayMongo\Payment\Model\PaymongoPayment;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;

/**
 * Decides whether a PayMongo payment is held for capture on shipment (Authorize Only)
 * or charged straight away, and remembers that decision on the order.
 *
 * Authorize Only is a setting on the standalone Credit / Debit Card method, and only
 * that method honours it. All-in-One Checkout always captures immediately: PayMongo
 * cannot mix a manual-capture hold with other methods in one hosted session, and an
 * account without Hold Then Capture enabled rejects the hold outright with
 * organization_not_allowed (PYM-24). Before 2.0.1 the all-in-one method inherited the
 * Card setting, narrowed its session to card-only and requested the hold anyway.
 *
 * The decision is made once, when the checkout session is created, and stored on the
 * payment. Everything that runs later — the success return, the webhook, the admin
 * Refresh button, reconciliation and capture on shipment — reads it back from there
 * instead of re-deriving it from config, so changing Payment Action or the display
 * mode while an order is in flight cannot flip how that order is treated.
 *
 * Orders whose session was created before this class existed carry no stored
 * decision. For those, isAuthorizeOnly() falls back to the rule they were created
 * under, which included the all-in-one method — so an All-in-One order already
 * sitting on an authorisation hold at upgrade is still captured when it ships.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CaptureMode
{
    /**
     * Payment additional_information key holding the decision made at session creation.
     */
    public const ADDITIONAL_INFO_KEY = 'paymongo_authorize_only';

    /** @var Config */
    private Config $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Whether a new checkout session for this method should request an authorisation hold.
     *
     * True only for the standalone Card method with Payment Action set to Authorize Only.
     * The all-in-one method is deliberately excluded, whatever the Card setting is.
     *
     * @param string $methodCode Magento payment method code
     * @param int $storeId
     * @return bool
     */
    public function shouldAuthorizeOnly(string $methodCode, int $storeId): bool
    {
        return $methodCode === Card::CODE
            && $this->config->getCardPaymentAction($storeId) === PaymentAction::AUTHORIZE;
    }

    /**
     * Store the decision on the payment so later steps read it instead of re-deriving it.
     *
     * The caller saves the order.
     *
     * @param OrderPaymentInterface $payment
     * @param bool $authorizeOnly
     * @return void
     */
    public function record(OrderPaymentInterface $payment, bool $authorizeOnly): void
    {
        $payment->setAdditionalInformation(self::ADDITIONAL_INFO_KEY, $authorizeOnly);
    }

    /**
     * Whether this order's payment is an authorisation held for capture on shipment.
     *
     * Uses the decision stored at session creation. Orders created before it was stored
     * fall back to the rule they were created under: the Card or all-in-one method with
     * Payment Action set to Authorize Only.
     *
     * @param Order $order
     * @return bool
     */
    public function isAuthorizeOnly(Order $order): bool
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return false;
        }

        $recorded = $payment->getAdditionalInformation(self::ADDITIONAL_INFO_KEY);
        if ($recorded !== null) {
            return (bool) $recorded;
        }

        return in_array($payment->getMethod(), [Card::CODE, PaymongoPayment::CODE], true)
            && $this->config->getCardPaymentAction((int) $order->getStoreId()) === PaymentAction::AUTHORIZE;
    }
}
