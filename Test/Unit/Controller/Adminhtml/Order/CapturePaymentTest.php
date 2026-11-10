<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Controller\Adminhtml\Order;

use PayMongo\Payment\Controller\Adminhtml\Order\CapturePayment;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin "Capture from PayMongo" button (PYM-28), which retries a capture that
 * failed or could not be confirmed when the order shipped: it charges a card that is still
 * held, never charges one PayMongo already captured, and changes nothing else when the
 * capture fails again.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CapturePaymentTest extends TestCase
{
    private const INTENT_ID = 'pi_28';
    private const PAYMENT_ID = 'pay_31';

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var InvoiceService&MockObject */
    private InvoiceService $invoiceService;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $messageManager;

    /** @var Order&MockObject */
    private Order $order;

    /** @var array<string, mixed> The order payment's additional information */
    private array $additionalInformation = [
        'paymongo_payment_intent_id' => self::INTENT_ID,
        PaymentCapture::RESULT_KEY   => PaymentCapture::RESULT_NOT_CAPTURED,
    ];

    /**
     * @var Order|null Fresh copy PaymentCapture loads to record a failed invoice. The controller
     *                 loads the order twice (before and inside the lock); later loads get this.
     */
    private ?Order $freshOrder = null;

    /** @var int Orders created by the order factory so far */
    private int $ordersLoaded = 0;

    /** @var CapturePayment */
    private CapturePayment $controller;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        // Only the API lookup is replaced: picking the paid payment out of PayMongo's response is real.
        $this->paymentConfirmation = $this->createPartialMock(PaymentConfirmation::class, ['checkPaymentIntent']);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(fn (string $key) => $this->additionalInformation[$key] ?? null);
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use ($payment) {
                $this->additionalInformation[$key] = $value;
                return $payment;
            });

        $this->order = $this->createMock(Order::class);
        $this->order->method('load')->willReturnSelf();
        $this->order->method('getId')->willReturn(28);
        $this->order->method('getIncrementId')->willReturn('000000028');
        $this->order->method('getState')->willReturn(Order::STATE_HOLDED);
        $this->order->method('getBaseTotalInvoiced')->willReturn(null);
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getGrandTotal')->willReturn(1500.00);

        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturnCallback(
            fn () => ++$this->ordersLoaded > 2 && $this->freshOrder ? $this->freshOrder : $this->order
        );

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
            $this->createStub(AuditLogger::class),
            $this->createStub(Logger::class)
        );

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order_id', null, '28']]);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $this->controller = new CapturePayment(
            $context,
            $paymentCapture,
            $this->createStub(AuthorizationExpiry::class),
            $this->createStub(TimezoneInterface::class),
            $orderFactory,
            $this->createStub(Logger::class)
        );
    }

    /**
     * A card that is still held is charged, and the order released from hold and invoiced.
     *
     * @return void
     */
    public function testHeldCardIsCapturedAndTheOrderInvoiced(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->with(self::INTENT_ID, 150000, 1)
            ->willReturn($this->capturedIntent());
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->order->method('canUnhold')->willReturn(true);
        $this->order->method('canInvoice')->willReturn(true);

        $this->order->expects($this->once())->method('unhold');
        $this->expectInvoice();
        $this->expectComment('captured via PayMongo from the admin');
        $this->messageManager->expects($this->once())->method('addSuccessMessage')
            ->with($this->messageContaining('was captured and the order invoiced'));
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->controller->execute();

        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $this->additionalInformation[PaymentCapture::RESULT_KEY]);
        $this->assertSame(self::PAYMENT_ID, $this->additionalInformation['paymongo_payment_id'] ?? null);
    }

    /**
     * A payment PayMongo already captured (on PayMongo's side, say) is invoiced without a
     * second charge.
     *
     * @return void
     */
    public function testPaymentAlreadyCapturedIsInvoicedWithoutChargingAgain(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->givenIntentStatus('succeeded', self::PAYMENT_ID);
        $this->order->method('canUnhold')->willReturn(true);
        $this->order->method('canInvoice')->willReturn(true);

        $this->order->expects($this->once())->method('unhold');
        $this->expectInvoice();
        $this->expectComment('had already captured this payment');
        $this->messageManager->expects($this->once())->method('addSuccessMessage')
            ->with($this->messageContaining('not charged again'));

        $this->controller->execute();

        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $this->additionalInformation[PaymentCapture::RESULT_KEY]);
        $this->assertSame(self::PAYMENT_ID, $this->additionalInformation['paymongo_payment_id'] ?? null);
    }

    /**
     * A capture that fails while the card is still held changes nothing but a comment, and
     * says it can be tried again.
     *
     * @return void
     */
    public function testFailedCaptureOnAHeldCardCanBeRetried(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: 503')));
        $this->givenIntentStatus('awaiting_capture');

        $this->expectNoInvoice();
        $this->expectComment('the card is still held');
        $this->order->expects($this->once())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->messageContaining('try again'));

        $this->controller->execute();

        $this->assertSame(
            PaymentCapture::RESULT_NOT_CAPTURED,
            $this->additionalInformation[PaymentCapture::RESULT_KEY]
        );
    }

    /**
     * When the hold has expired or been cancelled, the admin is told to collect payment
     * another way.
     *
     * @return void
     */
    public function testHoldThatIsGoneIsReported(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->givenIntentStatus('cancelled');

        $this->expectNoInvoice();
        $this->expectComment('no longer held');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->messageContaining('collect payment another way'));

        $this->controller->execute();
    }

    /**
     * When PayMongo cannot confirm what happened, the admin is told not to collect payment
     * another way yet.
     *
     * @return void
     */
    public function testUnconfirmedCaptureIsReported(): void
    {
        $this->givenLock();
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));

        $this->expectNoInvoice();
        $this->expectComment('could not be confirmed');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->messageContaining('do not collect payment another way'));

        $this->controller->execute();

        $this->assertSame(
            PaymentCapture::RESULT_UNCONFIRMED,
            $this->additionalInformation[PaymentCapture::RESULT_KEY]
        );
    }

    /**
     * An order whose capture took the money but whose invoice failed is invoiced by the
     * button straight away: no capture is sent, so the customer is not charged again. If no
     * PayMongo payment was stored at the capture, it is looked up first, so the invoice can
     * be refunded against it (PYM-31).
     *
     * @return void
     */
    public function testCapturedButNotInvoicedOrderIsInvoicedWithoutChargingAgain(): void
    {
        $this->additionalInformation[PaymentCapture::RESULT_KEY] = PaymentCapture::RESULT_CAPTURED_NOT_INVOICED;
        $this->givenLock();
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->givenIntentStatus('succeeded', self::PAYMENT_ID);
        $this->order->method('canUnhold')->willReturn(true);
        $this->order->method('canInvoice')->willReturn(true);

        $this->order->expects($this->once())->method('unhold');
        $this->expectInvoice();
        $this->expectComment('had already captured this payment');
        $this->messageManager->expects($this->once())->method('addSuccessMessage')
            ->with($this->messageContaining('not charged again'));

        $this->controller->execute();

        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $this->additionalInformation[PaymentCapture::RESULT_KEY]);
        $this->assertSame(self::PAYMENT_ID, $this->additionalInformation['paymongo_payment_id'] ?? null);
    }

    /**
     * PayMongo being unreachable cannot stop the invoice of a known capture, or downgrade it
     * to "unconfirmed": the payment lookup is skipped, and the refund looks it up later.
     *
     * @return void
     */
    public function testCapturedButNotInvoicedOrderIsInvoicedWhenPayMongoIsUnreachable(): void
    {
        $this->additionalInformation[PaymentCapture::RESULT_KEY] = PaymentCapture::RESULT_CAPTURED_NOT_INVOICED;
        $this->givenLock();
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));
        $this->order->method('canUnhold')->willReturn(true);
        $this->order->method('canInvoice')->willReturn(true);

        $this->order->expects($this->once())->method('unhold');
        $this->expectInvoice();
        $this->messageManager->expects($this->once())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->controller->execute();

        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $this->additionalInformation[PaymentCapture::RESULT_KEY]);
        $this->assertArrayNotHasKey('paymongo_payment_id', $this->additionalInformation);
    }

    /**
     * If invoicing fails after the capture, the order is not saved with the failed
     * invoice's totals, and the admin is told a retry will not charge twice.
     *
     * @return void
     */
    public function testInvoiceFailureAfterCaptureIsReported(): void
    {
        $this->givenLock();
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn($this->capturedIntent());
        $this->order->method('canUnhold')->willReturn(true);
        $this->order->method('canInvoice')->willReturn(true);
        $this->invoiceService->expects($this->once())->method('prepareInvoice')
            ->willThrowException(new LocalizedException(__('The invoice cannot be created.')));

        $this->order->expects($this->never())->method('save');

        $freshInformation = [];
        $freshPayment = $this->createStub(Payment::class);
        $freshPayment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use (&$freshInformation, $freshPayment) {
                $freshInformation[$key] = $value;
                return $freshPayment;
            });
        $fresh = $this->createMock(Order::class);
        $fresh->method('load')->willReturnSelf();
        $fresh->method('getId')->willReturn(28);
        $fresh->method('getPayment')->willReturn($freshPayment);
        $fresh->method('canHold')->willReturn(true);
        $fresh->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->messageContaining('HAS been charged'));
        $fresh->expects($this->once())->method('hold');
        $fresh->expects($this->once())->method('save');
        $this->freshOrder = $fresh;

        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->messageContaining('will not be charged twice'));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        $this->controller->execute();

        $this->assertSame(
            PaymentCapture::RESULT_CAPTURED_NOT_INVOICED,
            $freshInformation[PaymentCapture::RESULT_KEY] ?? null
        );
    }

    /**
     * An order whose capture already succeeded — a second click, say — is not captured again.
     *
     * @return void
     */
    public function testOrderAlreadyCapturedIsLeftAlone(): void
    {
        $this->additionalInformation[PaymentCapture::RESULT_KEY] = PaymentCapture::RESULT_CAPTURED;
        $this->givenLock();

        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->expectNoInvoice();
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->messageManager->expects($this->once())->method('addNoticeMessage')
            ->with($this->messageContaining('no PayMongo payment waiting to be captured'));

        $this->controller->execute();
    }

    /**
     * While the webhook, Refresh or another click holds the order's lock, nothing is captured.
     *
     * @return void
     */
    public function testOrderIsLeftAloneWhenTheLockIsHeld(): void
    {
        $this->lockManager->expects($this->once())->method('lock')
            ->with('paymongo_order_000000028', 30)
            ->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');

        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->expectNoInvoice();
        $this->messageManager->expects($this->once())->method('addWarningMessage')
            ->with($this->messageContaining('already being updated'));

        $this->controller->execute();
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
     * PayMongo's response to a capture that took the money.
     *
     * @return array<string, mixed>
     */
    private function capturedIntent(): array
    {
        return ['data' => ['attributes' => [
            'status'   => 'succeeded',
            'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
        ]]];
    }

    /**
     * @return void
     */
    private function expectInvoice(): void
    {
        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register', 'getIncrementId'])
            ->getMock();
        $invoice->expects($this->once())->method('register')->willReturnSelf();
        $this->invoiceService->expects($this->once())->method('prepareInvoice')->willReturn($invoice);
    }

    /**
     * @return void
     */
    private function expectNoInvoice(): void
    {
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->order->expects($this->never())->method('unhold');
    }

    /**
     * @param string $text
     * @return void
     */
    private function expectComment(string $text): void
    {
        $this->order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->messageContaining($text));
    }

    /**
     * @param string $text
     * @return \PHPUnit\Framework\Constraint\Callback
     */
    private function messageContaining(string $text): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(static fn ($message): bool => str_contains((string) $message, $text));
    }
}
