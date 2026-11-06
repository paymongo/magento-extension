<?php

declare(strict_types=1);

namespace PayMongo\Payment\Observer;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\AdminNotice;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;

/**
 * Captures a previously authorised PayMongo card payment when a shipment is created.
 *
 * Triggered by the sales_order_shipment_save_commit_after event. Only acts when:
 * - The order's checkout session was created as Authorize Only — only the standalone
 *   paymongo_card method with Payment Action set to 'authorize' is (see
 *   Model\Order\CaptureMode, which also covers orders created before that was recorded)
 * - A PayMongo payment intent ID is stored on the order payment
 * - No earlier shipment of the order has tried to capture it (PaymentCapture::RESULT_KEY),
 *   so partial shipments, or a shipment re-saved to add tracking, do not capture again
 * - The order has not been invoiced. An invoice created with Capture Online captures the
 *   hold itself (PaymentCapture::captureOnInvoice()); one created with Capture Offline
 *   means the merchant collected the payment another way, so capturing the hold as well
 *   would charge the customer twice. Either is for the whole order: an invoice for part
 *   of it is refused (PaymentCapture::assertFullInvoice())
 *
 * This implements the authorize-now-capture-later flow described in v1.1.0.0:
 * merchants who prefer not to charge the customer until they have verified and
 * shipped the order can enable this mode in Stores → Configuration → PayMongo Card.
 *
 * It runs once the shipment is committed, not inside the shipment's save transaction.
 * Inside it, a failure while invoicing rolled the whole save back — shipment included —
 * after PayMongo had already charged the customer, and the PayMongo calls held the
 * order's row locks while they waited. It runs under the order lock the webhook,
 * Success and Refresh from PayMongo use: capturing makes PayMongo send payment.paid at
 * once, and a webhook that read the order mid-capture would save over the outcome.
 *
 * When PayMongo does not capture the payment, the order is not invoiced; it is put on
 * hold with a comment saying what happened, and the admin sees an error. The admin can
 * then retry with the "Capture from PayMongo" button. Before 2.0.1 a failed capture was
 * only logged, so the order looked shipped and normal (PYM-28).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CaptureOnShipment implements ObserverInterface
{
    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var AdminNotice */
    private AdminNotice $adminNotice;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param PaymentCapture $paymentCapture
     * @param CaptureMode $captureMode
     * @param AdminNotice $adminNotice
     * @param Logger $logger
     */
    public function __construct(
        PaymentCapture $paymentCapture,
        CaptureMode $captureMode,
        AdminNotice $adminNotice,
        Logger $logger
    ) {
        $this->paymentCapture = $paymentCapture;
        $this->captureMode = $captureMode;
        $this->adminNotice = $adminNotice;
        $this->logger = $logger;
    }

    /**
     * Capture the PayMongo payment intent once a shipment is saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();

        if (!$this->captureMode->isAuthorizeOnly($order)) {
            return;
        }

        if ($this->paymentCapture->getResult($order) !== null || (float) $order->getBaseTotalInvoiced() > 0.0) {
            return;
        }

        $paymentIntentId = $this->paymentCapture->getPaymentIntentId($order);
        if ($paymentIntentId === null) {
            $this->logger->warning('CaptureOnShipment: no payment intent ID found', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        if (!$this->paymentCapture->lock($order)) {
            $this->recordSkippedCapture($order);
            return;
        }

        try {
            // Checked again inside the lock, from the database: another shipment of this order
            // saved at the same moment may have captured and invoiced it while this one waited.
            if ($this->paymentCapture->isCaptureRecorded($order)) {
                return;
            }

            $capture = $this->paymentCapture->capture($order, $paymentIntentId);

            switch ($capture['outcome']) {
                case PaymentCapture::OUTCOME_CAPTURED:
                case PaymentCapture::OUTCOME_ALREADY_CAPTURED:
                    $this->invoice($order, $paymentIntentId, $capture['outcome']);
                    break;
                default:
                    $this->recordCaptureFailure(
                        $order,
                        $paymentIntentId,
                        $capture['status'],
                        $capture['outcome'] === PaymentCapture::OUTCOME_NOT_CAPTURED
                    );
            }
        } finally {
            $this->paymentCapture->unlock($order);
        }
    }

    /**
     * Record on the order, and tell the admin, that the capture was skipped because the order was busy.
     *
     * The webhook, Success or Refresh from PayMongo held the order's lock for the whole
     * wait. Nothing was sent to PayMongo, so the customer has not been charged. The order
     * shipped with no capture outcome recorded, which shows the Capture from PayMongo
     * button; the comment and the hold make sure someone notices, even when the shipment
     * came through the API and no admin sees the on-screen error.
     *
     * @param Order $order
     * @return void
     */
    private function recordSkippedCapture(Order $order): void
    {
        $this->logger->warning('CaptureOnShipment: order locked, capture skipped', [
            'order_id' => $order->getIncrementId(),
        ]);

        $order->addCommentToStatusHistory(__(
            'The payment was not captured for this shipment because the order was being updated by PayMongo at the '
            . 'same time. The customer has NOT been charged and no invoice was created. Use Capture from PayMongo '
            . 'to capture it.'
        ));
        if ($order->canHold()) {
            $order->hold();
        }

        try {
            $order->save();
        } catch (\Exception $e) {
            $this->logger->error('CaptureOnShipment: could not record the skipped capture on the order', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
        }

        $this->adminNotice->error(__(
            'The shipment was saved, but order #%1 was being updated by PayMongo at the same time, so its payment '
            . 'was not captured. The order has been put on hold; use Capture from PayMongo to capture it.',
            $order->getIncrementId()
        ));
    }

    /**
     * Invoice an order whose payment PayMongo has captured, warning the admin if that fails.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @param string $outcome PaymentCapture::OUTCOME_CAPTURED or OUTCOME_ALREADY_CAPTURED
     * @return void
     */
    private function invoice(Order $order, string $paymentIntentId, string $outcome): void
    {
        $alreadyCaptured = $outcome === PaymentCapture::OUTCOME_ALREADY_CAPTURED;
        $this->logger->info(
            $alreadyCaptured
                ? 'CaptureOnShipment: payment intent was already captured, proceeding to invoice'
                : 'PayMongo payment intent captured on shipment',
            [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'amount'            => $order->getGrandTotal(),
            ]
        );

        try {
            $this->paymentCapture->invoice(
                $order,
                $paymentIntentId,
                $alreadyCaptured
                    ? __(
                        'PayMongo had already captured this payment before the shipment, so it was not captured '
                        . 'again. The invoice records that one capture: the customer was charged once.'
                    )
                    : __('Payment captured via PayMongo on shipment creation (authorize-now-capture-later).')
            );
        } catch (\Exception $e) {
            $this->adminNotice->error(__(
                'PayMongo captured the payment for order #%1, but the invoice could not be created (%2). '
                . 'The order has been put on hold; use Capture from PayMongo to create the invoice.',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    /**
     * Record on the order, and tell the admin, that PayMongo did not capture the payment.
     *
     * The shipment stays saved and the order is not invoiced. The order is put on hold
     * so it stands out in the order grid instead of looking shipped and normal; the
     * merchant retries with "Capture from PayMongo", or releases it once the payment is
     * sorted out another way.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @param string|null $status Payment intent status, or null if PayMongo could not be asked
     * @param bool $certain True when the status shows the customer was not charged; false when
     *                      PayMongo may have charged them (no answer, or a status such as processing)
     * @return void
     */
    private function recordCaptureFailure(Order $order, string $paymentIntentId, ?string $status, bool $certain): void
    {
        $this->logger->error(
            $certain
                ? 'CaptureOnShipment: capture failed, customer not charged'
                : 'CaptureOnShipment: capture failed, could not confirm whether the customer was charged',
            [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'status'            => $status,
            ]
        );

        $statusLabel = $this->paymentCapture->statusLabel($status);

        $this->paymentCapture->recordResult(
            $order,
            $certain ? PaymentCapture::RESULT_NOT_CAPTURED : PaymentCapture::RESULT_UNCONFIRMED,
            $status
        );
        $order->addCommentToStatusHistory(
            $certain
                ? __(
                    'PayMongo did not capture the payment for this shipment, so the customer has NOT been charged '
                    . 'and no invoice was created. Payment intent %1 is %2; the card authorisation may have expired '
                    . 'or been cancelled. Use Capture from PayMongo to try again, or check the payment in the '
                    . 'PayMongo Dashboard before fulfilling this order.',
                    $paymentIntentId,
                    $statusLabel
                )
                : __(
                    'The PayMongo capture for this shipment failed, and it could not be confirmed whether the '
                    . 'customer was charged. No invoice was created. Payment intent %1 is %2. Use Capture from '
                    . 'PayMongo to check again, and do not collect payment another way until PayMongo confirms '
                    . 'the customer was not charged.',
                    $paymentIntentId,
                    $statusLabel
                )
        );

        $held = false;
        if ($order->canHold()) {
            try {
                $order->hold();
                $held = true;
            } catch (\Exception $e) {
                $this->logger->error('CaptureOnShipment: could not put the order on hold', [
                    'order_id' => $order->getIncrementId(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        try {
            $order->save();
        } catch (\Exception $e) {
            $this->logger->error('CaptureOnShipment: could not save the capture failure on the order', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
        }

        $this->adminNotice->error($this->failureMessage($order, $certain, $held));
    }

    /**
     * The admin's on-screen error for a capture that failed.
     *
     * @param Order $order
     * @param bool $certain
     * @param bool $held
     * @return Phrase
     */
    private function failureMessage(Order $order, bool $certain, bool $held): Phrase
    {
        $outcome = $certain
            ? __(
                'The shipment was saved, but PayMongo did not capture the payment for order #%1, so the customer '
                . 'has not been charged.',
                $order->getIncrementId()
            )
            : __(
                'The shipment was saved, but the PayMongo capture for order #%1 failed and it could not be '
                . 'confirmed whether the customer was charged.',
                $order->getIncrementId()
            );

        return $held
            ? __('%1 The order has been put on hold; see its comments.', $outcome)
            : __('%1 See the order comments.', $outcome);
    }
}
