<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use PayMongo\Payment\Gateway\PaymongoClient;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Asks PayMongo whether an order's checkout has actually been paid.
 *
 * The customer's return to the success page and the admin Refresh from PayMongo button
 * both have to answer this, and reconciliation re-checks it for orders Magento already
 * treats as paid. They share this class so they always agree.
 *
 * A checkout session whose status is "active" has NOT been paid: PayMongo's hosted page is
 * still open and waiting for the customer. A payment counts as confirmed only when PayMongo
 * reports the session paid, lists a paid payment behind it, or reports its payment intent
 * succeeded or awaiting capture (a card authorisation held under Authorize Only). Before
 * PYM-27 the Refresh button treated "active" as paid, which invoiced unpaid orders and
 * recorded the payment intent ID (pi_…) as the transaction, since no payment ID (pay_…)
 * existed.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentConfirmation
{
    /**
     * Order states still waiting for PayMongo to confirm payment. Anything else has already
     * been confirmed, shipped, cancelled or closed, and must not be confirmed a second time.
     *
     * Every PayMongo order waits in Pending Payment. Before 2.0.1 only All-in-One and Card had
     * a payment_action in etc/config.xml; Magento skipped every other method's initialize(),
     * so those orders waited in New (status Pending), which Magento's stale-order cron never
     * cancels (PYM-32). New stays here for orders placed before the upgrade.
     */
    public const AWAITING_PAYMENT_STATES = [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW];

    /**
     * Payment intent statuses that mean PayMongo has taken, or is holding, the customer's money.
     */
    private const CONFIRMED_INTENT_STATUSES = ['succeeded', 'awaiting_capture'];

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /**
     * @param PaymongoClient $paymongoClient
     */
    public function __construct(PaymongoClient $paymongoClient)
    {
        $this->paymongoClient = $paymongoClient;
    }

    /**
     * Check a Checkout Sessions order (every method, BPI and UnionBank included since PYM-6).
     *
     * The payment intent is always resolved when there is one. Its status is the only
     * reliable signal that money was actually taken, and it tells an authorisation hold
     * apart from a captured payment.
     *
     * @param string $sessionId PayMongo checkout session ID (cs_…)
     * @param int $storeId Store whose API keys to use
     * @return array{confirmed: bool, authorized: bool, status: ?string, payment_intent_id: ?string,
     *               payment_id: ?string, pi_status: ?string}
     * @throws LocalizedException on an API error
     */
    public function checkCheckoutSession(string $sessionId, int $storeId): array
    {
        $session = $this->paymongoClient->retrieveCheckoutSession($sessionId, $storeId);
        $attrs = $session['data']['attributes'] ?? [];
        $status = $attrs['status'] ?? null;
        $paymentIntentId = $attrs['payment_intent']['id'] ?? null;
        $paymentId = $this->findPaidPaymentId($attrs['payments'] ?? []);
        $piStatus = null;

        if ($paymentIntentId) {
            $pi = $this->paymongoClient->retrievePaymentIntent($paymentIntentId, $storeId);
            $piStatus = $pi['data']['attributes']['status'] ?? null;
            $paymentId = $paymentId ?? $this->findPaidPaymentId($pi['data']['attributes']['payments'] ?? []);
        }

        return [
            'confirmed' => self::isCheckoutSessionPaid($status, $paymentId, $piStatus),
            'authorized' => $piStatus === 'awaiting_capture',
            'status' => $status,
            'payment_intent_id' => $paymentIntentId,
            'payment_id' => $paymentId,
            'pi_status' => $piStatus,
        ];
    }

    /**
     * Check an order by its payment intent alone.
     *
     * Used for BPI / UnionBank orders placed before they moved to Checkout Sessions (PYM-6), and by
     * reconciliation for any order with a stored intent, since one call answers it.
     * A succeeded intent is paid; one awaiting capture is a card held under Authorize
     * Only, which online banking never is.
     *
     * @param string $intentId PayMongo payment intent ID (pi_…)
     * @param int $storeId Store whose API keys to use
     * @return array{confirmed: bool, authorized: bool, status: ?string, payment_intent_id: ?string,
     *               payment_id: ?string, pi_status: ?string}
     * @throws LocalizedException on an API error
     */
    public function checkPaymentIntent(string $intentId, int $storeId): array
    {
        $pi = $this->paymongoClient->retrievePaymentIntent($intentId, $storeId);
        $piStatus = $pi['data']['attributes']['status'] ?? null;

        return [
            'confirmed' => in_array($piStatus, self::CONFIRMED_INTENT_STATUSES, true),
            'authorized' => $piStatus === 'awaiting_capture',
            'status' => $piStatus,
            'payment_intent_id' => $intentId,
            'payment_id' => $this->findPaidPaymentId($pi['data']['attributes']['payments'] ?? []),
            'pi_status' => $piStatus,
        ];
    }

    /**
     * Whether a checkout session's state shows the customer has paid, or that their card is
     * held for capture.
     *
     * @param string|null $sessionStatus Checkout session status ("active", "paid", "expired")
     * @param string|null $paymentId A paid payment behind the session, if any
     * @param string|null $piStatus Status of the session's payment intent, if resolved
     * @return bool
     */
    public static function isCheckoutSessionPaid(?string $sessionStatus, ?string $paymentId, ?string $piStatus): bool
    {
        return $sessionStatus === 'paid'
            || $paymentId !== null
            || in_array($piStatus, self::CONFIRMED_INTENT_STATUSES, true);
    }

    /**
     * The ID of the first paid payment in a PayMongo payments list.
     *
     * A payment whose status is not given is accepted, so a response that leaves it out
     * keeps working as it did before. One that is explicitly failed or pending never counts.
     *
     * @param array $payments Payment resources from a checkout session or payment intent
     * @return string|null
     */
    public function findPaidPaymentId(array $payments): ?string
    {
        foreach ($payments as $payment) {
            $id = $payment['id'] ?? null;
            $status = $payment['attributes']['status'] ?? null;

            if ($id && ($status === null || $status === 'paid')) {
                return (string) $id;
            }
        }

        return null;
    }
}
