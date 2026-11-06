<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Checkout\EnabledMethodTypes;
use PayMongo\Payment\Model\Method\AbstractPaymongoMethod;
use PayMongo\Payment\Model\PaymongoPayment;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;

/**
 * Knows whether an order's PayMongo payment page can still take a payment, and opens a new one safely.
 *
 * Every visit to the redirect page, and every createPaymongoCheckoutSession call, creates a new
 * checkout session and stores it in place of the previous one. Before PYM-32 the previous page
 * stayed payable: a customer who refreshed the redirect and then paid in the first tab paid a
 * session the order no longer knew, and the webhook, which only accepts this order's own
 * checkout, ignored it. A page could also be opened for an order that was already cancelled.
 *
 * open() runs under the order lock the webhook and Success use, on a freshly loaded order, so
 * two requests at once (a double click, a retried API call) cannot both open a page. Before a
 * new session is created:
 * - an order no longer awaiting payment (cancelled, paid, closed) is refused;
 * - a previous session the customer already paid (or authorised) is refused, so the customer
 *   is not charged twice;
 * - a previous session with a payment still processing is refused until it settles;
 * - a previous session that could still be paid is expired, so only one page is payable.
 *
 * openPage() is the one place that creates a page, for the redirect and the
 * createPaymongoCheckoutSession resolver. sessionState() is the one place that reads a
 * session's state, for open(), the Failed controller, CloseCheckoutOnCancel and
 * ReleaseHoldOnCancel; within one request it asks PayMongo once per session, so cancelling an
 * order checks PayMongo once, not once per step (PYM-33).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CheckoutStart
{
    /** The order has no PayMongo checkout session. */
    public const STATE_NONE = 'none';

    /** The customer can still pay on the session's page. */
    public const STATE_OPEN = 'open';

    /**
     * The customer started paying and PayMongo waits on them: a 3DS check, or approval in the
     * GCash or Maya app. The payment may still complete.
     */
    public const STATE_ACTION = 'action';

    /** PayMongo took, or holds, the customer's money. */
    public const STATE_PAID = 'paid';

    /** A payment is under way and may still succeed. */
    public const STATE_PROCESSING = 'processing';

    /** The session can no longer take a payment (expired, or its intent cancelled). */
    public const STATE_CLOSED = 'closed';

    /**
     * Payment intent statuses in which the customer can still pay on a PayMongo page. No status
     * means the session has no payment intent yet.
     */
    private const OPEN_INTENT_STATUSES = [null, 'awaiting_payment_method'];

    /**
     * Payment additional_information key listing the order's earlier checkouts, each as
     * ['session' => cs_…, 'intent' => pi_…], so a payment completed on one is still recognised.
     */
    public const PREVIOUS_CHECKOUTS_KEY = 'paymongo_previous_checkouts';

    /**
     * How many earlier checkouts are kept.
     */
    private const PREVIOUS_CHECKOUTS_KEPT = 10;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var OrderFactory */
    private OrderFactory $orderFactory;

    /** @var Logger */
    private Logger $logger;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var PaymentHelper */
    private PaymentHelper $paymentHelper;

    /** @var EnabledMethodTypes */
    private EnabledMethodTypes $enabledMethodTypes;

    /**
     * PayMongo's answer for each checkout session asked about in this request, by session ID.
     *
     * @var array<string, array>
     */
    private array $sessionChecks = [];

    /**
     * @param PaymentConfirmation $paymentConfirmation
     * @param PaymongoClient $paymongoClient
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     * @param Logger $logger
     * @param CaptureMode $captureMode
     * @param OrderRepositoryInterface $orderRepository
     * @param PaymentHelper $paymentHelper
     * @param EnabledMethodTypes $enabledMethodTypes
     */
    public function __construct(
        PaymentConfirmation $paymentConfirmation,
        PaymongoClient $paymongoClient,
        LockManagerInterface $lockManager,
        OrderFactory $orderFactory,
        Logger $logger,
        CaptureMode $captureMode,
        OrderRepositoryInterface $orderRepository,
        PaymentHelper $paymentHelper,
        EnabledMethodTypes $enabledMethodTypes
    ) {
        $this->paymentConfirmation = $paymentConfirmation;
        $this->paymongoClient = $paymongoClient;
        $this->lockManager = $lockManager;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->captureMode = $captureMode;
        $this->orderRepository = $orderRepository;
        $this->paymentHelper = $paymentHelper;
        $this->enabledMethodTypes = $enabledMethodTypes;
    }

    /**
     * Whether the order was paid with one of this module's methods.
     *
     * @param Order $order
     * @return bool
     */
    public function isPaymongoOrder(Order $order): bool
    {
        $method = (string) $order->getPayment()?->getMethod();

        return $method === 'paymongo' || str_starts_with($method, 'paymongo_');
    }

    /**
     * The state of the order's PayMongo checkout session, one of the STATE_* constants.
     *
     * A payment still processing counts before an expired session, since it may complete.
     * PayMongo is asked once per session while an operation runs (a cancellation, say);
     * closeSession() and forget() make the next call ask again.
     *
     * @param Order $order
     * @return array{state: string, result: array|null} result is PaymentConfirmation's check, if one was made
     * @throws \Exception when PayMongo cannot be asked
     */
    public function sessionState(Order $order): array
    {
        $sessionId = (string) $order->getPayment()?->getAdditionalInformation('paymongo_checkout_session_id');
        if ($sessionId === '') {
            return ['state' => self::STATE_NONE, 'result' => null];
        }

        // Asked once per request: a cancellation reads the same session in the Failed controller,
        // ReleaseHoldOnCancel and CloseCheckoutOnCancel, one after the other.
        $result = $this->sessionChecks[$sessionId]
            ??= $this->paymentConfirmation->checkCheckoutSession($sessionId, (int) $order->getStoreId());
        $piStatus = $result['pi_status'] ?? null;

        // A payment under way counts even on an expired session: it may still complete.
        if ($result['confirmed']) {
            $state = self::STATE_PAID;
        } elseif ($piStatus === 'processing') {
            $state = self::STATE_PROCESSING;
        } elseif (($result['status'] ?? null) === 'expired') {
            $state = self::STATE_CLOSED;
        } elseif ($piStatus === 'awaiting_next_action') {
            $state = self::STATE_ACTION;
        } elseif (in_array($piStatus, self::OPEN_INTENT_STATUSES, true)) {
            $state = self::STATE_OPEN;
        } else {
            $state = self::STATE_CLOSED;
        }

        return ['state' => $state, 'result' => $result];
    }

    /**
     * Expire the order's checkout session, so its PayMongo page no longer takes a payment.
     *
     * @param Order $order
     * @return void
     * @throws \Exception when PayMongo refuses or cannot be reached
     */
    public function closeSession(Order $order): void
    {
        $sessionId = (string) $order->getPayment()->getAdditionalInformation('paymongo_checkout_session_id');
        $this->paymongoClient->expireCheckoutSession($sessionId, (int) $order->getStoreId());
        unset($this->sessionChecks[$sessionId]);

        $this->logger->info('PayMongo checkout session expired', [
            'order_id'            => $order->getIncrementId(),
            'checkout_session_id' => $sessionId,
        ]);
    }

    /**
     * Open a new PayMongo payment page for the order, under its lock.
     *
     * $create receives a freshly loaded copy of the order, creates the session, records it with
     * recordSession() and saves the order; its return value is returned.
     *
     * @param Order $order
     * @param callable $create fn (Order $order): mixed
     * @return mixed
     * @throws LocalizedException with a message for the customer when no new page may be opened
     */
    public function open(Order $order, callable $create): mixed
    {
        $lockName = PaymentCapture::ORDER_LOCK_PREFIX . $order->getIncrementId();
        if (!$this->lockManager->lock($lockName, PaymentCapture::ORDER_LOCK_TIMEOUT)) {
            throw new LocalizedException(__('Your order is being updated. Please try again in a moment.'));
        }

        try {
            $fresh = $this->orderFactory->create()->load((int) $order->getId());
            if ($fresh->getId()) {
                $order = $fresh;
            }
            $this->prepare($order);

            return $create($order);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Forget PayMongo's answer for the order's session, so the next sessionState() asks again.
     *
     * Called when an operation that read it is over (CloseCheckoutOnCancel, the last step of a
     * cancellation; the Failed controller), and after the payment changed on PayMongo's side
     * (ReleaseHoldOnCancel cancelling the intent). The answer must not outlive the operation:
     * cron and queue processes run for a long time, and would otherwise act on an old state.
     *
     * @param Order $order
     * @return void
     */
    public function forget(Order $order): void
    {
        unset($this->sessionChecks[(string) $order->getPayment()?->getAdditionalInformation(
            'paymongo_checkout_session_id'
        )]);
    }

    /**
     * Open a new PayMongo payment page for the order and record it, under the order's lock.
     *
     * The one place a page is created, for the redirect and the createPaymongoCheckoutSession
     * resolver (PYM-33). What the page offers, and whether it asks for a card hold, come from the
     * order's own payment method, never from the caller: a headless storefront could otherwise
     * open a card page, even an Authorize Only one, for a GCash order. The session, its payment
     * intent and the capture mode are stored on the order, which is saved. Without the intent,
     * CaptureOnShipment would have nothing to capture an Authorize Only order against.
     *
     * @param Order $order
     * @return array{session_id: string, checkout_url: string, payment_intent_id: string|null}
     * @throws LocalizedException with a message for the customer when no page may be opened, or
     *         PayMongo gives no page
     */
    public function openPage(Order $order): array
    {
        $methodCode = (string) $order->getPayment()->getMethod();
        $methodTypes = $this->paymentTypes($methodCode);
        $authorizeOnly = $this->captureMode->shouldAuthorizeOnly($methodCode, (int) $order->getStoreId());

        return $this->open($order, function (Order $order) use ($methodTypes, $authorizeOnly): array {
            $response = $this->paymongoClient->createCheckoutSession($order, $methodTypes, $authorizeOnly);
            $sessionId = $response['data']['id'] ?? null;
            $checkoutUrl = $response['data']['attributes']['checkout_url'] ?? null;
            if (!$sessionId || !$checkoutUrl) {
                throw new LocalizedException(__('Unable to create PayMongo checkout session.'));
            }

            $paymentIntentId = $response['data']['attributes']['payment_intent']['id'] ?? null;
            $payment = $order->getPayment();
            $this->recordSession($payment, $sessionId, $paymentIntentId);
            $this->captureMode->record($payment, $authorizeOnly);
            $this->orderRepository->save($order);

            return [
                'session_id'        => $sessionId,
                'checkout_url'      => $checkoutUrl,
                'payment_intent_id' => $paymentIntentId,
            ];
        });
    }

    /**
     * The PayMongo payment method types a page for this payment method offers.
     *
     * Every individual method offers its own (one, or both banks for an order placed with the
     * retired Direct Online Banking); the all-in-one method offers every individually enabled
     * PayMongo method on PayMongo's page.
     *
     * @param string $methodCode Magento payment method code (e.g. 'paymongo_gcash', 'paymongo')
     * @return string[]|null PayMongo types (e.g. ['gcash']), or null for PayMongoClient's fallback list
     */
    public function paymentTypes(string $methodCode): ?array
    {
        if ($methodCode === PaymongoPayment::CODE) {
            $types = $this->enabledMethodTypes->getEnabledTypes();
            return $types !== [] ? $types : null;
        }

        try {
            $method = $this->paymentHelper->getMethodInstance($methodCode);
        } catch (\Exception $e) {
            $this->logger->warning('CheckoutStart: could not resolve the PayMongo method', [
                'method_code' => $methodCode,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }

        $types = $method instanceof AbstractPaymongoMethod ? $method->getCheckoutSessionTypes() : [];

        return $types !== [] ? $types : null;
    }

    /**
     * Store a new checkout session on the payment, in place of the previous one.
     *
     * The previous session's payment intent and payment are cleared even when PayMongo's
     * response names no intent. The previous session and intent are kept in
     * PREVIOUS_CHECKOUTS_KEY: a customer waiting on a 3DS check or an e-wallet app can still
     * complete that payment after a new page was opened, and the webhook must recognise it.
     *
     * @param Payment $payment
     * @param string $sessionId
     * @param string|null $paymentIntentId
     * @return void
     */
    public function recordSession(Payment $payment, string $sessionId, ?string $paymentIntentId): void
    {
        $previousSession = (string) $payment->getAdditionalInformation('paymongo_checkout_session_id');
        $previousIntent = (string) $payment->getAdditionalInformation('paymongo_payment_intent_id');
        if ($previousSession !== '' || $previousIntent !== '') {
            $previous = (array) ($payment->getAdditionalInformation(self::PREVIOUS_CHECKOUTS_KEY) ?? []);
            $previous[] = ['session' => $previousSession, 'intent' => $previousIntent];
            $payment->setAdditionalInformation(
                self::PREVIOUS_CHECKOUTS_KEY,
                array_slice($previous, -self::PREVIOUS_CHECKOUTS_KEPT)
            );
        }

        $payment->setAdditionalInformation('paymongo_checkout_session_id', $sessionId);
        if ($paymentIntentId) {
            $payment->setAdditionalInformation('paymongo_payment_intent_id', $paymentIntentId);
        } else {
            $payment->unsAdditionalInformation('paymongo_payment_intent_id');
        }
        $payment->unsAdditionalInformation('paymongo_payment_id');
    }

    /**
     * Make sure a new PayMongo payment page may be opened for the order, closing the previous one.
     *
     * Called by open(), holding the order's lock.
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException with a message for the customer when no new page may be opened
     */
    public function prepare(Order $order): void
    {
        if (!in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)) {
            $this->logger->warning('CheckoutStart: order is no longer awaiting payment, no page opened', [
                'order_id' => $order->getIncrementId(),
                'state'    => $order->getState(),
            ]);
            throw new LocalizedException(__(
                'Order #%1 can no longer be paid. Please place a new order.',
                $order->getIncrementId()
            ));
        }

        try {
            $state = $this->sessionState($order)['state'];
            // A payment waiting on 3DS or an e-wallet app is closed too: if it completes anyway,
            // the webhook still recognises it from PREVIOUS_CHECKOUTS_KEY.
            if ($state === self::STATE_OPEN || $state === self::STATE_ACTION) {
                $this->closeSession($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('CheckoutStart: could not check or close the previous checkout session', [
                'order_id' => $order->getIncrementId(),
                'error'    => $e->getMessage(),
            ]);
            throw new LocalizedException(__('We could not reach PayMongo. Please try again in a moment.'), $e);
        }

        if ($state === self::STATE_PAID) {
            $this->logger->warning('CheckoutStart: previous checkout session already paid, no page opened', [
                'order_id' => $order->getIncrementId(),
            ]);
            throw new LocalizedException(__(
                'We have already received your payment for order #%1. You will get a confirmation shortly.',
                $order->getIncrementId()
            ));
        }

        if ($state === self::STATE_PROCESSING) {
            throw new LocalizedException(__(
                'Your payment for order #%1 is still being processed. Please wait a moment before trying again.',
                $order->getIncrementId()
            ));
        }
    }
}
