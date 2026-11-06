<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Plugin\Sales;

use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Plugin\Sales\RejectPartialInvoicePlugin;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\PayOperation;
use PHPUnit\Framework\TestCase;

/**
 * Covers the check that refuses an invoice for only part of an Authorize Only order before
 * it is registered, by the admin or custom code and by the REST API (PYM-28). Which invoices
 * are refused is covered by PaymentCaptureTest::testAssertFullInvoice().
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class RejectPartialInvoicePluginTest extends TestCase
{
    /**
     * Invoice::register() checks the invoice's base grand total against its order.
     *
     * @return void
     */
    public function testChecksAnInvoiceBeforeItIsRegistered(): void
    {
        $order = $this->createStub(Order::class);

        $paymentCapture = $this->createMock(PaymentCapture::class);
        $paymentCapture->expects($this->once())->method('assertFullInvoice')->with($order, 500.0);

        $result = (new RejectPartialInvoicePlugin($paymentCapture))->beforeRegister($this->invoice($order, 500.0));

        $this->assertNull($result);
    }

    /**
     * The REST invoice API checks the same, against the order it was given.
     *
     * @return void
     */
    public function testChecksAnInvoiceFromTheRestApi(): void
    {
        $order = $this->createStub(Order::class);

        $paymentCapture = $this->createMock(PaymentCapture::class);
        $paymentCapture->expects($this->once())->method('assertFullInvoice')->with($order, 500.0);

        $result = (new RejectPartialInvoicePlugin($paymentCapture))->beforeExecute(
            $this->createStub(PayOperation::class),
            $order,
            $this->invoice($order, 500.0),
            false
        );

        $this->assertNull($result);
    }

    /**
     * A refused invoice throws before Magento registers it.
     *
     * @return void
     */
    public function testARefusedInvoiceIsNotRegistered(): void
    {
        $paymentCapture = $this->createStub(PaymentCapture::class);
        $paymentCapture->method('assertFullInvoice')
            ->willThrowException(new LocalizedException(__('Invoice the whole order at once')));

        $this->expectException(LocalizedException::class);

        (new RejectPartialInvoicePlugin($paymentCapture))
            ->beforeRegister($this->invoice($this->createStub(Order::class), 500.0));
    }

    /**
     * @param Order $order
     * @param float $baseGrandTotal
     * @return Invoice
     */
    private function invoice(Order $order, float $baseGrandTotal): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getBaseGrandTotal')->willReturn($baseGrandTotal);
        $invoice->method('getOrder')->willReturn($order);

        return $invoice;
    }
}
