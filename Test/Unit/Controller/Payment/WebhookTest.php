<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Controller\Payment;

use PayMongo\Payment\Controller\Payment\Webhook;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers which orders a payment.paid webhook may confirm. An Authorize Only order is captured
 * when it ships and PayMongo then sends payment.paid for the capture; the order, by then
 * Complete, must not be confirmed again (a "Card authorised" note and a second confirmation
 * email), and a late webhook must not move a cancelled order to Processing. Also covers
 * which payment a webhook names: the paid one, never a declined attempt before it (PYM-31).
 * And a declined attempt: it no longer cancels the order, and a payment for an order that is
 * already cancelled alerts the admin, but only for this store's own checkout (PYM-32).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class WebhookTest extends TestCase
{
    /** @var OrderRepositoryInterface&MockObject */
    private OrderRepositoryInterface $orderRepository;

    /** @var OrderCollectionFactory */
    private OrderCollectionFactory $orderCollectionFactory;

    /** @var InvoiceService&MockObject */
    private InvoiceService $invoiceService;

    /** @var OrderSender&MockObject */
    private OrderSender $orderSender;

    /** @var CaptureMode&MockObject */
    private CaptureMode $captureMode;

    /** @var Payment&MockObject The order's payment, set up by givenOrder() */
    private Payment $payment;

    /** @var Logger A stub unless a test replaces it with a mock to check what is logged */
    private Logger $logger;

    /** @var PaidCancelledOrder A stub unless a test replaces it with a mock */
    private PaidCancelledOrder $paidCancelledOrder;

    /** @var OrderStatusHistoryRepositoryInterface A stub unless a test replaces it with a mock */
    private OrderStatusHistoryRepositoryInterface $historyRepository;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderCollectionFactory = $this->createStub(OrderCollectionFactory::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->captureMode = $this->createMock(CaptureMode::class);
        $this->logger = $this->createStub(Logger::class);
        $this->paidCancelledOrder = $this->createStub(PaidCancelledOrder::class);
        $this->historyRepository = $this->createStub(OrderStatusHistoryRepositoryInterface::class);
    }

    /**
     * @return Webhook
     */
    private function controller(?LockManagerInterface $lockManager = null): Webhook
    {
        return new Webhook(
            $this->createStub(RequestInterface::class),
            $this->createStub(JsonFactory::class),
            $this->createStub(Json::class),
            $this->createStub(Config::class),
            $this->createStub(ResourceConnection::class),
            $this->orderRepository,
            $this->orderCollectionFactory,
            $this->invoiceService,
            $this->createStub(TransactionFactory::class),
            $this->orderSender,
            $lockManager ?? $this->createStub(LockManagerInterface::class),
            $this->logger,
            $this->createStub(PaymentReference::class),
            $this->captureMode,
            new PaymentConfirmation($this->createStub(PaymongoClient::class)),
            $this->paidCancelledOrder,
            $this->historyRepository
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function settledStates(): array
    {
        return [
            'already confirmed' => [Order::STATE_PROCESSING],
            'captured on shipment' => [Order::STATE_COMPLETE],
            'refunded' => [Order::STATE_CLOSED],
            'cancelled' => [Order::STATE_CANCELED],
        ];
    }

    /**
     * An order that is no longer awaiting payment keeps its state and gets no note, invoice
     * or email, but a missing PayMongo payment ID is still saved for refunds and reconciliation.
     *
     * @param string $state
     * @return void
     */
    #[DataProvider('settledStates')]
    public function testOrderNoLongerAwaitingPaymentIsNotConfirmedAgain(string $state): void
    {
        $order = $this->givenOrder($state, null);
        $this->expectNotConfirmedAgain($order);
        $this->expectPaymentIdSaved('pay_7');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->processPaymentPaid('pay_7');
    }

    /**
     * A duplicate webhook for an order that already has its payment ID saves nothing.
     *
     * @return void
     */
    public function testNothingIsSavedWhenThePaymentIdIsAlreadyStored(): void
    {
        $order = $this->givenOrder(Order::STATE_COMPLETE, 'pay_7');
        $this->expectNotConfirmedAgain($order);
        $this->payment->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->never())->method('save');

        $this->processPaymentPaid('pay_7');
    }

    /**
     * A stored payment ID is never replaced. Another store on the same PayMongo account can
     * send a payment with this order's number, and overwriting the ID would point this
     * order's refunds at that payment. The mismatch is logged.
     *
     * @return void
     */
    public function testADifferentStoredPaymentIdIsNotOverwritten(): void
    {
        $order = $this->givenOrder(Order::STATE_COMPLETE, 'pay_this_store');
        $this->expectNotConfirmedAgain($order);
        $this->payment->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->never())->method('save');

        $logged = [];
        $this->logger = $this->createMock(Logger::class);
        $this->logger->expects($this->atLeastOnce())->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$logged): void {
                $logged[$message] = $context;
            }
        );

        $this->processPaymentPaid('pay_other_store');

        $mismatch = $logged['Webhook: order already has a different PayMongo payment ID, kept it'] ?? null;
        $this->assertSame(
            ['order_id' => '000000007', 'stored_payment_id' => 'pay_this_store', 'webhook_payment_id' => 'pay_other_store'],
            $mismatch
        );
    }

    /**
     * A payment reported for a cancelled order is logged as a warning for the merchant to
     * check, since the customer may have been charged.
     *
     * @return void
     */
    public function testPaymentForACancelledOrderIsLoggedAsAWarning(): void
    {
        $order = $this->givenOrder(Order::STATE_CANCELED, null);
        $this->expectNotConfirmedAgain($order);
        $this->expectPaymentIdSaved('pay_7');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->logger = $this->createMock(Logger::class);
        $this->logger->expects($this->once())->method('warning')->with(
            'Webhook: PayMongo reports a payment for a cancelled order',
            $this->callback(static fn (array $context): bool => $context['payment_id'] === 'pay_7')
        );

        $this->processPaymentPaid('pay_7');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function awaitingStates(): array
    {
        return [
            'pending payment' => [Order::STATE_PENDING_PAYMENT],
            'new' => [Order::STATE_NEW],
        ];
    }

    /**
     * An order still awaiting payment is confirmed as before (here an Authorize Only card,
     * which moves to Processing without an invoice).
     *
     * @param string $state
     * @return void
     */
    #[DataProvider('awaitingStates')]
    public function testOrderAwaitingPaymentIsConfirmed(string $state): void
    {
        $order = $this->givenOrder($state, null);
        $this->expectPaymentIdSaved('pay_7');
        $this->captureMode->expects($this->once())->method('isAuthorizeOnly')->with($order)->willReturn(true);

        $order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->orderSender->expects($this->once())->method('send')->with($order);

        $this->processPaymentPaid('pay_7');
    }

    /**
     * A payment for a cancelled order from this store's own checkout alerts the admin, and the
     * alert is saved on the order (PYM-32).
     *
     * @return void
     */
    public function testPaymentForACancelledOrderAlertsTheAdmin(): void
    {
        $order = $this->givenOrder(Order::STATE_CANCELED, 'pay_7', 'pi_7');
        $this->expectNotConfirmedAgain($order);
        $this->paidCancelledOrder = $this->createMock(PaidCancelledOrder::class);
        $this->paidCancelledOrder->expects($this->once())->method('alert')->with($order, 'pay_7')->willReturn(true);
        $this->payment->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->processPaymentPaid('pay_7', 'pi_7');
    }

    /**
     * Another store on the same PayMongo account can have an order with this number: its
     * payment, for another payment intent, raises no alert here.
     *
     * @return void
     */
    public function testAnotherStoresPaymentForACancelledOrderRaisesNoAlert(): void
    {
        $order = $this->givenOrder(Order::STATE_CANCELED, 'pay_7', 'pi_7');
        $this->expectNotConfirmedAgain($order);
        $this->paidCancelledOrder = $this->createMock(PaidCancelledOrder::class);
        $this->paidCancelledOrder->expects($this->never())->method('alert');
        $this->payment->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->never())->method('save');

        $this->processPaymentPaid('pay_other_store', 'pi_other_store');
    }

    /**
     * The reported gap: the customer completed a 3DS or e-wallet payment on a page opened
     * before the current one. It is this order's payment: the order is confirmed, and the
     * paid page's intent becomes the order's (for a capture or the alert).
     *
     * @return void
     */
    public function testPaymentOnAnEarlierPageConfirmsTheOrder(): void
    {
        $order = $this->givenOrder(
            Order::STATE_PENDING_PAYMENT,
            null,
            'pi_current',
            [['session' => 'cs_earlier', 'intent' => 'pi_earlier']]
        );
        $this->payment->expects($this->exactly(2))->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) {
                $this->assertContains([$key, $value], [
                    ['paymongo_payment_intent_id', 'pi_earlier'],
                    ['paymongo_payment_id', 'pay_7'],
                ]);
                return $this->payment;
            });
        $this->captureMode->expects($this->once())->method('isAuthorizeOnly')->willReturn(true);
        $order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->orderSender->expects($this->once())->method('send')->with($order);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $this->processPaymentPaid('pay_7', 'pi_earlier');
    }

    /**
     * When the order stays busy for the whole wait, the event is not processed: the webhook
     * throws, so it answers PayMongo with a retry and does not mark the event processed.
     *
     * @return void
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testBusyOrderAsksPayMongoToRetry(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $controller = $this->controller($lockManager);

        $this->expectException(\PayMongo\Payment\Model\Order\OrderBusyException::class);

        (new \ReflectionMethod($controller, 'withOrderLock'))->invoke(
            $controller,
            '000000007',
            'payment.paid',
            fn () => $this->fail('Nothing may run without the lock.')
        );
    }

    /**
     * Another store's payment for its own order with this number never confirms, invoices or
     * gives a payment ID to this store's order, which is still awaiting its own payment.
     *
     * @return void
     */
    public function testAnotherStoresPaymentDoesNotConfirmAPendingOrder(): void
    {
        $order = $this->givenOrder(Order::STATE_PENDING_PAYMENT, null, 'pi_7');
        $this->expectNotConfirmedAgain($order);
        $this->payment->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->never())->method('save');

        $this->processPaymentPaid('pay_other_store', 'pi_other_store');
    }

    /**
     * A declined attempt keeps the order open for a retry, with PayMongo's reason on it. It is
     * never cancelled: the customer can still pay on PayMongo's page (PYM-32).
     *
     * @return void
     */
    public function testDeclinedAttemptKeepsTheOrderOpen(): void
    {
        $order = $this->givenOrder(Order::STATE_PENDING_PAYMENT, null, 'pi_7');
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('setState');
        // Only the comment is saved: saving the order would restart the Pending Payment lifetime.
        $history = $this->createStub(\Magento\Sales\Model\Order\Status\History::class);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            static fn ($comment): bool => str_contains((string) $comment, 'insufficient funds')
                && str_contains((string) $comment, 'can still pay')
        ))->willReturn($history);
        $this->historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $this->historyRepository->expects($this->once())->method('save')->with($history);
        $this->orderRepository->expects($this->never())->method('save');
        $this->expectNoPaymentProcessing();

        $this->processPaymentFailed('pi_7', 'The card has insufficient funds to complete the purchase.');
    }

    /**
     * A declined attempt on another checkout (another store's order with this number), or on
     * an order no longer awaiting payment, changes nothing.
     *
     * @return array<string, array{string, string}>
     */
    public static function ignoredFailures(): array
    {
        return [
            'another store\'s checkout' => [Order::STATE_PENDING_PAYMENT, 'pi_other_store'],
            'already paid'             => [Order::STATE_PROCESSING, 'pi_7'],
            'already cancelled'        => [Order::STATE_CANCELED, 'pi_7'],
        ];
    }

    /**
     * @param string $state
     * @param string $eventIntentId
     * @return void
     */
    #[DataProvider('ignoredFailures')]
    public function testDeclinedAttemptIsIgnoredWhenNotThisOrdersOpenCheckout(
        string $state,
        string $eventIntentId
    ): void {
        $order = $this->givenOrder($state, null, 'pi_7');
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('addCommentToStatusHistory');
        $this->orderRepository->expects($this->never())->method('save');
        $this->expectNoPaymentProcessing();

        $this->processPaymentFailed($eventIntentId, 'Declined.');
    }

    /**
     * A checkout session webhook names the paid payment, not a declined attempt listed before
     * it: the order keeps the first ID it is given, and refunds go to it.
     *
     * @return void
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCheckoutSessionWebhookNamesThePaidPayment(): void
    {
        $session = ['id' => 'cs_7', 'attributes' => ['payments' => [
            ['id' => 'pay_declined', 'attributes' => ['status' => 'failed']],
            ['id' => 'pay_7', 'attributes' => ['status' => 'paid']],
        ]]];

        $this->assertSame('pay_7', $this->extractPaymentId($session, 'checkout_session.payment.paid'));
    }

    /**
     * A checkout session webhook with no paid payment names none.
     *
     * @return void
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCheckoutSessionWebhookWithoutAPaidPaymentNamesNone(): void
    {
        $session = ['id' => 'cs_7', 'attributes' => ['payments' => [
            ['id' => 'pay_declined', 'attributes' => ['status' => 'failed']],
        ]]];

        $this->assertNull($this->extractPaymentId($session, 'checkout_session.payment.paid'));
    }

    /**
     * A payment.paid webhook is about the payment itself.
     *
     * @return void
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPaymentWebhookNamesItsOwnPayment(): void
    {
        $this->assertSame(
            'pay_7',
            $this->extractPaymentId(['id' => 'pay_7', 'attributes' => ['status' => 'paid']], 'payment.paid')
        );
    }

    /**
     * @param array<string, mixed> $eventData
     * @param string $eventType
     * @return string|null
     */
    private function extractPaymentId(array $eventData, string $eventType): ?string
    {
        $controller = $this->controller();

        return (new \ReflectionMethod($controller, 'extractPaymentId'))->invoke($controller, $eventData, $eventType);
    }

    /**
     * The order keeps its state, gets no note, invoice or email, and the capture mode is not
     * even consulted (the cancelled-order alert asks PayMongo whether a card is held).
     *
     * @param Order&MockObject $order
     * @return void
     */
    private function expectNotConfirmedAgain(Order $order): void
    {
        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('setStatus');
        $order->expects($this->never())->method('addCommentToStatusHistory');
        $this->captureMode->expects($this->never())->method('isAuthorizeOnly');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->orderSender->expects($this->never())->method('send');
    }

    /**
     * A declined attempt never invoices, emails or changes the order's payment data.
     *
     * @return void
     */
    private function expectNoPaymentProcessing(): void
    {
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->orderSender->expects($this->never())->method('send');
        $this->captureMode->expects($this->never())->method('isAuthorizeOnly');
        $this->payment->expects($this->never())->method('setAdditionalInformation');
    }

    /**
     * @param string $paymentId
     * @return void
     */
    private function expectPaymentIdSaved(string $paymentId): void
    {
        $this->payment->expects($this->once())->method('setAdditionalInformation')
            ->with('paymongo_payment_id', $paymentId);
    }

    /**
     * @param string $state
     * @param string|null $storedPaymentId PayMongo payment ID already on the order, if any
     * @param string|null $storedIntentId PayMongo payment intent ID on the order, if any
     * @param array<int, array<string, string>> $previousCheckouts Pages opened before the current one
     * @return Order&MockObject
     */
    private function givenOrder(
        string $state,
        ?string $storedPaymentId,
        ?string $storedIntentId = null,
        array $previousCheckouts = []
    ): Order {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['paymongo_payment_id', $storedPaymentId],
            ['paymongo_payment_intent_id', $storedIntentId],
            ['paymongo_checkout_session_id', null],
            [\PayMongo\Payment\Model\Order\CheckoutStart::PREVIOUS_CHECKOUTS_KEY, $previousCheckouts],
        ]);
        $this->payment = $payment;

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        return $order;
    }

    /**
     * Run the handler the webhook calls once it holds the order's lock.
     *
     * @param string|null $paymentId
     * @param string|null $eventIntentId
     * @return void
     */
    private function processPaymentPaid(?string $paymentId, ?string $eventIntentId = null): void
    {
        $controller = $this->controller();
        (new \ReflectionMethod($controller, 'processPaymentPaid'))
            ->invoke($controller, '000000007', $paymentId, $eventIntentId, null);
    }

    /**
     * Run the payment.failed handler the webhook calls once it holds the order's lock.
     *
     * @param string|null $eventIntentId
     * @param string|null $reason
     * @return void
     */
    private function processPaymentFailed(?string $eventIntentId, ?string $reason): void
    {
        $controller = $this->controller();
        (new \ReflectionMethod($controller, 'processPaymentFailed'))
            ->invoke($controller, '000000007', $eventIntentId, $reason);
    }
}
