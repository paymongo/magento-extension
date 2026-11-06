<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Payment;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\OrderBusyException;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Receives and processes PayMongo webhook events.
 *
 * Security model (v1.1.0.0):
 * - Verifies the Paymongo-Signature header using HMAC-SHA256 with the stored webhook secret,
 *   resolved (see resolveStoreIdFromBody()) against whichever store the referenced order
 *   belongs to, so a store/website with its own webhook secret or environment override is
 *   checked correctly instead of always falling back to default scope.
 * - Enforces a 5-minute timestamp window to reject stale/delayed replays.
 * - Stores processed event IDs in paymongo_webhook_events to reject exact replays.
 *
 * Handled events:
 * - payment.paid / checkout_session.payment.paid — transitions order to Processing and creates invoice
 * - payment.failed — cancels the order
 *
 * CSRF validation is intentionally disabled; this endpoint is called by an
 * external system and cannot carry a Magento CSRF token.
 *
 * Route: POST paymongo/payment/webhook
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Maximum age of a valid webhook timestamp in seconds.
     *
     * Webhooks older than this window are rejected as potential replays even if
     * the HMAC is valid — an attacker who captured a valid webhook cannot replay
     * it more than 5 minutes later.
     */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /** Forward allowance for clock skew between PayMongo's hosts and this server. */
    private const SIGNATURE_CLOCK_SKEW_SECONDS = 60;

    /** @var RequestInterface */
    private RequestInterface $request;

    /** @var JsonFactory */
    private JsonFactory $jsonFactory;

    /** @var Json */
    private Json $json;

    /** @var Config */
    private Config $config;

    /** @var ResourceConnection */
    private ResourceConnection $resourceConnection;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var OrderCollectionFactory */
    private OrderCollectionFactory $orderCollectionFactory;

    /** @var InvoiceService */
    private InvoiceService $invoiceService;

    /** @var TransactionFactory */
    private TransactionFactory $transactionFactory;

    /** @var OrderSender */
    private OrderSender $orderSender;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var Logger */
    private Logger $logger;

    /** @var PaymentReference */
    private PaymentReference $paymentReference;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var PaidCancelledOrder */
    private PaidCancelledOrder $paidCancelledOrder;

    /** @var OrderStatusHistoryRepositoryInterface */
    private OrderStatusHistoryRepositoryInterface $historyRepository;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param Json $json
     * @param Config $config
     * @param ResourceConnection $resourceConnection
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param LockManagerInterface $lockManager
     * @param Logger $logger
     * @param PaymentReference $paymentReference
     * @param CaptureMode $captureMode
     * @param PaymentConfirmation $paymentConfirmation
     * @param PaidCancelledOrder $paidCancelledOrder
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Json $json,
        Config $config,
        ResourceConnection $resourceConnection,
        OrderRepositoryInterface $orderRepository,
        OrderCollectionFactory $orderCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderSender $orderSender,
        LockManagerInterface $lockManager,
        Logger $logger,
        PaymentReference $paymentReference,
        CaptureMode $captureMode,
        PaymentConfirmation $paymentConfirmation,
        PaidCancelledOrder $paidCancelledOrder,
        OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->json = $json;
        $this->config = $config;
        $this->resourceConnection = $resourceConnection;
        $this->orderRepository = $orderRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderSender = $orderSender;
        $this->lockManager = $lockManager;
        $this->logger = $logger;
        $this->paymentReference = $paymentReference;
        $this->captureMode = $captureMode;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->paidCancelledOrder = $paidCancelledOrder;
        $this->historyRepository = $historyRepository;
    }

    /**
     * Parse the incoming webhook payload and dispatch to the appropriate handler.
     *
     * Verifies the signature first, then checks the event has not been processed
     * before (replay protection), dispatches to the handler, and marks it as processed.
     *
     * Returns HTTP 200 with {"status":"ok"} on success, or HTTP 400 with
     * {"status":"error","message":"…"} on signature/replay failures and processing errors.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $body = $this->request->getContent();
            $signatureHeader = $this->request->getHeader('Paymongo-Signature') ?: '';

            $storeId = $this->resolveStoreIdFromBody($body);
            $this->verifySignature($body, $signatureHeader, $storeId);

            $payload = $this->json->unserialize($body);
            $eventId = $payload['data']['id'] ?? null;
            $eventType = $payload['data']['attributes']['type'] ?? null;
            $eventData = $payload['data']['attributes']['data'] ?? [];

            $this->logger->info('Webhook received', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            if ($eventId && $this->isReplay($eventId)) {
                $this->logger->info('Webhook replay rejected', ['event_id' => $eventId]);
                $result->setData(['status' => 'ok']);
                return $result;
            }

            switch ($eventType) {
                case 'payment.paid':
                case 'checkout_session.payment.paid':
                    $this->handlePaymentPaid($eventData, (string) $eventType);
                    break;
                case 'payment.failed':
                    $this->handlePaymentFailed($eventData);
                    break;
                default:
                    $this->logger->info('Unhandled webhook event type', ['event_type' => $eventType]);
            }

            if ($eventId) {
                $this->markProcessed($eventId);
            }

            $result->setData(['status' => 'ok']);
        } catch (OrderBusyException $e) {
            // Not marked processed: PayMongo delivers the event again later.
            $this->logger->warning('Webhook: order busy, PayMongo asked to retry', ['error' => $e->getMessage()]);
            $result->setHttpResponseCode(503);
            $result->setData(['status' => 'retry']);
        } catch (\Exception $e) {
            $this->logger->error('Webhook processing error', ['error' => $e->getMessage()]);
            $result->setHttpResponseCode(400);
            // The detail stays in the log. Echoing it told an unauthenticated caller whether
            // a webhook secret was configured, whether the header parsed, and whether it was
            // the timestamp or the signature that failed — a free read on the store's setup.
            $result->setData(['status' => 'error']);
        }

        return $result;
    }

    /**
     * Verify the Paymongo-Signature header using HMAC-SHA256.
     *
     * Header format: t=<unix_timestamp>,te=<test_hmac>,li=<live_hmac>
     * HMAC input:    "<timestamp>.<raw_request_body>"
     *
     * Only the variant matching the configured Environment is accepted — te in Sandbox,
     * li in Production — so a test-mode signature can never authorise an event on a live
     * store. The timestamp must be no older than SIGNATURE_TOLERANCE_SECONDS and no more
     * than SIGNATURE_CLOCK_SKEW_SECONDS in the future.
     *
     * $storeId (resolved by resolveStoreIdFromBody() before this is called) lets the
     * correct store's webhook secret/environment be checked when one has its own
     * override — Magento's own scope-config resolution falls back to website/default
     * transparently when the resolved store has no override, so this is a no-op for
     * any merchant not using per-store/per-website PayMongo credentials.
     *
     * @param string $body Raw request body (before JSON decoding)
     * @param string $signatureHeader Value of the Paymongo-Signature request header
     * @param int|null $storeId
     * @return void
     * @throws \RuntimeException if the webhook secret is not configured, or the signature
     *                           is absent, expired, malformed, or invalid
     */
    private function verifySignature(string $body, string $signatureHeader, ?int $storeId): void
    {
        $secret = $this->config->getWebhookSecret($storeId);

        if (empty($secret)) {
            throw new \RuntimeException('Webhook secret is not configured — rejecting webhook.');
        }

        if (empty($signatureHeader)) {
            throw new \RuntimeException('Missing Paymongo-Signature header.');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = explode('=', $part, 2) + [null, null];
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;

        // PayMongo sends the test-mode HMAC as "te" and the live-mode one as "li". Accepting
        // whichever is present, against whichever secret happens to be configured, lets a
        // test-mode signing secret validate a webhook aimed at a live store. Bind the field to
        // the configured environment so a test signature can never authorise a production event.
        $receivedHmac = $this->config->isProductionEnvironment($storeId)
            ? ($parts['li'] ?? '')
            : ($parts['te'] ?? '');

        if ($timestamp === 0 || $receivedHmac === '') {
            throw new \RuntimeException('Malformed Paymongo-Signature header.');
        }

        // Deliberately asymmetric. abs() also accepted timestamps up to the full tolerance
        // in the future, roughly doubling the window a captured signature stays replayable;
        // a small forward allowance is all that is needed for clock skew between hosts.
        $age = time() - $timestamp;
        if ($age > self::SIGNATURE_TOLERANCE_SECONDS || $age < -self::SIGNATURE_CLOCK_SKEW_SECONDS) {
            throw new \RuntimeException('Webhook timestamp is outside the allowed 5-minute window.');
        }

        $expectedHmac = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        if (!hash_equals($expectedHmac, $receivedHmac)) {
            throw new \RuntimeException('Webhook signature verification failed.');
        }
    }

    /**
     * Best-effort, read-only peek at the payload to resolve which store's webhook
     * secret should be checked, since Magento's config scope lookups need a store ID
     * and the store isn't otherwise known until after the (not-yet-verified) body is
     * parsed and its order looked up.
     *
     * Safe to do before signature verification: this only decides WHICH secret to
     * check the HMAC against, never trusts the payload for anything else, and an
     * attacker who doesn't already know the resolved store's secret gains nothing by
     * influencing which one gets tried — verification still fails the same way either
     * way. If the reference number is missing, malformed, or doesn't match a real
     * order, this returns null and verifySignature() falls back to default scope
     * exactly as it always has.
     *
     * @param string $body Raw request body (before JSON decoding)
     * @return int|null
     */
    private function resolveStoreIdFromBody(string $body): ?int
    {
        try {
            $payload = $this->json->unserialize($body);
        } catch (\Exception) {
            return null;
        }

        $eventData = $payload['data']['attributes']['data'] ?? [];
        $referenceNumber = $eventData['attributes']['reference_number']
            ?? $eventData['attributes']['description']
            ?? null;

        if (!$referenceNumber) {
            return null;
        }

        $incrementId = $this->paymentReference->resolveIncrementId((string) $referenceNumber);
        if ($incrementId === null) {
            return null;
        }

        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('increment_id', $incrementId);

        /** @var Order $order */
        $order = $orderCollection->getFirstItem();

        return $order->getId() ? (int) $order->getStoreId() : null;
    }

    /**
     * Return true if this event ID has already been processed (replay detected).
     *
     * @param string $eventId PayMongo event ID (e.g. evt_abc123)
     * @return bool
     */
    private function isReplay(string $eventId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('paymongo_webhook_events');
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE event_id = ?',
            [$eventId]
        );
        return (int) $count > 0;
    }

    /**
     * Record a successfully processed event ID so future duplicates are rejected.
     *
     * Uses INSERT IGNORE so a race condition between two simultaneous deliveries
     * of the same event is safe — the second insert silently no-ops.
     *
     * @param string $eventId PayMongo event ID
     * @return void
     */
    private function markProcessed(string $eventId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('paymongo_webhook_events');
        $connection->insertOnDuplicate($table, ['event_id' => $eventId]);
    }

    /**
     * Process a payment.paid or checkout_session.payment.paid webhook event.
     *
     * Finds the order by its increment ID (extracted from the reference number),
     * transitions it to Processing, creates an invoice, and sends a confirmation email.
     * Idempotent: an order no longer awaiting payment is not confirmed again, but still
     * gets the PayMongo payment ID if it wasn't stored yet (see processPaymentPaid()).
     *
     * PayMongo sends every payment on the account here, including ones another system
     * created. A reference this module did not send is logged and ignored.
     *
     * @param array $eventData The event data object from the webhook payload
     * @param string $eventType 'payment.paid' or 'checkout_session.payment.paid'
     * @return void
     */
    private function handlePaymentPaid(array $eventData, string $eventType): void
    {
        $referenceNumber = $eventData['attributes']['reference_number']
            ?? $eventData['attributes']['description']
            ?? null;

        if (!$referenceNumber) {
            $this->logger->warning('Webhook payment.paid: no reference number found');
            return;
        }

        $incrementId = $this->paymentReference->resolveIncrementId((string) $referenceNumber);
        if ($incrementId === null) {
            $this->logger->info('Webhook payment.paid: not a Magento reference, ignored', [
                'reference_number' => $referenceNumber,
            ]);
            return;
        }

        $paymentId = $this->extractPaymentId($eventData, $eventType);

        $this->logger->info('Processing webhook payment.paid', [
            'reference_number' => $referenceNumber,
            'increment_id' => $incrementId,
            'payment_id' => $paymentId,
        ]);

        $this->withOrderLock($incrementId, $eventType, fn () => $this->processPaymentPaid(
            $incrementId,
            $paymentId,
            $this->extractPaymentIntentId($eventData, $eventType),
            $eventType === 'checkout_session.payment.paid' ? ($eventData['id'] ?? null) : null
        ));
    }

    /**
     * Extract the PayMongo payment ID from a payment.paid / checkout_session.payment.paid
     * webhook's event data object.
     *
     * For 'payment.paid', $eventData is the Payment resource itself, so its own "id" is
     * the payment ID. For 'checkout_session.payment.paid', $eventData is the Checkout
     * Session resource, so the payment ID is the paid one among attributes.payments, picked
     * the same way as Success and Refresh from PayMongo (PaymentConfirmation::findPaidPaymentId()).
     * Before 2.0.1 it took the first payment listed, paid or not: after a declined attempt, the
     * order kept the failed payment's ID, which is never replaced, and online refunds to it
     * were refused (PYM-31).
     *
     * @param array $eventData
     * @param string $eventType
     * @return string|null
     */
    private function extractPaymentId(array $eventData, string $eventType): ?string
    {
        if ($eventType === 'payment.paid') {
            return $eventData['id'] ?? null;
        }

        return $this->paymentConfirmation->findPaidPaymentId($eventData['attributes']['payments'] ?? []);
    }

    /**
     * Inner logic for handlePaymentPaid, called only after the per-order lock is held.
     *
     * Only an order still awaiting payment is confirmed. Any other order (the customer's
     * browser return already confirmed it, a duplicate webhook arrived, or it has since
     * shipped, been cancelled or closed) is left in its state, but the PayMongo payment ID
     * is still saved if it wasn't already stored. Otherwise an order confirmed by webhook
     * first would never get one, which breaks reconciliation matching and online refunds.
     *
     * Before this checked for Processing only. An Authorize Only order is captured when it
     * ships, PayMongo then sends payment.paid for the capture, and the order, by then
     * Complete, was confirmed again: a misleading "Card authorised" note and a second order
     * confirmation email to the customer. A late payment.paid could likewise move a
     * cancelled order to Processing.
     *
     * An event for another checkout is ignored entirely: PayMongo sends every store's webhooks
     * to every store on the account, and another installation can have an order with this
     * number. A payment for an order that is already cancelled raises the admin alert
     * (PaidCancelledOrder) (PYM-32).
     *
     * @param string $incrementId
     * @param string|null $paymentId PayMongo payment ID (pay_...), or null if not resolvable
     * @param string|null $eventIntentId The payment intent the event is about, if it names one
     * @param string|null $eventSessionId The checkout session the event is about, if it names one
     * @return void
     */
    private function processPaymentPaid(
        string $incrementId,
        ?string $paymentId,
        ?string $eventIntentId = null,
        ?string $eventSessionId = null
    ): void {
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('increment_id', $incrementId);

        /** @var Order $order */
        $order = $orderCollection->getFirstItem();

        if (!$order || !$order->getId()) {
            $this->logger->warning('Webhook: order not found', ['increment_id' => $incrementId]);
            return;
        }

        // Another store on the same PayMongo account can have an order with this number. Its
        // payment must not confirm, invoice or give a payment ID to this one (PYM-32).
        if (!$this->isForOrder($order, $eventIntentId, $eventSessionId)) {
            $this->logger->info('Webhook: payment for another checkout with this order number, ignored', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => $paymentId,
            ]);
            return;
        }

        $adopted = $this->adoptCheckout($order, $eventIntentId, $eventSessionId);

        // A stored payment ID is never replaced. PayMongo sends every webhook to every store
        // on the account, so another store's payment can carry this order's number, and
        // overwriting the ID would point this order's refunds at that payment.
        $payment = $order->getPayment();
        $storedPaymentId = $payment->getAdditionalInformation('paymongo_payment_id');
        $backfilled = false;
        if ($paymentId && !$storedPaymentId) {
            $payment->setAdditionalInformation('paymongo_payment_id', $paymentId);
            $backfilled = true;
        } elseif ($paymentId && $storedPaymentId !== $paymentId) {
            $this->logger->info('Webhook: order already has a different PayMongo payment ID, kept it', [
                'order_id' => $order->getIncrementId(),
                'stored_payment_id' => $storedPaymentId,
                'webhook_payment_id' => $paymentId,
            ]);
        }

        if (!in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)) {
            $this->logger->info('Webhook: order no longer awaiting payment, not confirmed again', [
                'order_id' => $order->getIncrementId(),
                'state' => $order->getState(),
            ]);
            $alerted = false;
            if ($order->getState() === Order::STATE_CANCELED) {
                $this->logger->warning('Webhook: PayMongo reports a payment for a cancelled order', [
                    'order_id' => $order->getIncrementId(),
                    'payment_id' => $paymentId,
                ]);
                // An event cannot tell a card hold from a charge; the alert asks PayMongo which.
                $alerted = $this->paidCancelledOrder->alert($order, $paymentId);
            }

            if ($alerted || $backfilled || $adopted) {
                $this->orderRepository->save($order);
            }
            if ($backfilled) {
                $this->logger->info('Webhook: backfilled PayMongo payment ID on an already-processed order', [
                    'order_id' => $order->getIncrementId(),
                    'payment_id' => $paymentId,
                ]);
            }

            return;
        }

        $isAuthorizeOnly = $this->captureMode->isAuthorizeOnly($order);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);

        if ($isAuthorizeOnly) {
            $order->addCommentToStatusHistory(
                __('Card authorised via PayMongo webhook. Invoice will be created when shipment is captured.')
            );
            $this->logger->info('Webhook: authorize-only card order, skipping invoice', [
                'order_id' => $order->getIncrementId(),
            ]);
        } else {
            $order->addCommentToStatusHistory(__('Payment confirmed via PayMongo webhook.'));

            if ($order->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();

                $transaction = $this->transactionFactory->create();
                $transaction->addObject($invoice)->addObject($order)->save();

                $this->logger->info('Invoice created via webhook', [
                    'order_id' => $order->getIncrementId(),
                    'invoice_id' => $invoice->getIncrementId(),
                ]);
            }
        }

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->error('Webhook: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a payment.failed webhook event: note the declined attempt on the order.
     *
     * A failed attempt does not end the checkout. PayMongo's payment page stays open, and the
     * customer can retry with another card or method. Before PYM-32 this cancelled the order,
     * so a customer who then paid was charged for an order Magento had cancelled. The order
     * now stays awaiting payment, with a comment giving PayMongo's reason. A checkout the
     * customer abandons is still cancelled: by the Failed controller when they leave
     * PayMongo's page, or by Magento's cron for stale Pending Payment orders. Either way
     * CloseCheckoutOnCancel then closes PayMongo's page, so no one can pay a cancelled order.
     *
     * A reference this module did not send is logged and ignored.
     *
     * @param array $eventData The event data object from the webhook payload (a Payment resource)
     * @return void
     */
    private function handlePaymentFailed(array $eventData): void
    {
        $referenceNumber = $eventData['attributes']['reference_number']
            ?? $eventData['attributes']['description']
            ?? null;

        if (!$referenceNumber) {
            $this->logger->warning('Webhook payment.failed: no reference number found');
            return;
        }

        $incrementId = $this->paymentReference->resolveIncrementId((string) $referenceNumber);
        if ($incrementId === null) {
            $this->logger->info('Webhook payment.failed: not a Magento reference, ignored', [
                'reference_number' => $referenceNumber,
            ]);
            return;
        }

        // A retry that succeeds can be reported at nearly the same moment: the lock keeps this
        // from saving over its confirmation.
        $this->withOrderLock($incrementId, 'payment.failed', fn () => $this->processPaymentFailed(
            $incrementId,
            $this->extractPaymentIntentId($eventData, 'payment.failed'),
            $eventData['attributes']['failed_message'] ?? $eventData['attributes']['failed_code'] ?? null
        ));
    }

    /**
     * Inner logic for handlePaymentFailed, called only after the per-order lock is held.
     *
     * Only an order still awaiting payment, and only for its own checkout, gets the comment.
     * The order is never cancelled here.
     *
     * @param string $incrementId
     * @param string|null $eventIntentId The payment intent the failed payment belongs to
     * @param string|null $reason PayMongo's reason, e.g. "The card has insufficient funds..."
     * @return void
     */
    private function processPaymentFailed(string $incrementId, ?string $eventIntentId, ?string $reason): void
    {
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('increment_id', $incrementId);

        /** @var Order $order */
        $order = $orderCollection->getFirstItem();

        if (!$order || !$order->getId()) {
            $this->logger->warning('Webhook: order not found for failed payment', [
                'increment_id' => $incrementId,
            ]);
            return;
        }

        if (!in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)
            || !$this->isForOrder($order, $eventIntentId, null)
        ) {
            $this->logger->info('Webhook payment.failed: order not awaiting payment, or another checkout, ignored', [
                'order_id' => $order->getIncrementId(),
                'state' => $order->getState(),
            ]);
            return;
        }

        // Only the comment is saved, not the order: saving the order would move its updated_at,
        // from which Magento's cron counts the Pending Payment lifetime, so every declined
        // attempt (a card tester's, say) would put off cancelling an abandoned checkout.
        $reason = rtrim(trim((string) $reason), '.');
        $history = $order->addCommentToStatusHistory(__(
            'A payment attempt was declined by PayMongo (%1). The order stays open: the customer can still pay '
            . 'on PayMongo\'s page with another card or method. It is cancelled if they leave the page, or once it '
            . 'has waited longer than the Pending Payment Order Lifetime.',
            $reason !== '' ? $reason : __('no reason given')
        ));
        $this->historyRepository->save($history);

        $this->logger->info('Webhook: payment attempt declined, order kept open for a retry', [
            'order_id' => $order->getIncrementId(),
            'reason' => $reason,
        ]);
    }

    /**
     * Run $process while holding the order's lock.
     *
     * It is the lock Success, Refresh from PayMongo and the PayMongo capture (PaymentCapture)
     * use, so they never save over each other. When another process holds it for the whole
     * wait, OrderBusyException is thrown: the event is not marked processed and PayMongo is
     * asked to deliver it again.
     *
     * @param string $incrementId
     * @param string $eventType For the log
     * @param callable $process
     * @return void
     * @throws OrderBusyException when the lock cannot be taken
     */
    private function withOrderLock(string $incrementId, string $eventType, callable $process): void
    {
        $lockName = PaymentCapture::ORDER_LOCK_PREFIX . $incrementId;
        if (!$this->lockManager->lock($lockName, PaymentCapture::ORDER_LOCK_TIMEOUT)) {
            throw new OrderBusyException(sprintf('Order %s is locked; %s not processed', $incrementId, $eventType));
        }

        try {
            $process();
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * The payment intent a webhook event is about, if it names one.
     *
     * @param array $eventData
     * @param string $eventType
     * @return string|null
     */
    private function extractPaymentIntentId(array $eventData, string $eventType): ?string
    {
        $id = $eventType === 'checkout_session.payment.paid'
            ? ($eventData['attributes']['payment_intent']['id'] ?? null)
            : ($eventData['attributes']['payment_intent_id'] ?? null);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Whether a webhook event is about this order's own checkout.
     *
     * PayMongo sends every store's webhooks to every store on the account, and two
     * installations can have an order with the same number. The event's payment intent or
     * checkout session must match one stored on the order: the current one, or one opened
     * before it (CheckoutStart::PREVIOUS_CHECKOUTS_KEY). An order with neither stored, or an
     * event naming neither, cannot be checked and counts as a match, so a real problem is
     * never hidden.
     *
     * @param Order $order
     * @param string|null $eventIntentId
     * @param string|null $eventSessionId
     * @return bool
     */
    private function isForOrder(Order $order, ?string $eventIntentId, ?string $eventSessionId): bool
    {
        $payment = $order->getPayment();
        $intents = [(string) $payment?->getAdditionalInformation('paymongo_payment_intent_id')];
        $sessions = [(string) $payment?->getAdditionalInformation('paymongo_checkout_session_id')];
        // A payment can complete on a page opened before the current one (CheckoutStart).
        foreach ((array) ($payment?->getAdditionalInformation(CheckoutStart::PREVIOUS_CHECKOUTS_KEY) ?? []) as $old) {
            $intents[] = (string) ($old['intent'] ?? '');
            $sessions[] = (string) ($old['session'] ?? '');
        }
        $intents = array_filter($intents);
        $sessions = array_filter($sessions);

        if ($eventIntentId !== null && $intents !== []) {
            return in_array($eventIntentId, $intents, true);
        }
        if ($eventSessionId !== null && $sessions !== []) {
            return in_array($eventSessionId, $sessions, true);
        }

        return true;
    }

    /**
     * Make the checkout an event is about the order's current one, if it was an earlier one.
     *
     * The payment was completed on a page opened before the current one, so that page's intent
     * and session are the ones a capture, a refund or the alert must use. The caller saves.
     *
     * @param Order $order
     * @param string|null $eventIntentId
     * @param string|null $eventSessionId
     * @return bool Whether anything changed
     */
    private function adoptCheckout(Order $order, ?string $eventIntentId, ?string $eventSessionId): bool
    {
        $payment = $order->getPayment();
        $changed = false;
        if ($eventIntentId !== null
            && $eventIntentId !== (string) $payment->getAdditionalInformation('paymongo_payment_intent_id')
        ) {
            $payment->setAdditionalInformation('paymongo_payment_intent_id', $eventIntentId);
            $changed = true;
        }
        if ($eventSessionId !== null
            && $eventSessionId !== (string) $payment->getAdditionalInformation('paymongo_checkout_session_id')
        ) {
            $payment->setAdditionalInformation('paymongo_checkout_session_id', $eventSessionId);
            $changed = true;
        }
        if ($changed) {
            $this->logger->info('Webhook: payment completed on an earlier PayMongo page, now the order\'s checkout', [
                'order_id' => $order->getIncrementId(),
                'payment_intent_id' => $eventIntentId,
                'checkout_session_id' => $eventSessionId,
            ]);
        }

        return $changed;
    }

    /**
     * Skip CSRF exception generation — external webhooks cannot supply a CSRF token.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Bypass CSRF validation for this endpoint — it is called by the PayMongo API, not a browser.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
