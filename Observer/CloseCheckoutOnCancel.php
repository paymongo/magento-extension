<?php

declare(strict_types=1);

namespace PayMongo\Payment\Observer;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * Closes PayMongo's payment page when a PayMongo order is cancelled, so no one can pay for it.
 *
 * A cancelled order's checkout session stays open on PayMongo until it expires, and the
 * customer could still pay on it: PayMongo would take the money for an order the store will
 * not fulfil (PYM-32). Triggered by order_cancel_after, so it covers every way an order is
 * cancelled: the customer leaving PayMongo's page (Failed controller), the admin, and
 * Magento's cron for stale Pending Payment orders.
 *
 * PayMongo is asked for the session and its payment intent first:
 * - nothing paid yet (awaiting a payment method, or the customer mid-way through a 3DS or
 *   e-wallet step): the session is expired, and the order says so;
 * - already paid: the customer was charged for the cancelled order, so the admin is alerted
 *   (PaidCancelledOrder). For an Authorize Only card order ReleaseHoldOnCancel reports it;
 * - a card on hold: ReleaseHoldOnCancel releases it;
 * - already expired, or a payment still processing: nothing to do. A payment that then
 *   completes raises the alert when PayMongo reports it.
 *
 * Cancelling the order never depends on PayMongo: a failure is logged and noted on the order.
 * The caller saves the order.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CloseCheckoutOnCancel implements ObserverInterface
{
    /** @var CheckoutStart */
    private CheckoutStart $checkoutStart;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var PaidCancelledOrder */
    private PaidCancelledOrder $paidCancelledOrder;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param CheckoutStart $checkoutStart
     * @param CaptureMode $captureMode
     * @param PaidCancelledOrder $paidCancelledOrder
     * @param Logger $logger
     */
    public function __construct(
        CheckoutStart $checkoutStart,
        CaptureMode $captureMode,
        PaidCancelledOrder $paidCancelledOrder,
        Logger $logger
    ) {
        $this->checkoutStart = $checkoutStart;
        $this->captureMode = $captureMode;
        $this->paidCancelledOrder = $paidCancelledOrder;
        $this->logger = $logger;
    }

    /**
     * Close the cancelled order's PayMongo payment page, or alert if its customer was already charged.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if ($order->getPayment() === null || !$this->checkoutStart->isPaymongoOrder($order)) {
            return;
        }
        // The last step of a cancellation: PayMongo's answer, shared with the steps before, is
        // forgotten when it is done, so a long-running cron process never reuses it.
        try {
            $this->closeOrAlert($order);
        } finally {
            $this->checkoutStart->forget($order);
        }
    }

    /**
     * Close the order's PayMongo page if it can still take a payment, or alert if it was paid.
     *
     * @param Order $order
     * @return void
     */
    private function closeOrAlert(Order $order): void
    {
        $sessionId = (string) $order->getPayment()->getAdditionalInformation('paymongo_checkout_session_id');

        try {
            $check = $this->checkoutStart->sessionState($order);
        } catch (\Exception $e) {
            $this->logger->error('CloseCheckoutOnCancel: could not check the checkout session', [
                'order_id'            => $order->getIncrementId(),
                'checkout_session_id' => $sessionId,
                'error'               => $e->getMessage(),
            ]);
            $this->recordOpenPage($order, $sessionId);
            return;
        }

        if ($check['state'] === CheckoutStart::STATE_PAID) {
            // A card on hold, or any charge on an Authorize Only order, is ReleaseHoldOnCancel's.
            $result = $check['result'];
            if (!$result['authorized'] && !$this->captureMode->isAuthorizeOnly($order)) {
                $this->paidCancelledOrder->alert($order, $result['payment_id'] ?? null, false);
            }
            return;
        }

        // A page waiting on 3DS or an e-wallet app is closed too: the order is cancelled, and a
        // payment that completes anyway raises the alert when PayMongo reports it.
        if ($check['state'] !== CheckoutStart::STATE_OPEN && $check['state'] !== CheckoutStart::STATE_ACTION) {
            return;
        }

        try {
            $this->checkoutStart->closeSession($order);
        } catch (\Exception $e) {
            $this->logger->error('CloseCheckoutOnCancel: could not expire the checkout session', [
                'order_id'            => $order->getIncrementId(),
                'checkout_session_id' => $sessionId,
                'error'               => $e->getMessage(),
            ]);
            $this->recordOpenPage($order, $sessionId);
            return;
        }

        $order->addCommentToStatusHistory(__(
            'PayMongo\'s payment page for this order was closed (checkout session %1), so the customer can no '
            . 'longer pay for this cancelled order.',
            $sessionId
        ));
    }

    /**
     * Note on the order that PayMongo's payment page may still be open.
     *
     * @param Order $order
     * @param string $sessionId
     * @return void
     */
    private function recordOpenPage(Order $order, string $sessionId): void
    {
        $order->addCommentToStatusHistory(__(
            'PayMongo\'s payment page for this order (checkout session %1) could not be closed, so the customer '
            . 'may still be able to pay on it until it expires. If they do, you will be alerted to refund them.',
            $sessionId
        ));
    }
}
