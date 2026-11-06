<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Logger\Logger;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Captures an Authorize Only order's card hold on PayMongo, and records what happened.
 *
 * Shared by everything that takes the held money, so they all decide the same way
 * whether the customer was charged:
 * - Observer\CaptureOnShipment, when the order ships
 * - the admin "Capture from PayMongo" button (Controller\Adminhtml\Order\CapturePayment),
 *   which retries a capture that failed, could not be confirmed, or was never made
 * - an invoice created with Capture Online (Model\Method\AbstractPaymongoMethod::capture())
 *
 * A failed capture call does not always mean no money was taken: the intent may already
 * have been captured — test mode has charged at checkout despite capture_type=manual, the
 * merchant may have captured it on PayMongo's side, or a call that timed out may still
 * have gone through. So PayMongo is asked for the intent's status, and only "succeeded"
 * counts as captured. Before 2.0.1 every resource_invalid_state error was taken to mean
 * "already captured", so an authorisation that had expired or been cancelled was invoiced
 * as paid with no money taken (PYM-28).
 *
 * The outcome, and the intent's status at the time, are stored on the payment
 * (RESULT_KEY, STATUS_KEY): a later shipment does not capture again, the admin button
 * knows which orders still need it, and the expiry banner knows whether PayMongo still
 * holds the card.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentCapture
{
    /**
     * Payment additional_information key holding the outcome of the last capture attempt.
     */
    public const RESULT_KEY = 'paymongo_capture_result';

    /**
     * Payment additional_information key holding the payment intent's status at that attempt.
     */
    public const STATUS_KEY = 'paymongo_capture_status';

    public const RESULT_CAPTURED = 'captured';
    public const RESULT_NOT_CAPTURED = 'not_captured';
    public const RESULT_UNCONFIRMED = 'unconfirmed';

    /**
     * PayMongo took the money, but the invoice could not be created.
     */
    public const RESULT_CAPTURED_NOT_INVOICED = 'captured_not_invoiced';

    /**
     * capture() outcomes. A captured payment is RESULT_CAPTURED, whether this call took the
     * money or PayMongo already had.
     */
    public const OUTCOME_CAPTURED = 'captured';
    public const OUTCOME_ALREADY_CAPTURED = 'already_captured';
    public const OUTCOME_NOT_CAPTURED = 'not_captured';
    public const OUTCOME_UNCONFIRMED = 'unconfirmed';

    /**
     * The payment intent status of a card hold PayMongo still has, which can be captured.
     */
    public const HELD_STATUS = 'awaiting_capture';

    /**
     * The only payment intent status in which PayMongo has taken the customer's money.
     */
    public const CAPTURED_STATUS = 'succeeded';

    /**
     * The order lock's name prefix and wait, shared by everything that changes a PayMongo
     * order's payment: the Webhook, Success and RefreshStatus controllers use these too, so
     * a capture cannot interleave with them, or with another capture, on the same order.
     */
    public const ORDER_LOCK_PREFIX = 'paymongo_order_';
    public const ORDER_LOCK_TIMEOUT = 30;

    /**
     * Payment intent statuses in which PayMongo has certainly not taken the customer's
     * money. Any other status, or none, leaves it unconfirmed.
     */
    private const NOT_CAPTURED_STATUSES = [
        self::HELD_STATUS,
        'awaiting_payment_method',
        'awaiting_next_action',
        'cancelled',
    ];

    /**
     * Outcomes the admin can follow up with the "Capture from PayMongo" button.
     */
    private const RETRYABLE_RESULTS = [
        self::RESULT_NOT_CAPTURED,
        self::RESULT_UNCONFIRMED,
        self::RESULT_CAPTURED_NOT_INVOICED,
    ];

    /**
     * Order states in which an order can no longer be captured or invoiced.
     */
    private const FINISHED_STATES = [Order::STATE_CANCELED, Order::STATE_CLOSED, Order::STATE_COMPLETE];

    /**
     * Order states in which a confirmed card hold can be waiting for capture.
     */
    private const AUTHORISED_STATES = [Order::STATE_PROCESSING, Order::STATE_HOLDED];

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var InvoiceService */
    private InvoiceService $invoiceService;

    /** @var TransactionFactory */
    private TransactionFactory $transactionFactory;

    /** @var OrderFactory */
    private OrderFactory $orderFactory;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var AuditLogger */
    private AuditLogger $auditLogger;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param PaymongoClient $paymongoClient
     * @param PaymentConfirmation $paymentConfirmation
     * @param CaptureMode $captureMode
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderFactory $orderFactory
     * @param LockManagerInterface $lockManager
     * @param AuditLogger $auditLogger
     * @param Logger $logger
     */
    public function __construct(
        PaymongoClient $paymongoClient,
        PaymentConfirmation $paymentConfirmation,
        CaptureMode $captureMode,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderFactory $orderFactory,
        LockManagerInterface $lockManager,
        AuditLogger $auditLogger,
        Logger $logger
    ) {
        $this->paymongoClient = $paymongoClient;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->captureMode = $captureMode;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderFactory = $orderFactory;
        $this->lockManager = $lockManager;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
    }

    /**
     * The order's stored PayMongo payment intent ID, or null if it has none.
     *
     * @param Order $order
     * @return string|null
     */
    public function getPaymentIntentId(Order $order): ?string
    {
        $id = (string) $order->getPayment()?->getAdditionalInformation('paymongo_payment_intent_id');

        return $id !== '' ? $id : null;
    }

    /**
     * The PayMongo payment (pay_…) behind the order's payment intent, looked up if not stored.
     *
     * The stored paymongo_payment_id if there is one. Otherwise PayMongo is asked for the
     * intent, and the first payment it lists as paid is stored on the payment (the caller
     * saves the order). A failed attempt listed before it, such as a declined card or a
     * failed 3DS check, is skipped: a refund against it would be refused.
     *
     * @param Order $order
     * @return string|null Null if PayMongo lists no paid payment, or the order has no intent
     * @throws \Exception when PayMongo cannot be asked
     */
    public function findPaymentId(Order $order): ?string
    {
        $paymentId = $this->getPaymentId($order);
        $paymentIntentId = $this->getPaymentIntentId($order);
        if ($paymentId !== null || $paymentIntentId === null) {
            return $paymentId;
        }

        $paymentId = $this->paymentConfirmation->checkPaymentIntent(
            $paymentIntentId,
            (int) $order->getStoreId()
        )['payment_id'] ?? null;
        $this->recordPaymentId($order, $paymentId);

        return $paymentId;
    }

    /**
     * The outcome of the order's last capture attempt (a RESULT_* constant), or null if none was made.
     *
     * @param Order $order
     * @return string|null
     */
    public function getResult(Order $order): ?string
    {
        $result = (string) $order->getPayment()?->getAdditionalInformation(self::RESULT_KEY);

        return $result !== '' ? $result : null;
    }

    /**
     * Store the outcome of a capture attempt, and the intent's status, on the payment.
     *
     * The caller saves the order.
     *
     * @param Order $order
     * @param string $result One of the RESULT_* constants
     * @param string|null $status The payment intent's status, if known
     * @return void
     */
    public function recordResult(Order $order, string $result, ?string $status = null): void
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation(self::RESULT_KEY, $result);
        $payment->setAdditionalInformation(self::STATUS_KEY, $status);
    }

    /**
     * A payment intent status for an admin message or order comment.
     *
     * @param string|null $status
     * @return string
     */
    public function statusLabel(?string $status): string
    {
        return $status ?? (string) __('unknown (PayMongo could not be reached)');
    }

    /**
     * Whether PayMongo should still be holding the order's card, waiting for capture.
     *
     * True for a confirmed Authorize Only order that has not been invoiced, as long as no
     * capture was attempted, or the last attempt left the card held. Used by the expiry
     * banner, which must not claim a hold PayMongo has dropped or already captured.
     *
     * @param Order $order
     * @return bool
     */
    public function isAwaitingCapture(Order $order): bool
    {
        if (!in_array($order->getState(), self::AUTHORISED_STATES, true) || !$this->isUncaptured($order)) {
            return false;
        }

        $result = $this->getResult($order);

        return $result === null
            || ($result === self::RESULT_NOT_CAPTURED
                && $order->getPayment()->getAdditionalInformation(self::STATUS_KEY) === self::HELD_STATUS);
    }

    /**
     * Whether the admin can capture with the "Capture from PayMongo" button.
     *
     * True for an Authorize Only order that has not been invoiced or finished, when the
     * last capture failed, could not be confirmed, or took the money without an invoice —
     * or when the order has shipped without a capture being recorded at all (an order
     * shipped before 2.0.1, whose failed capture was only logged, or a capture skipped
     * because the order was locked).
     *
     * @param Order $order
     * @return bool
     */
    public function canRetry(Order $order): bool
    {
        if (in_array($order->getState(), self::FINISHED_STATES, true) || !$this->isUncaptured($order)) {
            return false;
        }

        $result = $this->getResult($order);
        if ($result !== null) {
            return in_array($result, self::RETRYABLE_RESULTS, true);
        }

        foreach ($order->getAllItems() as $item) {
            if ((float) $item->getQtyShipped() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a capture is already recorded for the order in the database.
     *
     * Reads a fresh copy, so a caller holding the order's lock sees what another process
     * — a second shipment saved at the same moment, say — wrote before the lock was free.
     * The caller's own order object stays as it is: Magento may save that object after
     * the caller returns (the REST ship service does), so it must not be swapped out.
     *
     * @param Order $order
     * @return bool True if a capture was attempted, or the order has been invoiced
     */
    public function isCaptureRecorded(Order $order): bool
    {
        $fresh = $this->orderFactory->create()->load((int) $order->getId());
        if (!$fresh->getId()) {
            return false;
        }

        return $this->getResult($fresh) !== null || (float) $fresh->getBaseTotalInvoiced() > 0.0;
    }

    /**
     * Take the order's lock, shared with the webhook, Success and Refresh from PayMongo.
     *
     * @param Order $order
     * @return bool False if another process held it for the whole wait
     */
    public function lock(Order $order): bool
    {
        return $this->lockManager->lock(self::ORDER_LOCK_PREFIX . $order->getIncrementId(), self::ORDER_LOCK_TIMEOUT);
    }

    /**
     * Release the order's lock.
     *
     * @param Order $order
     * @return void
     */
    public function unlock(Order $order): void
    {
        $this->lockManager->unlock(self::ORDER_LOCK_PREFIX . $order->getIncrementId());
    }

    /**
     * Ask PayMongo to capture the order's card hold, and work out whether the customer was charged.
     *
     * When the customer was charged, the PayMongo payment (pay_…) behind the capture is stored
     * on the order's payment as paymongo_payment_id, as the webhook does for a payment made at
     * checkout. It becomes the invoice's transaction ID, which a credit memo refunds online.
     * The caller saves the order.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @return array{outcome: string, status: ?string}
     *         outcome is one of the OUTCOME_* constants; status is the intent's status after a
     *         failed capture call, or null if PayMongo could not be asked
     */
    public function capture(Order $order, string $paymentIntentId): array
    {
        $storeId = (int) $order->getStoreId();
        $amountCentavos = (int) round((float) $order->getGrandTotal() * 100);

        $response = null;
        try {
            $response = $this->paymongoClient->capturePaymentIntent($paymentIntentId, $amountCentavos, $storeId);
        } catch (\Exception $captureEx) {
            $this->logger->warning('PaymentCapture: capture call failed, checking the payment intent', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'error'             => $captureEx->getMessage(),
            ]);
        }

        // Outside the try above: PayMongo has taken the money, so nothing here may turn this
        // into a failed capture. A response naming no paid payment is followed by one lookup.
        if ($response !== null) {
            $this->keepPaymentId(
                $order,
                $this->paymentConfirmation->findPaidPaymentId($response['data']['attributes']['payments'] ?? []),
                true
            );
            return ['outcome' => self::OUTCOME_CAPTURED, 'status' => self::CAPTURED_STATUS];
        }

        try {
            $intent = $this->paymentConfirmation->checkPaymentIntent($paymentIntentId, $storeId);
            $status = $intent['pi_status'];
            $paymentId = $intent['payment_id'] ?? null;
        } catch (\Exception $lookupEx) {
            $this->logger->error('PaymentCapture: could not retrieve the payment intent', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'error'             => $lookupEx->getMessage(),
            ]);
            $status = null;
            $paymentId = null;
        }

        if ($status === self::CAPTURED_STATUS) {
            // The intent was just looked up, so a missing payment is not looked up again.
            $this->keepPaymentId($order, $paymentId, false);
            $outcome = self::OUTCOME_ALREADY_CAPTURED;
        } elseif (in_array($status, self::NOT_CAPTURED_STATUSES, true)) {
            $outcome = self::OUTCOME_NOT_CAPTURED;
        } else {
            $outcome = self::OUTCOME_UNCONFIRMED;
        }

        return ['outcome' => $outcome, 'status' => $status];
    }

    /**
     * Record a captured payment on the order and invoice it. Saves the order.
     *
     * The invoice uses Capture Online, like the one the webhook creates for a payment made
     * at checkout, so Magento records a capture transaction and gives the invoice its
     * transaction ID: the PayMongo payment, or failing that the payment intent (see the
     * method's capture()). Without one, a credit memo can only be refunded offline; before
     * 2.0.1 these invoices used Capture Offline and had none (PYM-31). PayMongo is not asked
     * to capture again: the result is recorded as captured before the invoice is registered,
     * so captureOnInvoice() leaves it alone.
     *
     * When invoicing fails, the invoice transaction rolls back, but register() has already
     * changed this order object's totals in memory, so it is not saved; the caller must not
     * save it either. Instead the failure is recorded on a freshly loaded copy of the order
     * (RESULT_CAPTURED_NOT_INVOICED, a comment, and On Hold), so the admin can create the
     * invoice with "Capture from PayMongo" without the customer being charged again.
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @param Phrase $comment Order comment describing how the payment was captured
     * @return void
     * @throws \Exception when the invoice cannot be created
     */
    public function invoice(Order $order, string $paymentIntentId, Phrase $comment): void
    {
        $this->auditLogger->logCapture(
            (string) $order->getIncrementId(),
            (float) $order->getGrandTotal(),
            $paymentIntentId,
            $this->getPaymentId($order)
        );

        try {
            $this->recordResult($order, self::RESULT_CAPTURED, self::CAPTURED_STATUS);
            $order->addCommentToStatusHistory($comment);

            // Every caller has already ruled out invoiced orders, so an order Magento will not
            // invoice now (payment review, say) is a failure like any other: the customer has
            // been charged, and recording it as plainly "captured" would hide the button.
            if (!$order->canInvoice()) {
                throw new LocalizedException(__('Magento cannot invoice this order in its current state.'));
            }

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();

            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)->addObject($order)->save();

            $this->logger->info('Invoice created for captured PayMongo payment', [
                'order_id'   => $order->getIncrementId(),
                'invoice_id' => $invoice->getIncrementId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('PaymentCapture: payment captured but invoicing failed', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
            ]);
            $this->recordResult($order, self::RESULT_CAPTURED_NOT_INVOICED, self::CAPTURED_STATUS);
            $this->recordCapturedNotInvoiced(
                (int) $order->getId(),
                $paymentIntentId,
                $this->getPaymentId($order),
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Capture the card hold for an invoice created with Capture Online.
     *
     * Called by the payment method's capture() while the invoice is registered. Only an
     * Authorize Only order whose payment has not been captured yet is sent to PayMongo;
     * anything else is left to the method as before. Before PYM-28, Capture Online only
     * recorded a transaction ID, so an Authorize Only order invoiced before shipping was
     * marked paid without the card being charged. The caller saves the order.
     *
     * An invoice for only part of the order is refused before PayMongo is asked, since the
     * capture always takes the whole hold. RejectPartialInvoicePlugin refuses most of these
     * earlier, when the invoice is registered; this also covers capturing an invoice created
     * earlier with "Not Capture", which is not registered again, and the rest of an order
     * part-invoiced before 2.0.1.
     *
     * @param Order $order
     * @param float $baseAmount The invoice's grand total in the base currency, as Magento
     *        passes it to the payment method
     * @return void
     * @throws LocalizedException when the invoice is not for the whole order, or PayMongo did
     *         not capture the payment, or could not confirm it, so Magento does not create the invoice
     */
    public function captureOnInvoice(Order $order, float $baseAmount): void
    {
        $paymentIntentId = $this->getPaymentIntentId($order);
        $result = $this->getResult($order);
        if ($paymentIntentId === null
            || $result === self::RESULT_CAPTURED
            || !$this->captureMode->isAuthorizeOnly($order)
        ) {
            return;
        }

        // PayMongo already took this money; only the invoice was missing. Asking PayMongo again
        // would be refused, and if it were unreachable would block the invoice as unconfirmed.
        if ($result === self::RESULT_CAPTURED_NOT_INVOICED) {
            $this->ensurePaymentId($order);
            $this->recordResult($order, self::RESULT_CAPTURED, self::CAPTURED_STATUS);
            $order->addCommentToStatusHistory(__(
                'Invoice created for the payment PayMongo had already captured (payment intent %1).',
                $paymentIntentId
            ));
            return;
        }

        if (!$this->isWholeOrder($order, $baseAmount)) {
            throw new LocalizedException(__(
                'This invoice is for only part of order #%1, and PayMongo can only capture its card payment '
                . 'once, for the full order amount, so the payment was not captured. Invoice the whole order '
                . 'at once. If part of it is already invoiced, collect the rest another way and invoice it '
                . 'with Capture Offline.',
                $order->getIncrementId()
            ));
        }

        $capture = $this->capture($order, $paymentIntentId);
        $status = $capture['status'];

        switch ($capture['outcome']) {
            case self::OUTCOME_CAPTURED:
            case self::OUTCOME_ALREADY_CAPTURED:
                // The money is taken now, but the invoice is only saved later, by Magento. Store
                // "captured but not invoiced" straight away; the invoice save overwrites it with
                // "captured". If that save fails, the customer is charged with nothing else on
                // record, and this lets the admin invoice it with Capture from PayMongo.
                $this->auditLogger->logCapture(
                    (string) $order->getIncrementId(),
                    (float) $order->getGrandTotal(),
                    $paymentIntentId,
                    $this->getPaymentId($order)
                );
                $this->persistResult(
                    (int) $order->getId(),
                    self::RESULT_CAPTURED_NOT_INVOICED,
                    $this->getPaymentId($order)
                );
                $this->recordResult($order, self::RESULT_CAPTURED, self::CAPTURED_STATUS);
                $order->addCommentToStatusHistory(__(
                    'Payment captured via PayMongo when the invoice was created (payment intent %1).',
                    $paymentIntentId
                ));
                return;

            case self::OUTCOME_NOT_CAPTURED:
                throw new LocalizedException(__(
                    'PayMongo did not capture the payment (payment intent %1 is %2), so the invoice was not '
                    . 'created and the customer has not been charged.',
                    $paymentIntentId,
                    $this->statusLabel($status)
                ));

            default:
                throw new LocalizedException(__(
                    'The PayMongo capture failed and it could not be confirmed whether the customer was charged '
                    . '(payment intent %1 is %2), so the invoice was not created. Check the payment in the '
                    . 'PayMongo Dashboard before trying again.',
                    $paymentIntentId,
                    $this->statusLabel($status)
                ));
        }
    }

    /**
     * Refuse an invoice for less than the whole of an Authorize Only order on a card hold.
     *
     * PayMongo captures a card hold once, and this module always captures it in full (the
     * shipment, the "Capture from PayMongo" button and Capture Online all do), so while the
     * hold can still be captured the order can only be invoiced in one go, for everything.
     * Otherwise:
     * - a Capture Online invoice for part of the order charged the customer for all of it;
     * - a Capture Offline invoice for part of it counted as "invoiced", so shipping and the
     *   button no longer captured the hold, and the rest was never collected.
     *
     * Magento's admin already only invoices these orders in full, since the methods do not
     * allow partial capture, but the REST API and custom code do not check that. This is
     * called by RejectPartialInvoicePlugin before an invoice is registered, so a refused
     * invoice changes nothing on the order.
     *
     * Not refused:
     * - orders without a card hold: paid at checkout, or not PayMongo's;
     * - once the hold cannot be captured any more: PayMongo has taken the money (captured,
     *   or captured but not invoiced), or no longer holds the card. The order then has to
     *   be invoiced however it can, and refusing would leave it impossible to invoice;
     * - the rest of an order part-invoiced before this check existed. Only Capture Offline
     *   gets that far: Capture Online would take the whole hold, so captureOnInvoice()
     *   refuses it.
     *
     * @param Order $order
     * @param float $baseAmount The invoice's grand total in the base currency
     * @return void
     * @throws LocalizedException when the invoice is not for the whole order
     */
    public function assertFullInvoice(Order $order, float $baseAmount): void
    {
        if (!$this->isHoldCapturable($order)
            || (float) $order->getBaseTotalInvoiced() > 0.0
            || $this->isWholeOrder($order, $baseAmount)
        ) {
            return;
        }

        throw new LocalizedException(__(
            'Order #%1 was paid by card with Authorize Only, and PayMongo can only capture that payment '
            . 'once, for the full order amount. Invoice the whole order at once instead of part of it.',
            $order->getIncrementId()
        ));
    }

    /**
     * Whether PayMongo may still be holding the order's card for a capture.
     *
     * True until a capture is known to have taken the money or found the hold gone; an
     * unconfirmed capture may still find it held.
     *
     * @param Order $order
     * @return bool
     */
    private function isHoldCapturable(Order $order): bool
    {
        if ($this->getPaymentIntentId($order) === null || !$this->captureMode->isAuthorizeOnly($order)) {
            return false;
        }

        $result = $this->getResult($order);

        return $result === null
            || $result === self::RESULT_UNCONFIRMED
            || ($result === self::RESULT_NOT_CAPTURED
                && $order->getPayment()->getAdditionalInformation(self::STATUS_KEY) === self::HELD_STATUS);
    }

    /**
     * Whether an amount in the base currency is the order's whole grand total.
     *
     * Compared in centavos, the unit capture() sends PayMongo.
     *
     * @param Order $order
     * @param float $baseAmount
     * @return bool
     */
    private function isWholeOrder(Order $order, float $baseAmount): bool
    {
        return (int) round($baseAmount * 100) === (int) round((float) $order->getBaseGrandTotal() * 100);
    }

    /**
     * Whether the order is Authorize Only, has a payment intent, and has not been invoiced.
     *
     * @param Order $order
     * @return bool
     */
    private function isUncaptured(Order $order): bool
    {
        return (float) $order->getBaseTotalInvoiced() <= 0.0
            && $this->getPaymentIntentId($order) !== null
            && $this->captureMode->isAuthorizeOnly($order);
    }

    /**
     * The order's stored PayMongo payment ID (pay_…), or null if it has none.
     *
     * @param Order $order
     * @return string|null
     */
    private function getPaymentId(Order $order): ?string
    {
        $id = (string) $order->getPayment()?->getAdditionalInformation('paymongo_payment_id');

        return $id !== '' ? $id : null;
    }

    /**
     * Store the PayMongo payment behind a capture on the order's payment, if PayMongo named one.
     *
     * It replaces a different payment ID already stored, which is logged. Unlike a webhook,
     * which may come from another store on the same PayMongo account and so never replaces
     * one, this answer comes from the order's own payment intent. The caller saves the order.
     *
     * @param Order $order
     * @param string|null $paymentId
     * @return void
     */
    private function recordPaymentId(Order $order, ?string $paymentId): void
    {
        if ($paymentId === null || $paymentId === '') {
            return;
        }

        $storedPaymentId = $this->getPaymentId($order);
        if ($storedPaymentId !== null && $storedPaymentId !== $paymentId) {
            $this->logger->warning('PaymentCapture: replaced the stored PayMongo payment ID with the captured one', [
                'order_id'          => $order->getIncrementId(),
                'stored_payment_id' => $storedPaymentId,
                'payment_id'        => $paymentId,
            ]);
        }

        $order->getPayment()->setAdditionalInformation('paymongo_payment_id', $paymentId);
    }

    /**
     * Look up and store a captured order's PayMongo payment ID if it is missing, before invoicing.
     *
     * For an order whose invoice is created after the capture that took the money: Capture
     * from PayMongo, or Capture Online, on a "captured but not invoiced" order, which may have
     * been captured before the ID was stored. Without it the invoice's transaction ID is the
     * payment intent's. Never throws: a failed lookup must not stop the invoice of money
     * already taken, and the refund looks the payment up again. The caller saves the order.
     *
     * @param Order $order
     * @return void
     */
    public function ensurePaymentId(Order $order): void
    {
        try {
            $this->findPaymentId($order);
        } catch (\Exception $e) {
            $this->logger->warning('PaymentCapture: could not look up the PayMongo payment for the invoice', [
                'order_id'          => $order->getIncrementId(),
                'payment_intent_id' => $this->getPaymentIntentId($order),
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store the PayMongo payment behind a capture PayMongo confirmed, without ever failing it.
     *
     * @param Order $order
     * @param string|null $paymentId The paid payment PayMongo named, if any
     * @param bool $lookUpIfMissing Whether to ask PayMongo when none was named
     * @return void
     */
    private function keepPaymentId(Order $order, ?string $paymentId, bool $lookUpIfMissing): void
    {
        if ($paymentId === null) {
            if ($lookUpIfMissing) {
                $this->ensurePaymentId($order);
            }
            return;
        }

        try {
            $this->recordPaymentId($order, $paymentId);
        } catch (\Exception $e) {
            $this->logger->error('PaymentCapture: could not store the PayMongo payment ID', [
                'order_id'   => $order->getIncrementId(),
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store a capture outcome on the payment in the database, leaving the order object alone.
     *
     * @param int $orderId
     * @param string $result One of the RESULT_* constants
     * @param string|null $paymentId The PayMongo payment behind the capture, if known
     * @return void
     */
    private function persistResult(int $orderId, string $result, ?string $paymentId): void
    {
        try {
            $fresh = $this->orderFactory->create()->load($orderId);
            $payment = $fresh->getPayment();
            if (!$fresh->getId() || $payment === null) {
                return;
            }

            $this->recordResult($fresh, $result, self::CAPTURED_STATUS);
            $this->recordPaymentId($fresh, $paymentId);
            $payment->save();
        } catch (\Exception $e) {
            $this->logger->critical('PaymentCapture: could not store the capture outcome', [
                'order_id' => $orderId,
                'result'   => $result,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record on a fresh copy of the order that PayMongo took the money but no invoice exists.
     *
     * @param int $orderId
     * @param string $paymentIntentId
     * @param string|null $paymentId The PayMongo payment behind the capture, if known
     * @param string $error Why the invoice could not be created
     * @return void
     */
    private function recordCapturedNotInvoiced(
        int $orderId,
        string $paymentIntentId,
        ?string $paymentId,
        string $error
    ): void {
        try {
            $fresh = $this->orderFactory->create()->load($orderId);
            if (!$fresh->getId()) {
                return;
            }

            $this->recordResult($fresh, self::RESULT_CAPTURED_NOT_INVOICED, self::CAPTURED_STATUS);
            $this->recordPaymentId($fresh, $paymentId);
            $fresh->addCommentToStatusHistory(__(
                'PayMongo captured the payment (payment intent %1), so the customer HAS been charged, but the '
                . 'invoice could not be created (%2). Use Capture from PayMongo to create the invoice; the '
                . 'customer will not be charged again.',
                $paymentIntentId,
                $error
            ));
            if ($fresh->canHold()) {
                $fresh->hold();
            }
            $fresh->save();
        } catch (\Exception $e) {
            $this->logger->critical('PaymentCapture: could not record a captured payment that was not invoiced', [
                'order_id'          => $orderId,
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
