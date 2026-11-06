<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Observer;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\AdminNotice;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Observer\CaptureOnShipment;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers capture on shipment for Authorize Only orders, where PYM-28's bugs were: a failed
 * capture was only logged, so the order looked shipped and normal with the customer never
 * charged, and a resource_invalid_state error was taken to mean "already captured", so an
 * expired or cancelled authorisation was invoiced as paid with no money taken.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CaptureOnShipmentTest extends TestCase
{
    private const INTENT_ID = 'pi_28';
    private const PAYMENT_ID = 'pay_31';

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var InvoiceService&MockObject */
    private InvoiceService $invoiceService;

    /** @var AuditLogger&MockObject */
    private AuditLogger $auditLogger;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var Order|null The fresh copy PaymentCapture loads to record a failed invoice */
    private ?Order $freshOrder = null;

    /** @var AdminNotice&MockObject */
    private AdminNotice $adminNotice;

    /** @var Order&MockObject */
    private Order $order;

    /** @var array<string, mixed> The order payment's additional information */
    private array $additionalInformation = ['paymongo_payment_intent_id' => self::INTENT_ID];

    /** @var CaptureOnShipment */
    private CaptureOnShipment $observer;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        // Only the API lookup is replaced: picking the paid payment out of PayMongo's response is real.
        $this->paymentConfirmation = $this->createPartialMock(PaymentConfirmation::class, ['checkPaymentIntent']);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturnCallback(fn () => $this->freshOrder ?? $this->databaseCopy([]));
        $this->adminNotice = $this->createMock(AdminNotice::class);

        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($this->payment($this->additionalInformation));
        $this->order->method('getId')->willReturn(28);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getIncrementId')->willReturn('000000028');
        $this->order->method('getGrandTotal')->willReturn(1500.00);

        $captureMode = $this->createStub(CaptureMode::class);
        $captureMode->method('isAuthorizeOnly')->willReturn(true);

        $transaction = $this->createStub(Transaction::class);
        $transaction->method('addObject')->willReturnSelf();
        $transactionFactory = $this->createStub(TransactionFactory::class);
        $transactionFactory->method('create')->willReturn($transaction);

        $paymentCapture = new PaymentCapture(
            $this->paymongoClient,
            $this->paymentConfirmation,
            $captureMode,
            $this->invoiceService,
            $transactionFactory,
            $orderFactory,
            $this->lockManager,
            $this->auditLogger,
            $this->createStub(Logger::class)
        );

        $this->observer = new CaptureOnShipment(
            $paymentCapture,
            $captureMode,
            $this->adminNotice,
            $this->createStub(Logger::class)
        );
    }

    /**
     * A successful capture invoices the order, does not hold it, and records the capture,
     * with the PayMongo payment the invoice's refund goes against (PYM-31).
     *
     * @return void
     */
    public function testCapturedPaymentIsInvoiced(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->with(self::INTENT_ID, 150000, 1)
            ->willReturn(['data' => ['attributes' => [
                'status'   => 'succeeded',
                'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
            ]]]);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->order->method('canInvoice')->willReturn(true);

        $this->expectInvoice();
        $this->auditLogger->expects($this->once())->method('logCapture');
        $this->order->expects($this->never())->method('hold');
        $this->adminNotice->expects($this->never())->method('error');
        $this->expectComment('Payment captured via PayMongo on shipment creation');

        $this->observer->execute($this->shipmentObserver());

        $this->assertCaptureResult(PaymentCapture::RESULT_CAPTURED, 'succeeded');
        $this->assertSame(self::PAYMENT_ID, $this->additionalInformation['paymongo_payment_id'] ?? null);
    }

    /**
     * The reported bug: a failed capture saves the shipment without an invoice, holds the
     * order, records that the customer was not charged, and warns the admin.
     *
     * @return void
     */
    public function testFailedCaptureHoldsTheOrderAndWarnsTheAdmin(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: card_declined')));
        $this->givenIntentStatus('awaiting_capture');
        $this->order->method('canHold')->willReturn(true);

        $this->expectCaptureFailure('NOT been charged', 'awaiting_capture');
        $this->order->expects($this->once())->method('hold');
        $this->order->expects($this->once())->method('save');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'has not been charged')
                && str_contains((string) $message, 'put on hold'))
        );

        $this->observer->execute($this->shipmentObserver());

        $this->assertCaptureResult(PaymentCapture::RESULT_NOT_CAPTURED, 'awaiting_capture');
    }

    /**
     * An authorisation that has expired or been cancelled is no longer read as "already
     * captured" from a resource_invalid_state error, so it is never invoiced as paid.
     *
     * @return void
     */
    public function testInvalidStateOnAnUncapturedIntentIsNotInvoiced(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(
                __('PayMongo API error: {"errors":[{"code":"resource_invalid_state"}]}')
            ));
        $this->givenIntentStatus('cancelled');
        $this->order->method('canHold')->willReturn(true);

        $this->expectCaptureFailure('NOT been charged', 'cancelled');
        $this->order->expects($this->once())->method('hold');
        $this->adminNotice->expects($this->once())->method('error');

        $this->observer->execute($this->shipmentObserver());

        $this->assertCaptureResult(PaymentCapture::RESULT_NOT_CAPTURED, 'cancelled');
    }

    /**
     * An intent PayMongo had already captured (test mode, or captured in the Dashboard) is
     * still invoiced, without a second charge.
     *
     * @return void
     */
    public function testIntentAlreadyCapturedIsInvoiced(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(
                __('PayMongo API error: {"errors":[{"code":"resource_invalid_state"}]}')
            ));
        $this->givenIntentStatus('succeeded', self::PAYMENT_ID);
        $this->order->method('canInvoice')->willReturn(true);

        $this->expectInvoice();
        $this->auditLogger->expects($this->once())->method('logCapture');
        $this->order->expects($this->never())->method('hold');
        $this->adminNotice->expects($this->never())->method('error');
        $this->expectComment('had already captured this payment');

        $this->observer->execute($this->shipmentObserver());

        $this->assertCaptureResult(PaymentCapture::RESULT_CAPTURED, 'succeeded');
        $this->assertSame(self::PAYMENT_ID, $this->additionalInformation['paymongo_payment_id'] ?? null);
    }

    /**
     * When PayMongo cannot be asked, or the payment is still processing, the order is not
     * invoiced, and it is not claimed that the customer was not charged either: the money
     * may have been taken, so the merchant is told to check before collecting it again.
     *
     * @param string|null $status
     * @param string $statusLabel
     * @return void
     */
    #[DataProvider('unconfirmedStatusProvider')]
    public function testUnconfirmedCaptureIsNotClaimedAsUncharged(?string $status, string $statusLabel): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));
        if ($status === null) {
            $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
                ->willThrowException(new LocalizedException(__('Connection timed out')));
        } else {
            $this->givenIntentStatus($status);
        }
        $this->order->method('canHold')->willReturn(true);

        $this->expectCaptureFailure('could not be confirmed whether the customer was charged', $statusLabel);
        $this->order->expects($this->once())->method('hold');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'could not be confirmed')
                && !str_contains((string) $message, 'has not been charged'))
        );

        $this->observer->execute($this->shipmentObserver());

        $this->assertCaptureResult(PaymentCapture::RESULT_UNCONFIRMED, $status);
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function unconfirmedStatusProvider(): array
    {
        return [
            'PayMongo unreachable' => [null, 'unknown'],
            'still processing'     => ['processing', 'processing'],
        ];
    }

    /**
     * An order that cannot be held (still Pending Payment, say) is still commented on, and
     * the admin is still warned.
     *
     * @return void
     */
    public function testFailedCaptureOnAnOrderThatCannotBeHeldIsStillReported(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: card_declined')));
        $this->givenIntentStatus('awaiting_capture');
        $this->order->method('canHold')->willReturn(false);

        $this->expectCaptureFailure('NOT been charged', 'awaiting_capture');
        $this->order->expects($this->never())->method('hold');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => !str_contains((string) $message, 'put on hold'))
        );

        $this->observer->execute($this->shipmentObserver());
    }

    /**
     * A later shipment of an order whose capture already ran — a partial shipment, or a
     * shipment re-saved to add tracking — leaves the payment alone.
     *
     * @param string $result
     * @return void
     */
    #[DataProvider('captureResultProvider')]
    public function testLaterShipmentDoesNotCaptureAgain(string $result): void
    {
        $this->additionalInformation[PaymentCapture::RESULT_KEY] = $result;
        $this->expectNothingCaptured();

        $this->observer->execute($this->shipmentObserver());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function captureResultProvider(): array
    {
        return [
            'captured'              => [PaymentCapture::RESULT_CAPTURED],
            'not captured'          => [PaymentCapture::RESULT_NOT_CAPTURED],
            'unconfirmed'           => [PaymentCapture::RESULT_UNCONFIRMED],
            'captured not invoiced' => [PaymentCapture::RESULT_CAPTURED_NOT_INVOICED],
        ];
    }

    /**
     * An order invoiced before it shipped is not captured: Capture Online already captured
     * the hold, and Capture Offline means the payment was collected another way, so a
     * capture now would charge the customer twice.
     *
     * @return void
     */
    public function testInvoicedOrderIsNotCaptured(): void
    {
        $this->order->method('getBaseTotalInvoiced')->willReturn(1500.00);
        $this->expectNothingCaptured();

        $this->observer->execute($this->shipmentObserver());
    }

    /**
     * While the webhook or Refresh holds the order's lock, the capture is skipped. That is
     * recorded on the order itself — a comment and a hold — not only in an admin message,
     * which nobody sees when the shipment came through the API.
     *
     * @return void
     */
    public function testLockedOrderIsNotCaptured(): void
    {
        $this->lockManager->expects($this->once())->method('lock')
            ->with('paymongo_order_000000028', 30)
            ->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->auditLogger->expects($this->never())->method('logCapture');
        $this->order->method('canHold')->willReturn(true);
        $this->expectComment('NOT been charged');
        $this->order->expects($this->once())->method('hold');
        $this->order->expects($this->once())->method('save');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'Capture from PayMongo'))
        );

        $this->observer->execute($this->shipmentObserver());
    }

    /**
     * A captured payment whose invoice fails is not swallowed: the in-memory order, whose
     * totals the failed invoice changed, is not saved; a fresh copy records that the
     * customer HAS been charged, goes on hold, and the admin is pointed at the button. It
     * keeps the PayMongo payment, so the invoice the button creates can be refunded online.
     *
     * @return void
     */
    public function testInvoiceFailureAfterCaptureIsRecordedOnAFreshCopy(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn(['data' => ['attributes' => [
                'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
            ]]]);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->order->method('canInvoice')->willReturn(true);
        $this->invoiceService->expects($this->once())->method('prepareInvoice')
            ->willThrowException(new LocalizedException(__('The invoice cannot be created.')));
        $this->auditLogger->expects($this->once())->method('logCapture');
        $this->order->expects($this->never())->method('hold');
        $this->order->expects($this->never())->method('save');

        $freshInformation = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $fresh = $this->createMock(Order::class);
        $fresh->method('load')->willReturnSelf();
        $fresh->method('getId')->willReturn(28);
        $fresh->method('getPayment')->willReturn($this->payment($freshInformation));
        $fresh->method('canHold')->willReturn(true);
        $fresh->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(static fn ($comment): bool => str_contains((string) $comment, 'HAS been charged'))
        );
        $fresh->expects($this->once())->method('hold');
        $fresh->expects($this->once())->method('save');
        $this->freshOrder = $fresh;

        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'Capture from PayMongo'))
        );

        $this->observer->execute($this->shipmentObserver());

        $this->assertSame(PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, $freshInformation[PaymentCapture::RESULT_KEY]);
        $this->assertSame(self::PAYMENT_ID, $freshInformation['paymongo_payment_id'] ?? null);
        $this->assertCaptureResult(PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, 'succeeded');
    }

    /**
     * Another shipment of the same order, saved at the same moment, captured and invoiced it
     * while this one waited for the lock: re-read inside the lock, this one leaves it alone.
     *
     * @return void
     */
    public function testConcurrentShipmentDoesNotCaptureOrInvoiceAgain(): void
    {
        $this->givenLock();
        $this->freshOrder = $this->databaseCopy([PaymentCapture::RESULT_KEY => PaymentCapture::RESULT_CAPTURED]);
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->auditLogger->expects($this->never())->method('logCapture');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->shipmentObserver());
    }

    /**
     * A stub of the order as stored in the database, with the given payment additional information.
     *
     * @param array<string, mixed> $information
     * @return Order
     */
    private function databaseCopy(array $information): Order
    {
        $information += ['paymongo_payment_intent_id' => self::INTENT_ID];
        $copy = $this->createStub(Order::class);
        $copy->method('load')->willReturnSelf();
        $copy->method('getId')->willReturn(28);
        $copy->method('getPayment')->willReturn($this->payment($information));

        return $copy;
    }

    /**
     * An order Magento will not invoice after PayMongo captured it (payment review, say) is
     * not recorded as plainly "captured", which would hide the button for good: it is
     * recorded as captured but not invoiced, like any other failed invoice.
     *
     * @return void
     */
    public function testUninvoiceableOrderAfterCaptureIsRecordedAsCapturedNotInvoiced(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn(['data' => ['attributes' => [
                'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
            ]]]);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->order->method('canInvoice')->willReturn(false);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->auditLogger->expects($this->once())->method('logCapture');
        $this->order->expects($this->never())->method('save');

        $freshInformation = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $fresh = $this->createMock(Order::class);
        $fresh->method('load')->willReturnSelf();
        $fresh->method('getId')->willReturn(28);
        $fresh->method('getPayment')->willReturn($this->payment($freshInformation));
        $fresh->method('canHold')->willReturn(true);
        $fresh->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(static fn ($comment): bool => str_contains((string) $comment, 'HAS been charged'))
        );
        $fresh->expects($this->once())->method('hold');
        $fresh->expects($this->once())->method('save');
        $this->freshOrder = $fresh;

        $this->adminNotice->expects($this->once())->method('error');

        $this->observer->execute($this->shipmentObserver());

        $this->assertSame(PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, $freshInformation[PaymentCapture::RESULT_KEY]);
    }

    /**
     * @return void
     */
    private function givenLock(): void
    {
        $this->lockManager->expects($this->once())->method('lock')
            ->with('paymongo_order_000000028', 30)
            ->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->with('paymongo_order_000000028');
    }

    /**
     * @param string $status
     * @return void
     */
    private function givenIntentStatus(string $status, ?string $paymentId = null): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->with(self::INTENT_ID, 1)
            ->willReturn([
                'pi_status'  => $status,
                'authorized' => $status === 'awaiting_capture',
                'payment_id' => $paymentId,
            ]);
    }

    /**
     * @return void
     */
    private function expectNothingCaptured(): void
    {
        $this->lockManager->expects($this->never())->method('lock');
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->auditLogger->expects($this->never())->method('logCapture');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->never())->method('error');
    }

    /**
     * Expect a capture-failure comment naming the intent's status, and no invoice or audit entry.
     *
     * @param string $text
     * @param string $statusLabel
     * @return void
     */
    private function expectCaptureFailure(string $text, string $statusLabel): void
    {
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->auditLogger->expects($this->never())->method('logCapture');
        $this->order->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(static fn ($comment): bool => str_contains((string) $comment, $text)
                && str_contains((string) $comment, self::INTENT_ID . ' is ' . $statusLabel))
        );
    }

    /**
     * @param string $text
     * @return void
     */
    private function expectComment(string $text): void
    {
        $this->order->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(static fn ($comment): bool => str_contains((string) $comment, $text))
        );
    }

    /**
     * Expect an invoice registered with Capture Online, so it gets a transaction ID and can be
     * refunded online (PYM-31). The capture is recorded first, so the method's capture() does
     * not ask PayMongo again.
     *
     * @return void
     */
    private function expectInvoice(): void
    {
        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register', 'getIncrementId'])
            ->getMock();
        $invoice->expects($this->once())->method('register')->willReturnCallback(
            function () use ($invoice): Invoice {
                $this->assertSame(Invoice::CAPTURE_ONLINE, $invoice->getRequestedCaptureCase());
                $this->assertSame(
                    PaymentCapture::RESULT_CAPTURED,
                    $this->additionalInformation[PaymentCapture::RESULT_KEY] ?? null
                );
                return $invoice;
            }
        );
        $this->invoiceService->expects($this->once())->method('prepareInvoice')->willReturn($invoice);
    }

    /**
     * @param string $result
     * @param string|null $status
     * @return void
     */
    private function assertCaptureResult(string $result, ?string $status): void
    {
        $this->assertSame($result, $this->additionalInformation[PaymentCapture::RESULT_KEY] ?? null);
        $this->assertSame($status, $this->additionalInformation[PaymentCapture::STATUS_KEY] ?? null);
    }

    /**
     * A payment stub whose additional information reads and writes the given array.
     *
     * @param array<string, mixed> $information
     * @return Payment
     */
    private function payment(array &$information): Payment
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static function (string $key) use (&$information) {
                return $information[$key] ?? null;
            });
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(static function (string $key, $value) use (&$information, $payment) {
                $information[$key] = $value;
                return $payment;
            });

        return $payment;
    }

    /**
     * @return Observer
     */
    private function shipmentObserver(): Observer
    {
        $shipment = $this->createStub(Shipment::class);
        $shipment->method('getOrder')->willReturn($this->order);

        return new Observer(['event' => new Event(['shipment' => $shipment])]);
    }
}
