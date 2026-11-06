<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Order;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;

/**
 * Admin action: retry capturing an Authorize Only order's card hold on PayMongo.
 *
 * Behind the "Capture from PayMongo" button, shown on orders whose capture failed, could
 * not be confirmed, took the money without an invoice, or never ran although the order
 * shipped (PaymentCapture::canRetry()). Once every item has
 * shipped, no new shipment can trigger the capture again, so before PYM-28's button the
 * merchant had to leave Magento to collect the payment within the 7 days the hold lasts.
 *
 * Model\Order\PaymentCapture asks PayMongo to capture and checks what happened:
 * - Captured now, or already captured on PayMongo's side: the order is released from hold
 *   and invoiced (Capture Offline). An already captured payment is never charged again.
 * - Not captured: nothing changes but a comment; if the card is still held the admin can
 *   try again, otherwise the hold has expired or been cancelled.
 * - Unconfirmed (PayMongo unreachable, or still processing): nothing changes but a comment.
 *
 * Runs under the same per-order lock as the webhook and Refresh from PayMongo, and re-reads
 * the order inside it, so a double click cannot capture or invoice twice.
 *
 * Route: POST paymongo/order/capturePayment?order_id={id}
 * ACL:   Magento_Sales::capture
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CapturePayment extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::capture';

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /** @var AuthorizationExpiry */
    private AuthorizationExpiry $authorizationExpiry;

    /** @var TimezoneInterface */
    private TimezoneInterface $timezone;

    /** @var OrderFactory */
    private OrderFactory $orderFactory;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param Context $context
     * @param PaymentCapture $paymentCapture
     * @param AuthorizationExpiry $authorizationExpiry
     * @param TimezoneInterface $timezone
     * @param OrderFactory $orderFactory
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        PaymentCapture $paymentCapture,
        AuthorizationExpiry $authorizationExpiry,
        TimezoneInterface $timezone,
        OrderFactory $orderFactory,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->paymentCapture = $paymentCapture;
        $this->authorizationExpiry = $authorizationExpiry;
        $this->timezone = $timezone;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
    }

    /**
     * Retry the capture and return to the order.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if ($orderId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid order.'));
            return $resultRedirect->setPath('sales/order/index');
        }
        $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);

        $order = $this->orderFactory->create()->load($orderId);
        if (!$order->getId()) {
            $this->messageManager->addErrorMessage(__('This order no longer exists.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        if (!$this->paymentCapture->lock($order)) {
            $this->messageManager->addWarningMessage(
                __('Order #%1 is already being updated. Please try again in a moment.', $order->getIncrementId())
            );
            return $resultRedirect;
        }

        try {
            // Re-read inside the lock: another click, or the shipment, may have captured it meanwhile.
            $order = $this->orderFactory->create()->load($orderId);
            if (!$this->paymentCapture->canRetry($order)) {
                $this->messageManager->addNoticeMessage(
                    __('Order #%1 has no PayMongo payment waiting to be captured.', $order->getIncrementId())
                );
                return $resultRedirect;
            }

            $this->capture($order, (string) $this->paymentCapture->getPaymentIntentId($order));
        } catch (\Exception $e) {
            $this->logger->error('CapturePayment: capture retry failed', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                __(
                    'Could not capture the payment for order #%1. See var/log/payment.paymongo.log.',
                    $order->getIncrementId()
                )
            );
        } finally {
            $this->paymentCapture->unlock($order);
        }

        return $resultRedirect;
    }

    /**
     * Capture the order's payment, then invoice it or record why not.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @return void
     */
    private function capture(Order $order, string $paymentIntentId): void
    {
        $this->logger->info('Admin retrying PayMongo capture', [
            'order_id'          => $order->getIncrementId(),
            'payment_intent_id' => $paymentIntentId,
        ]);

        // PayMongo already took this money; only the invoice is missing. Capturing again would
        // be refused, and if PayMongo were unreachable it would downgrade a known capture to
        // "unconfirmed", so go straight to the invoice.
        if ($this->paymentCapture->getResult($order) === PaymentCapture::RESULT_CAPTURED_NOT_INVOICED) {
            // An order captured before the payment ID was stored gets it here, so its invoice
            // can be refunded online against the payment (PYM-31).
            $this->paymentCapture->ensurePaymentId($order);
            $this->invoice($order, $paymentIntentId, PaymentCapture::OUTCOME_ALREADY_CAPTURED);
            return;
        }

        $capture = $this->paymentCapture->capture($order, $paymentIntentId);
        $statusLabel = $this->paymentCapture->statusLabel($capture['status']);

        switch ($capture['outcome']) {
            case PaymentCapture::OUTCOME_CAPTURED:
            case PaymentCapture::OUTCOME_ALREADY_CAPTURED:
                $this->invoice($order, $paymentIntentId, $capture['outcome']);
                return;

            case PaymentCapture::OUTCOME_NOT_CAPTURED:
                $stillHeld = $capture['status'] === PaymentCapture::HELD_STATUS;
                $this->recordFailure($order, PaymentCapture::RESULT_NOT_CAPTURED, $capture['status'], $stillHeld
                    ? __(
                        'Capture from PayMongo failed: PayMongo did not capture the payment, so the customer has '
                        . 'NOT been charged. Payment intent %1 is %2: the card is still held, so the capture can be '
                        . 'tried again.',
                        $paymentIntentId,
                        $statusLabel
                    )
                    : __(
                        'Capture from PayMongo failed: PayMongo did not capture the payment, so the customer has '
                        . 'NOT been charged. Payment intent %1 is %2: the card is no longer held, so collect '
                        . 'payment from the customer another way.',
                        $paymentIntentId,
                        $statusLabel
                    ));
                $this->messageManager->addErrorMessage($stillHeld
                    ? __(
                        'PayMongo did not capture the payment for order #%1, so the customer has not been charged. '
                        . 'The card is still held%2; try again shortly.',
                        $order->getIncrementId(),
                        $this->expiryNote($order)
                    )
                    : __(
                        'PayMongo did not capture the payment for order #%1, and the card is no longer held: the '
                        . 'authorisation has expired or been cancelled. The customer has not been charged; collect '
                        . 'payment another way.',
                        $order->getIncrementId()
                    ));
                return;

            default:
                $this->recordFailure($order, PaymentCapture::RESULT_UNCONFIRMED, $capture['status'], __(
                    'Capture from PayMongo failed, and it could not be confirmed whether the customer was charged. '
                    . 'Payment intent %1 is %2.',
                    $paymentIntentId,
                    $statusLabel
                ));
                $this->messageManager->addErrorMessage(__(
                    'The PayMongo capture for order #%1 failed and it could not be confirmed whether the customer '
                    . 'was charged. Try again in a few minutes, and do not collect payment another way until '
                    . 'PayMongo confirms the customer was not charged.',
                    $order->getIncrementId()
                ));
        }
    }

    /**
     * Release the order from hold and invoice the captured payment.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @param string $outcome PaymentCapture::OUTCOME_CAPTURED or OUTCOME_ALREADY_CAPTURED
     * @return void
     */
    private function invoice(Order $order, string $paymentIntentId, string $outcome): void
    {
        if ($order->canUnhold()) {
            $order->unhold();
        }

        $alreadyCaptured = $outcome === PaymentCapture::OUTCOME_ALREADY_CAPTURED;
        try {
            $this->paymentCapture->invoice(
                $order,
                $paymentIntentId,
                $alreadyCaptured
                    ? __(
                        'PayMongo had already captured this payment, so Capture from PayMongo did not capture it '
                        . 'again. The invoice records that one capture: the customer was charged once.'
                    )
                    : __('Payment captured via PayMongo from the admin (Capture from PayMongo).')
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__(
                'PayMongo captured the payment for order #%1, but the invoice could not be created (%2). '
                . 'The order is on hold; click Capture from PayMongo again to retry the invoice. The customer '
                . 'will not be charged twice.',
                $order->getIncrementId(),
                $e->getMessage()
            ));
            return;
        }

        $this->messageManager->addSuccessMessage($alreadyCaptured
            ? __(
                'PayMongo had already captured the payment for order #%1, so the customer was not charged again. '
                . 'The order has been invoiced.',
                $order->getIncrementId()
            )
            : __('The payment for order #%1 was captured and the order invoiced.', $order->getIncrementId()));
    }

    /**
     * Record a failed retry on the order and save it. The order stays as it is otherwise.
     *
     * @param Order $order
     * @param string $result PaymentCapture::RESULT_NOT_CAPTURED or RESULT_UNCONFIRMED
     * @param string|null $status The payment intent's status, if known
     * @param Phrase $comment
     * @return void
     */
    private function recordFailure(Order $order, string $result, ?string $status, Phrase $comment): void
    {
        $this->paymentCapture->recordResult($order, $result, $status);
        $order->addCommentToStatusHistory($comment);
        $order->save();
    }

    /**
     * " until <date>" for a hold with a known expiry, or an empty string.
     *
     * @param Order $order
     * @return string
     */
    private function expiryNote(Order $order): string
    {
        $expiresAt = $this->authorizationExpiry->getExpiresAt($order);
        if ($expiresAt === null) {
            return '';
        }

        return (string) __(
            ' until %1',
            $this->timezone->formatDateTime($expiresAt, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
        );
    }
}
