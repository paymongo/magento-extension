<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use PayMongo\Payment\Logger\Logger;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Model\Order;

/**
 * Alerts the admin when PayMongo took a payment for an order Magento has cancelled.
 *
 * The customer has been charged, but the store will not fulfil the order, so the merchant
 * must refund them or reinstate the order. Before PYM-32 this was only a warning in the
 * PayMongo log, which nobody reads: a customer whose first card was declined could retry
 * on PayMongo's page and pay for an order the declined attempt had already cancelled.
 *
 * The alert is an order comment and a critical message in the admin's System Messages, so it
 * is seen even when no admin is logged in at the time (the webhook and cron have no session).
 * It is raised once per checkout: the webhook's two events and the customer's return to the
 * store can all report the same payment. A card held for a cancelled order (Authorize Only)
 * gets an alert worded for a hold.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaidCancelledOrder
{
    /** PayMongo took the customer's money. */
    private const TAKEN_CHARGED = 'charged';

    /** PayMongo holds the customer's card (Authorize Only). */
    private const TAKEN_HELD = 'held';

    /** PayMongo neither took nor holds anything: a hold already released, for example. */
    private const TAKEN_NONE = 'none';

    /**
     * Payment additional_information key holding the checkout (payment intent) already alerted about.
     */
    public const ALERT_KEY = 'paymongo_paid_after_cancel';

    /** @var NotifierInterface */
    private NotifierInterface $notifier;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param NotifierInterface $notifier
     * @param PaymentConfirmation $paymentConfirmation
     * @param Logger $logger
     */
    public function __construct(
        NotifierInterface $notifier,
        PaymentConfirmation $paymentConfirmation,
        Logger $logger
    ) {
        $this->notifier = $notifier;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->logger = $logger;
    }

    /**
     * Alert the admin that PayMongo took a payment, or holds the card, for this cancelled order.
     *
     * Raised once per checkout: the alert is keyed by the order's checkout session (or, for an
     * order without one, its payment intent), which every report of the same payment shares,
     * whatever ID the reporter knows. The caller saves the order.
     *
     * @param Order $order
     * @param string|null $paymentId The PayMongo payment (pay_…), if known
     * @param bool|null $held Whether PayMongo only holds the card rather than charged it; null to
     *        ask PayMongo, for a caller that cannot tell (an Authorize Only order may be either).
     *        Asked, PayMongo may report neither, a hold already released for example: then the
     *        customer was not charged and no alert is raised.
     * @return bool Whether an alert was raised (false if this checkout was already reported,
     *         or PayMongo reports nothing taken or held)
     */
    public function alert(Order $order, ?string $paymentId, ?bool $held = null): bool
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return false;
        }

        $intentId = (string) $payment->getAdditionalInformation('paymongo_payment_intent_id');
        $sessionId = (string) $payment->getAdditionalInformation('paymongo_checkout_session_id');
        $key = $sessionId ?: $intentId ?: (string) $paymentId ?: '-';
        if ($payment->getAdditionalInformation(self::ALERT_KEY) === $key) {
            return false;
        }
        if ($held === null) {
            $taken = $this->paymentTaken($order, $intentId);
            if ($taken === self::TAKEN_NONE) {
                $this->logger->info('PaidCancelledOrder: PayMongo holds no payment for the cancelled order', [
                    'order_id' => $order->getIncrementId(),
                    'payment'  => $paymentId ?: $intentId,
                ]);
                return false;
            }
            $held = $taken === self::TAKEN_HELD;
        }
        $payment->setAdditionalInformation(self::ALERT_KEY, $key);

        $reference = $paymentId ?: $intentId ?: $sessionId ?: (string) __('unknown');

        $this->logger->critical(
            $held ? 'PayMongo holds a card for a cancelled order' : 'PayMongo took a payment for a cancelled order',
            ['order_id' => $order->getIncrementId(), 'payment' => $reference]
        );

        if ($held) {
            $order->addCommentToStatusHistory(__(
                'ACTION NEEDED: PayMongo is holding the customer\'s card (%1) for this order, but the order is '
                . 'cancelled in Magento. Cancel the payment in the PayMongo Dashboard to release the hold, or '
                . 'contact the customer. Otherwise PayMongo releases it %2 days after the card was authorised.',
                $reference,
                AuthorizationExpiry::HOLD_DAYS
            ));
            $title = __('PayMongo card hold on cancelled order #%1', $order->getIncrementId());
            $description = __(
                'PayMongo is holding the customer\'s card (%1) for order #%2, which is cancelled in Magento. '
                . 'Release the hold in the PayMongo Dashboard, or contact the customer. See the order comments.',
                $reference,
                $order->getIncrementId()
            );
        } else {
            $order->addCommentToStatusHistory(__(
                'ACTION NEEDED: PayMongo took the customer\'s payment (%1) for this order, but the order is '
                . 'cancelled in Magento, so it will not be fulfilled. Refund the customer in the PayMongo '
                . 'Dashboard, or contact them and place a new order for them.',
                $reference
            ));
            $title = __('PayMongo payment received for cancelled order #%1', $order->getIncrementId());
            $description = __(
                'PayMongo took the customer\'s payment (%1) for order #%2, which is cancelled in Magento, so the '
                . 'customer has paid for an order the store will not fulfil. Refund them in the PayMongo Dashboard, '
                . 'or contact them and place a new order. See the order comments.',
                $reference,
                $order->getIncrementId()
            );
        }

        $this->notifier->addCritical((string) $title, (string) $description);

        return true;
    }

    /**
     * What PayMongo took for the payment intent: a charge, a hold, or nothing.
     *
     * When PayMongo cannot be asked, or there is no intent to ask about, the payment is reported
     * as taken: the admin then checks the PayMongo Dashboard, which is right either way.
     *
     * @param Order $order
     * @param string $intentId
     * @return string One of the TAKEN_* constants
     */
    private function paymentTaken(Order $order, string $intentId): string
    {
        if ($intentId === '') {
            return self::TAKEN_CHARGED;
        }

        try {
            $check = $this->paymentConfirmation->checkPaymentIntent($intentId, (int) $order->getStoreId());
        } catch (\Exception $e) {
            $this->logger->warning('PaidCancelledOrder: could not ask PayMongo what was taken', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
            return self::TAKEN_CHARGED;
        }

        if ($check['authorized']) {
            return self::TAKEN_HELD;
        }

        return ($check['pi_status'] ?? null) === PaymentCapture::CAPTURED_STATUS
            ? self::TAKEN_CHARGED
            : self::TAKEN_NONE;
    }
}
