<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Reconciliation;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Reconciliation service — cross-references PayMongo payments/refunds with Magento orders.
 *
 * The service fetches all payments and refunds from the PayMongo API for a given date
 * window, then matches each entry against a Magento order strictly by PayMongo payment
 * ID (see findOrderForPayment()) — never by guessing from the order increment ID.
 * Discrepancies are returned so the admin user can investigate and act.
 *
 * Note on shared PayMongo accounts: PayMongo issues one test key pair and one live key
 * pair per account, so it's common to share the same keys across several Magento
 * installations (local, a shared dev server, staging, etc). If you do, the account-wide
 * payments/refunds list this service fetches will include every installation's activity
 * mixed together for a given date range — there's no per-installation filtering. That's
 * fine for correctness (payment-ID matching can't produce a false positive regardless of
 * which installation a transaction came from), just be aware the Payments/Refunds tables
 * and discrepancy checks aren't scoped to "this installation only" if keys are shared.
 *
 * Discrepancy types:
 *   - MISSING_IN_MAGENTO  — PayMongo shows a payment but no matching Magento order exists
 *   - AMOUNT_MISMATCH     — The captured amount differs from the Magento order grand total
 *   - STATUS_MISMATCH     — PayMongo status is 'paid' but the Magento order is still 'pending_payment'
 *   - INVOICE_MISSING     — PayMongo status is 'paid' and the order is 'processing', but no Magento
 *                           invoice exists. Not raised for card orders held under the
 *                           authorize-now-capture-later flow (Payment Action = Authorize Only) —
 *                           those are expected to have no invoice until the order ships; see
 *                           isAuthorizeOnlyHeld().
 *   - REFUND_NOT_IN_ORDER — PayMongo has a refund entry but either no Magento order was found
 *                           for its payment at all, or the order exists but its creditmemo is absent
 *   - NOT_PAID_IN_PAYMONGO — The reverse direction: a PayMongo order is Processing or Complete in
 *                           Magento, but PayMongo has no payment for it. Every check above starts
 *                           from PayMongo's payment list, so on its own it can never see an order
 *                           Magento marked paid without a payment — which is what the admin
 *                           Refresh button did to unpaid checkouts before PYM-27. See
 *                           checkOrdersPaidInMagento().
 *   - CHECK_INCOMPLETE    — checkOrdersPaidInMagento() stopped after MAX_PAYMENT_LOOKUPS orders,
 *                           so the rest of the window was not checked for NOT_PAID_IN_PAYMONGO.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Service
{
    /** Amount difference tolerance in centavos before flagging an AMOUNT_MISMATCH (5 = ₱0.05). */
    private const AMOUNT_TOLERANCE_CENTAVOS = 5;

    /**
     * Most orders checkOrdersPaidInMagento() asks PayMongo about in one run.
     *
     * The whole reconciliation runs in one admin request (the AJAX fetch, or the CSV
     * export), so an unbounded number of PayMongo calls could hit PHP or nginx timeouts
     * or PayMongo's rate limits on a wide date range. Each lookup is one call when the
     * order has a stored payment intent, two when only its checkout session is known.
     */
    private const MAX_PAYMENT_LOOKUPS = 200;

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var OrderCollectionFactory */
    private OrderCollectionFactory $orderCollectionFactory;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param PaymongoClient $paymongoClient
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param CaptureMode $captureMode
     * @param PaymentConfirmation $paymentConfirmation
     * @param Logger $logger
     */
    public function __construct(
        PaymongoClient $paymongoClient,
        OrderCollectionFactory $orderCollectionFactory,
        CaptureMode $captureMode,
        PaymentConfirmation $paymentConfirmation,
        Logger $logger
    ) {
        $this->paymongoClient = $paymongoClient;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->captureMode = $captureMode;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->logger = $logger;
    }

    /**
     * Run the reconciliation for the given date range.
     *
     * Fetches PayMongo payments and refunds, then matches them against Magento orders.
     * Returns a structured array suitable for rendering in the admin grid.
     *
     * @param \DateTime $from         Inclusive start of the date window (PHP DateTime)
     * @param \DateTime $to           Inclusive end of the date window (PHP DateTime)
     * @param int|null  $storeId      Limit to a specific store; null = default store's keys for the
     *                                PayMongo lists, and every store for the NOT_PAID_IN_PAYMONGO
     *                                check, which asks about each order with its own store's keys
     * @return array{payments: array, refunds: array, discrepancies: array}
     * @throws LocalizedException
     */
    public function reconcile(\DateTime $from, \DateTime $to, ?int $storeId = null): array
    {
        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp();

        $paymentsResponse = $this->paymongoClient->getPayments($fromTs, $toTs, $storeId);
        $refundsResponse  = $this->paymongoClient->getRefunds($fromTs, $toTs, $storeId);

        $payments = $paymentsResponse['data'] ?? [];
        $refunds  = $refundsResponse['data'] ?? [];

        $discrepancies = array_merge(
            $this->checkPayments($payments),
            $this->checkRefunds($refunds),
            $this->checkOrdersPaidInMagento($from, $to, $payments, $storeId)
        );

        return [
            'payments'      => $this->normalizePayments($payments),
            'refunds'       => $this->normalizeRefunds($refunds),
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Normalize raw PayMongo payment objects for display.
     *
     * @param array $payments
     * @return array
     */
    private function normalizePayments(array $payments): array
    {
        $rows = [];
        foreach ($payments as $p) {
            $attr  = $p['attributes'] ?? [];
            $rows[] = [
                'id'           => $p['id'] ?? '',
                'status'       => $attr['status'] ?? '',
                'amount'       => ($attr['amount'] ?? 0) / 100,
                'currency'     => $attr['currency'] ?? 'PHP',
                'description'  => $attr['description'] ?? '',
                'method'       => $attr['source']['type'] ?? '',
                'created_at'   => isset($attr['created_at'])
                    ? date('Y-m-d H:i:s', $attr['created_at'])
                    : '',
            ];
        }
        return $rows;
    }

    /**
     * Normalize raw PayMongo refund objects for display.
     *
     * @param array $refunds
     * @return array
     */
    private function normalizeRefunds(array $refunds): array
    {
        $rows = [];
        foreach ($refunds as $r) {
            $attr  = $r['attributes'] ?? [];
            $rows[] = [
                'id'         => $r['id'] ?? '',
                'status'     => $attr['status'] ?? '',
                'amount'     => ($attr['amount'] ?? 0) / 100,
                'payment_id' => $attr['payment_id'] ?? '',
                'reason'     => $attr['reason'] ?? '',
                'created_at' => isset($attr['created_at'])
                    ? date('Y-m-d H:i:s', $attr['created_at'])
                    : '',
            ];
        }
        return $rows;
    }

    /**
     * Cross-check PayMongo payments against Magento orders and surface discrepancies.
     *
     * @param array $payments
     * @return array
     */
    private function checkPayments(array $payments): array
    {
        $issues = [];

        foreach ($payments as $p) {
            $attr      = $p['attributes'] ?? [];
            $paymentId = $p['id'] ?? '';
            $status    = $attr['status'] ?? '';
            $amount    = (int) ($attr['amount'] ?? 0);

            $order = $this->findOrderForPayment($paymentId);

            if (!$order) {
                $issues[] = [
                    'type'       => 'MISSING_IN_MAGENTO',
                    'payment_id' => $paymentId,
                    'amount'     => $amount / 100,
                    'status'     => $status,
                    'message'    => 'No Magento order found for this PayMongo payment.',
                ];
                continue;
            }

            $magentoAmountCentavos = (int) round($order->getGrandTotal() * 100);
            if (abs($magentoAmountCentavos - $amount) > self::AMOUNT_TOLERANCE_CENTAVOS) {
                $issues[] = [
                    'type'            => 'AMOUNT_MISMATCH',
                    'payment_id'      => $paymentId,
                    'order_increment' => $order->getIncrementId(),
                    'paymongo_amount' => $amount / 100,
                    'magento_amount'  => $order->getGrandTotal(),
                    'message'         => 'Captured amount differs from Magento order grand total.',
                ];
            }

            if ($status === 'paid' && $order->getState() === 'pending_payment') {
                $issues[] = [
                    'type'            => 'STATUS_MISMATCH',
                    'payment_id'      => $paymentId,
                    'order_increment' => $order->getIncrementId(),
                    'paymongo_status' => $status,
                    'magento_state'   => $order->getState(),
                    'message'         => 'PayMongo shows paid but Magento order is still pending_payment.',
                ];
            }

            if ($status === 'paid'
                && $order->getState() === 'processing'
                && !$this->isAuthorizeOnlyHeld($order)
            ) {
                $invoices = $order->getInvoiceCollection();
                if (!$invoices || $invoices->getSize() === 0) {
                    $issues[] = [
                        'type'            => 'INVOICE_MISSING',
                        'payment_id'      => $paymentId,
                        'order_increment' => $order->getIncrementId(),
                        'amount'          => $amount / 100,
                        'message'         => 'PayMongo shows paid and the order is Processing, but no Magento invoice was found.',
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Whether this order is a card payment intentionally held under the
     * authorize-now-capture-later flow (Payment Action = Authorize Only).
     *
     * Such orders are moved to Processing without an invoice on purpose — the invoice
     * is created later by Observer\CaptureOnShipment when the merchant ships the order.
     * checkPayments() must not flag these as INVOICE_MISSING. Model\Order\CaptureMode
     * makes this decision for every part of the module, so reconciliation agrees with
     * Success, Webhook and CaptureOnShipment about which orders are held.
     *
     * @param Order $order
     * @return bool
     */
    private function isAuthorizeOnlyHeld(Order $order): bool
    {
        return $this->captureMode->isAuthorizeOnly($order);
    }

    /**
     * Cross-check PayMongo refunds against Magento creditmemos.
     *
     * @param array $refunds
     * @return array
     */
    private function checkRefunds(array $refunds): array
    {
        $issues = [];

        foreach ($refunds as $r) {
            $attr      = $r['attributes'] ?? [];
            $refundId  = $r['id'] ?? '';
            $paymentId = $attr['payment_id'] ?? '';
            $amount    = (int) ($attr['amount'] ?? 0);
            $status    = $attr['status'] ?? '';

            if ($status !== 'succeeded') {
                continue;
            }

            $order = $this->findOrderForPayment($paymentId);
            if (!$order) {
                $issues[] = [
                    'type'       => 'REFUND_NOT_IN_ORDER',
                    'refund_id'  => $refundId,
                    'payment_id' => $paymentId,
                    'amount'     => $amount / 100,
                    'message'    => 'PayMongo refund exists but no Magento order was found for its payment.',
                ];
                continue;
            }

            $creditmemos = $order->getCreditmemosCollection();
            if (!$creditmemos || $creditmemos->getSize() === 0) {
                $issues[] = [
                    'type'            => 'REFUND_NOT_IN_ORDER',
                    'refund_id'       => $refundId,
                    'payment_id'      => $paymentId,
                    'order_increment' => $order->getIncrementId(),
                    'amount'          => $amount / 100,
                    'message'         => 'PayMongo refund exists but no Magento creditmemo found.',
                ];
            }
        }

        return $issues;
    }

    /**
     * Flag PayMongo orders that Magento treats as paid but PayMongo has no payment for.
     *
     * Looks at PayMongo orders created in the date window that are Processing or Complete.
     * An order whose stored payment ID (pay_…) is among the paid payments already fetched
     * for this window is fine and costs no API call. Any other order is checked directly
     * with PaymentConfirmation, the same check the success page and the Refresh button use.
     * That covers a payment made just outside the window, a card held under Authorize Only
     * (no payment exists until capture, but its payment intent is awaiting capture), and an
     * order from a store whose keys the payment list was not fetched with.
     *
     * The direct check asks about the order's payment intent when one is stored (one call),
     * and falls back to its checkout session (two calls) only when it is not. After
     * MAX_PAYMENT_LOOKUPS orders it stops and adds a CHECK_INCOMPLETE row instead, so a
     * wide window cannot time out the request. An order PayMongo cannot be asked about is
     * logged and skipped rather than flagged.
     *
     * Covers every store when $storeId is null: each order is checked with its own store's
     * keys, so an order from another store is checked correctly too.
     *
     * @param \DateTime $from
     * @param \DateTime $to
     * @param array $payments PayMongo payments fetched for the same window
     * @param int|null $storeId Limit to this store's orders; null = every store
     * @return array
     */
    private function checkOrdersPaidInMagento(\DateTime $from, \DateTime $to, array $payments, ?int $storeId): array
    {
        $paidPaymentIds = [];
        foreach ($payments as $p) {
            if (($p['attributes']['status'] ?? '') === 'paid' && !empty($p['id'])) {
                $paidPaymentIds[$p['id']] = true;
            }
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()
            ->join(
                ['sop' => $collection->getResource()->getTable('sales_order_payment')],
                'main_table.entity_id = sop.parent_id',
                ['paymongo_additional_information' => 'sop.additional_information']
            )
            ->where('sop.method LIKE ?', 'paymongo%')
            ->where('main_table.state IN (?)', [Order::STATE_PROCESSING, Order::STATE_COMPLETE])
            ->where('main_table.created_at >= ?', $from->format('Y-m-d H:i:s'))
            ->where('main_table.created_at <= ?', $to->format('Y-m-d H:i:s'));

        if ($storeId !== null) {
            $collection->getSelect()->where('main_table.store_id = ?', $storeId);
        }

        $issues = [];
        $lookups = 0;
        $notChecked = 0;
        foreach ($collection as $order) {
            $info = json_decode((string) $order->getData('paymongo_additional_information'), true) ?: [];
            $paymentId = $info['paymongo_payment_id'] ?? null;
            $sessionId = $info['paymongo_checkout_session_id'] ?? null;
            $intentId = $info['paymongo_payment_intent_id'] ?? null;

            if ($paymentId && isset($paidPaymentIds[$paymentId])) {
                continue;
            }

            $status = null;
            if ($sessionId || $intentId) {
                if ($lookups >= self::MAX_PAYMENT_LOOKUPS) {
                    $notChecked++;
                    continue;
                }
                $lookups++;

                try {
                    $result = $intentId
                        ? $this->paymentConfirmation->checkPaymentIntent((string) $intentId, (int) $order->getStoreId())
                        : $this->paymentConfirmation->checkCheckoutSession((string) $sessionId, (int) $order->getStoreId());
                } catch (\Exception $e) {
                    $this->logger->warning('Reconciliation: could not check order payment on PayMongo', [
                        'order_id' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($result['confirmed']) {
                    continue;
                }
                $status = $result['status'] ?? 'unknown';
            }

            $issues[] = [
                'type'            => 'NOT_PAID_IN_PAYMONGO',
                'payment_id'      => $paymentId ?: ($intentId ?: ($sessionId ?: '')),
                'order_increment' => $order->getIncrementId(),
                'magento_amount'  => (float) $order->getGrandTotal(),
                'message'         => $status !== null
                    ? sprintf(
                        'Order is %s in Magento, but PayMongo has no payment for it (status: %s).',
                        $order->getState(),
                        $status
                    )
                    : sprintf(
                        'Order is %s in Magento, but has no PayMongo checkout to check a payment against.',
                        $order->getState()
                    ),
            ];
        }

        if ($notChecked > 0) {
            $this->logger->warning('Reconciliation: payment lookup limit reached', [
                'limit' => self::MAX_PAYMENT_LOOKUPS,
                'not_checked' => $notChecked,
            ]);
            $issues[] = [
                'type'       => 'CHECK_INCOMPLETE',
                'payment_id' => '',
                'message'    => sprintf(
                    'Checked %d orders with PayMongo for Not Paid in PayMongo; %d more were not checked. '
                    . 'Choose a shorter date range to check them.',
                    self::MAX_PAYMENT_LOOKUPS,
                    $notChecked
                ),
            ];
        }

        return $issues;
    }

    /**
     * Find a Magento order for a PayMongo payment.
     *
     * Matches by PayMongo payment ID only, via a LIKE search on
     * sales_order_payment.additional_information. Payment IDs are globally unique —
     * assigned by PayMongo itself, never reused, never sequential — so this is always
     * a correct match with no possibility of a false positive.
     *
     * This intentionally does not fall back to guessing by order increment ID parsed
     * from the payment description. Increment IDs restart at 000000001 in every fresh
     * Magento install, so a text-based fallback could match the wrong order whenever
     * more than one installation shares the same PayMongo account (see the class
     * docblock). Controller/Payment/Success.php and Controller/Payment/Webhook.php
     * both reliably persist the payment ID before/at the point an order is marked
     * paid, so this should find every order that has actually been paid.
     *
     * @param string $paymentId PayMongo payment ID (pay_...)
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrderForPayment(string $paymentId): ?\Magento\Sales\Model\Order
    {
        if ($paymentId === '') {
            return null;
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->joinLeft(
            ['sop' => $collection->getResource()->getTable('sales_order_payment')],
            'main_table.entity_id = sop.parent_id',
            []
        )->where('sop.additional_information LIKE ?', '%' . $paymentId . '%');
        $collection->setPageSize(1);

        $order = $collection->getFirstItem();
        return $order && $order->getId() ? $order : null;
    }
}
