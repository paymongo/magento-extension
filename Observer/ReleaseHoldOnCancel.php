<?php

declare(strict_types=1);

namespace PayMongo\Payment\Observer;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\AdminNotice;
use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * Releases the PayMongo card hold when an Authorize Only order is cancelled.
 *
 * Triggered by the order_cancel_after event, so it covers every way an order is
 * cancelled: the admin Cancel button and mass action, the customer leaving PayMongo's page
 * and Magento's cron for stale Pending Payment orders. (The payment.failed webhook no longer
 * cancels orders since PYM-32.) Before 2.0.1 cancelling only changed
 * the order in Magento, and PayMongo kept the customer's funds on hold until the
 * authorisation expired (PYM-28).
 *
 * PayMongo is asked for the payment intent's status first, and only an intent awaiting
 * capture is cancelled: any other order has no hold to release. A Pending Payment order
 * is checked too, since its customer may have authorised the card even though the
 * success return and the webhook never reached Magento. An intent that has already
 * succeeded means PayMongo took the money although Magento has no invoice (a capture
 * that could not be confirmed, or invoiced, at the time): the order says the customer
 * has to be refunded through PayMongo, since Magento has no invoice to refund.
 *
 * Cancelling the order never depends on PayMongo. If the hold cannot be released, or
 * PayMongo cannot be asked whether there is one, the order is still cancelled, with a
 * comment saying so, and the admin sees an error. The caller saves the order.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class ReleaseHoldOnCancel implements ObserverInterface
{
    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var AdminNotice */
    private AdminNotice $adminNotice;

    /** @var PaidCancelledOrder */
    private PaidCancelledOrder $paidCancelledOrder;

    /** @var Logger */
    private Logger $logger;

    /** @var CheckoutStart */
    private CheckoutStart $checkoutStart;

    /**
     * @param PaymongoClient $paymongoClient
     * @param PaymentCapture $paymentCapture
     * @param PaymentConfirmation $paymentConfirmation
     * @param CaptureMode $captureMode
     * @param AdminNotice $adminNotice
     * @param Logger $logger
     * @param PaidCancelledOrder $paidCancelledOrder
     * @param CheckoutStart $checkoutStart
     */
    public function __construct(
        PaymongoClient $paymongoClient,
        PaymentCapture $paymentCapture,
        PaymentConfirmation $paymentConfirmation,
        CaptureMode $captureMode,
        AdminNotice $adminNotice,
        Logger $logger,
        PaidCancelledOrder $paidCancelledOrder,
        CheckoutStart $checkoutStart
    ) {
        $this->paymongoClient = $paymongoClient;
        $this->paymentCapture = $paymentCapture;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->captureMode = $captureMode;
        $this->adminNotice = $adminNotice;
        $this->logger = $logger;
        $this->paidCancelledOrder = $paidCancelledOrder;
        $this->checkoutStart = $checkoutStart;
    }

    /**
     * Release the card hold of a cancelled Authorize Only order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();

        if ($payment === null || !$this->captureMode->isAuthorizeOnly($order)) {
            return;
        }

        $storeId = (int) $order->getStoreId();
        $paymentIntentId = (string) $this->paymentCapture->getPaymentIntentId($order);
        $sessionId = (string) $payment->getAdditionalInformation('paymongo_checkout_session_id');

        if ($paymentIntentId === '' && $sessionId === '') {
            return;
        }

        // Through the checkout session when there is one: CheckoutStart asks PayMongo once for the
        // whole cancellation, shared with the Failed controller and CloseCheckoutOnCancel (PYM-33).
        // But the hold is on the order's stored intent: a payment completed on an earlier PayMongo
        // page made that page's intent the order's while the session stayed the newest one
        // (PYM-32), and that intent is then asked about directly, so its hold is still released.
        try {
            $result = $sessionId !== ''
                ? $this->checkoutStart->sessionState($order)['result']
                : null;
            if ($result === null
                || ($paymentIntentId !== '' && ($result['payment_intent_id'] ?? null) !== $paymentIntentId)
            ) {
                $result = $this->paymentConfirmation->checkPaymentIntent($paymentIntentId, $storeId);
            }
        } catch (\Exception $e) {
            $this->logger->error('ReleaseHoldOnCancel: could not check the payment intent', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
            ]);
            $this->recordUncheckedHold($order, $paymentIntentId !== '' ? $paymentIntentId : $sessionId);
            return;
        }

        $paymentIntentId = (string) ($result['payment_intent_id'] ?? $paymentIntentId);
        if ($paymentIntentId === '') {
            return;
        }

        if (($result['pi_status'] ?? null) === PaymentCapture::CAPTURED_STATUS) {
            $this->recordChargedOrder($order, $paymentIntentId, $result['payment_id'] ?? null);
            return;
        }

        if (!$result['authorized']) {
            return;
        }

        try {
            $this->paymongoClient->cancelPaymentIntent($paymentIntentId, $storeId);
            // The hold is gone: the next step must not act on the answer read before.
            $this->checkoutStart->forget($order);
        } catch (\Exception $e) {
            $this->logger->error('ReleaseHoldOnCancel: could not cancel the payment intent', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
            ]);
            $this->recordUnreleasedHold($order, $paymentIntentId);
            return;
        }

        $this->logger->info('PayMongo card hold released on order cancellation', [
            'order_id'          => $order->getIncrementId(),
            'payment_intent_id' => $paymentIntentId,
        ]);
        $order->addCommentToStatusHistory(__(
            'Card authorisation released on PayMongo (payment intent %1). The customer\'s funds are no longer held.',
            $paymentIntentId
        ));
    }

    /**
     * Record that PayMongo already took the money for the cancelled order, and alert the admin.
     *
     * Through PaidCancelledOrder, so the alert is a persistent System Message, not only an
     * on-screen error: the cron and the customer leaving PayMongo's page cancel orders with no
     * admin watching. It also stops a later payment webhook from alerting a second time (PYM-32).
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @param string|null $paymentId The PayMongo payment (pay_…), if known
     * @return void
     */
    private function recordChargedOrder(Order $order, string $paymentIntentId, ?string $paymentId): void
    {
        $this->logger->warning('ReleaseHoldOnCancel: cancelled order was already charged on PayMongo', [
            'order_id'          => $order->getIncrementId(),
            'payment_intent_id' => $paymentIntentId,
        ]);
        if (!$this->paidCancelledOrder->alert($order, $paymentId ?? $paymentIntentId, false)) {
            return;
        }

        $this->adminNotice->error(__(
            'Order #%1 was cancelled, but PayMongo has already charged the customer for it. Refund the customer '
            . 'through PayMongo; see the order comments.',
            $order->getIncrementId()
        ));
    }

    /**
     * Record on the order, and tell the admin, that PayMongo could not be asked whether the card is held.
     *
     * Most orders cancelled this way never had a hold (an abandoned Pending Payment order,
     * say), so this says only that it could not be checked, not that funds are held.
     *
     * @param Order $order
     * @param string $reference Payment intent or checkout session ID
     * @return void
     */
    private function recordUncheckedHold(Order $order, string $reference): void
    {
        $order->addCommentToStatusHistory(__(
            'The order was cancelled, but PayMongo could not be asked whether the customer\'s card is still '
            . 'held (%1). If it is, PayMongo releases it %2 days after the card was authorised. Check the '
            . 'payment in the PayMongo Dashboard.',
            $reference,
            AuthorizationExpiry::HOLD_DAYS
        ));

        $this->adminNotice->error(__(
            'Order #%1 was cancelled, but PayMongo could not be checked for a card hold on it. '
            . 'See the order comments.',
            $order->getIncrementId()
        ));
    }

    /**
     * Record on the order, and tell the admin, that the customer's funds are still held.
     *
     * @param Order $order
     * @param string $reference Payment intent ID
     * @return void
     */
    private function recordUnreleasedHold(Order $order, string $reference): void
    {
        $order->addCommentToStatusHistory(__(
            'The order was cancelled, but the card authorisation could not be released on PayMongo (%1). '
            . 'The customer\'s funds stay held until PayMongo releases them, %2 days after the card was '
            . 'authorised. Check the payment in the PayMongo Dashboard.',
            $reference,
            AuthorizationExpiry::HOLD_DAYS
        ));

        $this->adminNotice->error(__(
            'Order #%1 was cancelled, but its card authorisation could not be released on PayMongo. '
            . 'See the order comments.',
            $order->getIncrementId()
        ));
    }
}
