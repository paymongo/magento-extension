<?php

declare(strict_types=1);

namespace PayMongo\Payment\Plugin\Sales;

use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\PayOperation;

/**
 * Refuses an invoice for only part of an Authorize Only PayMongo order while its card hold
 * can still be captured (PYM-28). See PaymentCapture::assertFullInvoice().
 *
 * Before-plugins on the two places Magento registers a new invoice: Invoice::register()
 * (the admin, and custom code using InvoiceService) and PayOperation::execute() (the REST
 * invoice API, POST /V1/order/{id}/invoice). Both run once the invoice's totals are
 * collected but before it changes the order's totals or captures the payment, so a refused
 * invoice leaves the order as it was, even if the caller catches the error and saves it.
 *
 * Capture Online is checked again by the payment method, before PayMongo is asked
 * (PaymentCapture::captureOnInvoice()), which also covers capturing an invoice created
 * earlier with "Not Capture".
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class RejectPartialInvoicePlugin
{
    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /**
     * @param PaymentCapture $paymentCapture
     */
    public function __construct(PaymentCapture $paymentCapture)
    {
        $this->paymentCapture = $paymentCapture;
    }

    /**
     * Before an invoice is registered by the admin or custom code.
     *
     * @param Invoice $subject
     * @return null
     * @throws LocalizedException when the invoice is for only part of the order
     */
    public function beforeRegister(Invoice $subject)
    {
        $this->check($subject->getOrder(), $subject);

        return null;
    }

    /**
     * Before an invoice is registered by the REST invoice API.
     *
     * @param PayOperation $subject
     * @param OrderInterface $order
     * @param InvoiceInterface $invoice
     * @param bool $capture
     * @return null
     * @throws LocalizedException when the invoice is for only part of the order
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeExecute(PayOperation $subject, OrderInterface $order, InvoiceInterface $invoice, $capture)
    {
        $this->check($order, $invoice);

        return null;
    }

    /**
     * Refuse the invoice if it is for only part of a PayMongo order on a card hold.
     *
     * @param OrderInterface|null $order
     * @param InvoiceInterface $invoice
     * @return void
     * @throws LocalizedException
     */
    private function check(?OrderInterface $order, InvoiceInterface $invoice): void
    {
        if ($order instanceof Order) {
            $this->paymentCapture->assertFullInvoice($order, (float) $invoice->getBaseGrandTotal());
        }
    }
}
