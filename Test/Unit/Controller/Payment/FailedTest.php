<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Controller\Payment;

use PayMongo\Payment\Controller\Payment\Failed;
use PayMongo\Payment\Gateway\PaymongoApiException;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers which order the return from PayMongo's page cancels: only the visitor's own PayMongo
 * order still awaiting payment, and only once PayMongo confirms it was not paid. Before PYM-32
 * any order_id was cancelled, without asking PayMongo (PYM-32).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class FailedTest extends TestCase
{
    private const ORDER_ID = 32;

    /** @var OrderManagementInterface&MockObject */
    private OrderManagementInterface $orderManagement;

    /** @var CheckoutSession&MockObject */
    private CheckoutSession $checkoutSession;

    /** @var int|null The order the visitor's checkout session placed last */
    private ?int $lastOrderId = self::ORDER_ID;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var LockManagerInterface|null A test that checks the lock sets this; else a stub granting it */
    private ?LockManagerInterface $lockManager = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call', 'restoreQuote'])
            ->getMock();
        $this->checkoutSession->method('__call')->willReturnCallback(fn (string $name) => match ($name) {
            'getLastOrderId'     => $this->lastOrderId,
            'getLastRealOrderId' => '000000032',
            default              => null,
        });
        $this->paymentConfirmation = $this->createMock(PaymentConfirmation::class);
    }

    /**
     * The visitor's own PayMongo order, still awaiting payment and not paid on PayMongo, is
     * cancelled and the cart restored.
     *
     * @return void
     */
    public function testOwnUnpaidOrderIsCancelled(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->expects($this->once())->method('lock')
            ->with('paymongo_order_000000032')
            ->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->with('paymongo_order_000000032');
        $this->givenSession(false, 'awaiting_payment_method');
        $this->orderManagement->expects($this->once())->method('cancel')->with(self::ORDER_ID);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->execute($this->order(Order::STATE_PENDING_PAYMENT, 'paymongo_gcash'));
    }

    /**
     * The reported race: the customer paid, or a GCash or Maya payment is still processing or
     * waiting on their approval in the app, and they still followed PayMongo's back link. The order is left for the webhook to
     * confirm, and the cart is not restored, so it cannot be bought twice.
     *
     * @return array<string, array{bool, string}>
     */
    public static function paidOrProcessing(): array
    {
        return [
            'paid'                                => [true, 'succeeded'],
            'processing'                          => [false, 'processing'],
            'waiting on the e-wallet app or 3DS' => [false, 'awaiting_next_action'],
        ];
    }

    /**
     * @param bool $confirmed
     * @param string $piStatus
     * @return void
     */
    #[DataProvider('paidOrProcessing')]
    public function testPaidOrProcessingOrderIsNotCancelled(bool $confirmed, string $piStatus): void
    {
        $this->givenSession($confirmed, $piStatus);
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->execute($this->order(Order::STATE_PENDING_PAYMENT, 'paymongo_gcash'));
    }

    /**
     * When PayMongo cannot be asked, the order is left open: the webhook confirms it if it was
     * paid, and Magento's cron cancels it if not.
     *
     * @return void
     */
    public function testOrderIsLeftOpenWhenPayMongoCannotBeAsked(): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->willThrowException(PaymongoApiException::fromResponse('Bad Gateway'));
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->execute($this->order(Order::STATE_PENDING_PAYMENT, 'paymongo_gcash'));
    }

    /**
     * The reported hole: another customer's order, by guessing its ID, is not cancelled.
     *
     * @return void
     */
    public function testSomeoneElsesOrderIsNotCancelled(): void
    {
        $this->lastOrderId = 31;
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->execute($this->order(Order::STATE_PENDING_PAYMENT, 'paymongo_gcash'));
    }

    /**
     * A visitor with no order placed in this session (another browser, a headless storefront)
     * cancels nothing.
     *
     * @return void
     */
    public function testVisitorWithoutAnOrderCancelsNothing(): void
    {
        $this->lastOrderId = null;
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->execute($this->order(Order::STATE_PENDING_PAYMENT, 'paymongo_gcash'));
    }

    /**
     * The visitor's own order is still not cancelled, nor its cart restored, once it is paid (an
     * Authorize Only order waiting to ship), or when it is not a PayMongo order.
     *
     * @return array<string, array{string, string}>
     */
    public static function ordersNotToCancel(): array
    {
        return [
            'paid, card held for shipping' => [Order::STATE_PROCESSING, 'paymongo_card'],
            'another payment method'       => [Order::STATE_PENDING_PAYMENT, 'checkmo'],
        ];
    }

    /**
     * @param string $state
     * @param string $method
     * @return void
     */
    #[DataProvider('ordersNotToCancel')]
    public function testOwnOrderNotAwaitingPayMongoIsNotCancelled(string $state, string $method): void
    {
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->execute($this->order($state, $method));
    }

    /**
     * @param Order $order
     * @return void
     */
    private function execute(Order $order): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order_id', null, (string) self::ORDER_ID]]);

        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('get')->willReturn($order);

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($this->createStub(Redirect::class));

        $lockManager = $this->lockManager;
        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturn(true);
        }
        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturn($order);
        $checkoutStart = new CheckoutStart(
            $this->paymentConfirmation,
            $this->createStub(PaymongoClient::class),
            $lockManager,
            $this->createStub(OrderFactory::class),
            $this->createStub(Logger::class),
            $this->createStub(\PayMongo\Payment\Model\Order\CaptureMode::class),
            $this->createStub(\Magento\Sales\Api\OrderRepositoryInterface::class),
            $this->createStub(\Magento\Payment\Helper\Data::class),
            $this->createStub(\PayMongo\Payment\Model\Checkout\EnabledMethodTypes::class)
        );

        (new Failed(
            $request,
            $this->checkoutSession,
            $repository,
            $this->orderManagement,
            $resultFactory,
            $this->createStub(ManagerInterface::class),
            $this->createStub(Logger::class),
            $checkoutStart,
            $lockManager,
            $orderFactory
        ))->execute();
    }

    /**
     * @param bool $confirmed
     * @param string $piStatus
     * @return void
     */
    private function givenSession(bool $confirmed, string $piStatus): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with('cs_32', 1)
            ->willReturn([
                'confirmed'         => $confirmed,
                'authorized'        => false,
                'status'            => 'active',
                'payment_intent_id' => 'pi_32',
                'payment_id'        => $confirmed ? 'pay_32' : null,
                'pi_status'         => $piStatus,
            ]);
    }

    /**
     * @param string $state
     * @param string $method
     * @return Order
     */
    private function order(string $state, string $method): Order
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $key) => $key === 'paymongo_checkout_session_id' ? 'cs_32' : null
        );

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(self::ORDER_ID);
        $order->method('getIncrementId')->willReturn('000000032');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);
        $order->method('canCancel')->willReturn(true);
        $order->method('getStoreId')->willReturn(1);
        $order->method('load')->willReturnSelf();

        return $order;
    }
}
