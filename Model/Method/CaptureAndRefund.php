<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

use PayMongo\Payment\Gateway\PaymongoApiException;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * Captures and refunds a PayMongo payment, for every PayMongo payment method.
 *
 * The individual methods (AbstractPaymongoMethod) and the legacy all-in-one method
 * (PaymongoPayment) each had their own copy of this code, and the copies drifted: before
 * 2.0.1 the all-in-one method wrote no audit entries at all. Both now call this class, so a
 * fix applies to every method (PYM-33).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CaptureAndRefund
{
    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var AuditLogger */
    private AuditLogger $auditLogger;

    /** @var PaymentReference */
    private PaymentReference $paymentReference;

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /**
     * @param PaymongoClient $paymongoClient
     * @param AuditLogger $auditLogger
     * @param PaymentReference $paymentReference
     * @param PaymentCapture $paymentCapture
     */
    public function __construct(
        PaymongoClient $paymongoClient,
        AuditLogger $auditLogger,
        PaymentReference $paymentReference,
        PaymentCapture $paymentCapture
    ) {
        $this->paymongoClient = $paymongoClient;
        $this->auditLogger = $auditLogger;
        $this->paymentReference = $paymentReference;
        $this->paymentCapture = $paymentCapture;
    }

    /**
     * Capture an invoice online: record its transaction, taking a card hold's money first.
     *
     * The transaction ID is the PayMongo payment (or, failing that, its payment intent), so
     * Magento links the capture to the invoice and a credit memo can refund it online. For an
     * Authorize Only order whose card hold has not been captured yet, PayMongo first takes the
     * money (PaymentCapture::captureOnInvoice()), and the invoice is stopped if it does not.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException when PayMongo does not capture the hold
     */
    public function capture(InfoInterface $payment, float $amount): void
    {
        $order = $payment instanceof Payment ? $payment->getOrder() : null;
        if ($order) {
            $this->paymentCapture->captureOnInvoice($order, $amount);
        }

        $txnId = $payment->getAdditionalInformation('paymongo_payment_id')
            ?: $payment->getAdditionalInformation('paymongo_payment_intent_id');

        if ($txnId) {
            $payment->setTransactionId($txnId);
            $payment->setIsTransactionClosed(true);
        }

        // A card hold's capture is audited by PaymentCapture when PayMongo takes the money, so
        // only a payment made at checkout is audited here, as its invoice is created.
        if ($txnId && ($order === null || $this->paymentCapture->getResult($order) === null)) {
            $this->auditLogger->logCapture(
                $order ? (string) $order->getIncrementId() : 'unknown',
                $amount,
                (string) ($payment->getAdditionalInformation('paymongo_payment_intent_id') ?: $txnId),
                $payment->getAdditionalInformation('paymongo_payment_id') ?: null
            );
        }
    }

    /**
     * Refund a payment through the PayMongo API (online refund through a credit memo).
     *
     * The refund goes to the order's paid PayMongo payment (PaymentCapture::findPaymentId()),
     * and PayMongo's refund ID (ref_…) names the refund transaction and credit memo. A refund
     * PayMongo refuses is explained in PayMongo's words; Magento then saves nothing.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException when there is no payment to refund, the amount is too small,
     *         or PayMongo refuses the refund
     */
    public function refund(InfoInterface $payment, float $amount): void
    {
        if (!$payment instanceof Payment) {
            return;
        }

        $order = $payment->getOrder();
        $paymentId = $order
            ? $this->paymentCapture->findPaymentId($order)
            : $payment->getAdditionalInformation('paymongo_payment_id');

        if (!$paymentId) {
            throw new LocalizedException(__('Cannot refund: PayMongo payment ID not found for this order.'));
        }

        $amountCentavos = (int) round($amount * 100);
        if ($amountCentavos < 100) {
            throw new LocalizedException(__('Refund amount must be at least ₱1.00.'));
        }

        $notes = $this->paymentReference->buildRefundNotes($order);

        try {
            $response = $this->paymongoClient->createRefund(
                $paymentId,
                $amountCentavos,
                'requested_by_customer',
                $notes,
                $order ? (int) $order->getStoreId() : null
            );
        } catch (PaymongoApiException $e) {
            throw $this->refundRefused($e);
        }

        // PayMongo's refund ID (ref_…) names the refund transaction and the credit memo. Left to
        // Magento, every refund of an invoice was named "<capture ID>-refund", so a second partial
        // refund reused the first one's ID, and the PayMongo refund was recorded nowhere.
        $refundId = $response['data']['id'] ?? null;
        if ($refundId) {
            $payment->setTransactionId($refundId);
            $payment->setIsTransactionClosed(true);
        }

        $this->auditLogger->logRefund(
            $order ? (string) $order->getIncrementId() : 'unknown',
            $amount,
            $paymentId,
            $notes
        );
    }

    /**
     * The admin's message for a refund PayMongo refused, in PayMongo's words, not its raw response.
     *
     * Magento shows it on the credit memo page and saves nothing, so the admin can retry. A
     * refund larger than the account's available payout gets what to do about it: PayMongo
     * keeps its fee on every payment, so on a new account the payout can be lower than the
     * refund (PYM-31).
     *
     * @param PaymongoApiException $e
     * @return LocalizedException
     */
    private function refundRefused(PaymongoApiException $e): LocalizedException
    {
        $detail = rtrim((string) ($e->getDetail() ?? __('PayMongo gave no reason')), '. ');
        $message = __(
            'PayMongo did not refund this payment: %1. Nothing was refunded and no credit memo was created.',
            $detail
        );
        if (stripos($detail, 'payout') !== false) {
            $message = __(
                '%1 Your PayMongo account\'s available payout is lower than this refund, because PayMongo keeps '
                . 'its fee on every payment. Top up your PayMongo balance, or wait for more payments to come in, '
                . 'then try the refund again.',
                $message
            );
        }

        return new LocalizedException($message, $e);
    }
}
