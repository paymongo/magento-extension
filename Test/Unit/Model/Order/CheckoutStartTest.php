<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Gateway\PaymongoApiException;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PayMongo\Payment\Model\Order\CaptureMode;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers when a new PayMongo payment page may be opened for an order, and closing the previous
 * one, so only one page can take a payment for an order and none for a cancelled one (PYM-32).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CheckoutStartTest extends TestCase
{
    private const SESSION_ID = 'cs_32';

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
    }

    /**
     * The first page for an order is opened without asking PayMongo anything.
     *
     * @return void
     */
    public function testFirstPageIsOpened(): void
    {
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, null));
    }

    /**
     * A cancelled, paid or closed order never gets a payment page.
     *
     * @return array<string, array{string}>
     */
    public static function settledStates(): array
    {
        return [
            'cancelled' => [Order::STATE_CANCELED],
            'paid'      => [Order::STATE_PROCESSING],
            'closed'    => [Order::STATE_CLOSED],
        ];
    }

    /**
     * @param string $state
     * @return void
     */
    #[DataProvider('settledStates')]
    public function testSettledOrderIsRefused(string $state): void
    {
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('can no longer be paid');

        $this->start()->prepare($this->order($state, self::SESSION_ID));
    }

    /**
     * The reported gap: a page opened earlier that could still take a payment is closed first.
     *
     * @return array<string, array{?string}>
     */
    public static function openStatuses(): array
    {
        return [
            'no payment yet'       => [null],
            'awaiting a payment'   => ['awaiting_payment_method'],
            'customer mid-way 3DS' => ['awaiting_next_action'],
        ];
    }

    /**
     * @param string|null $piStatus
     * @return void
     */
    #[DataProvider('openStatuses')]
    public function testPreviousOpenPageIsClosed(?string $piStatus): void
    {
        $this->givenPreviousSession('active', $piStatus, false);
        $this->paymongoClient->expects($this->once())->method('expireCheckoutSession')->with(self::SESSION_ID, 1);

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * An expired previous page needs no closing.
     *
     * @return void
     */
    public function testPreviousExpiredPageIsLeftAlone(): void
    {
        $this->givenPreviousSession('expired', 'awaiting_payment_method', false);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->start()->prepare($this->order(Order::STATE_NEW, self::SESSION_ID));
    }

    /**
     * A previous page the customer already paid gets no second page, so they are not charged twice.
     *
     * @return void
     */
    public function testPreviousPaidPageIsRefused(): void
    {
        $this->givenPreviousSession('active', 'succeeded', true);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already received your payment');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * The reported trap: a previous page whose payment was cancelled (in the PayMongo Dashboard,
     * say) can take no payment, so a new page is opened, not refused for good as "processing".
     *
     * @return void
     */
    public function testPreviousCancelledPaymentDoesNotBlockANewPage(): void
    {
        $this->givenPreviousSession('active', 'cancelled', false);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * open() runs under the order's lock, on a freshly loaded copy of the order, so a second
     * request at the same moment sees the first one's session.
     *
     * @return void
     */
    public function testOpensUnderTheOrderLockOnAFreshOrder(): void
    {
        $stale = $this->order(Order::STATE_PENDING_PAYMENT, null);
        $fresh = $this->order(Order::STATE_PENDING_PAYMENT, null);
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->with('paymongo_order_000000032')->willReturn(true);
        $lockManager->expects($this->once())->method('unlock')->with('paymongo_order_000000032');
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $result = $this->start($lockManager, $fresh)->open($stale, fn (Order $order) => $order);

        $this->assertSame($fresh, $result);
    }

    /**
     * When another request holds the order for the whole wait, no page is opened.
     *
     * @return void
     */
    public function testBusyOrderOpensNoPage(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->willReturn(false);
        $lockManager->expects($this->never())->method('unlock');
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('being updated');

        $this->start($lockManager)->open(
            $this->order(Order::STATE_PENDING_PAYMENT, null),
            fn () => $this->fail('No page may be opened.')
        );
    }

    /**
     * A new session replaces the previous one's intent and payment, even when PayMongo names no
     * intent, so the webhook never matches against a stale one.
     *
     * @return void
     */
    public function testRecordingASessionClearsThePreviousIntentAndPayment(): void
    {
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $info = [
            'paymongo_checkout_session_id' => 'cs_old',
            'paymongo_payment_intent_id'   => 'pi_old',
            'paymongo_payment_id'          => 'pay_old',
        ];
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(function (string $key) use (&$info) {
                return $info[$key] ?? null;
            });
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use (&$info, $payment) {
                $info[$key] = $value;
                return $payment;
            });
        $payment->method('unsAdditionalInformation')
            ->willReturnCallback(function (string $key) use (&$info, $payment) {
                unset($info[$key]);
                return $payment;
            });

        $this->start()->recordSession($payment, 'cs_new', null);

        $this->assertSame('cs_new', $info['paymongo_checkout_session_id']);
        $this->assertArrayNotHasKey('paymongo_payment_intent_id', $info);
        $this->assertArrayNotHasKey('paymongo_payment_id', $info);
        // The earlier page is remembered, so a payment completed on it is still recognised.
        $this->assertSame(
            [['session' => 'cs_old', 'intent' => 'pi_old']],
            $info[CheckoutStart::PREVIOUS_CHECKOUTS_KEY]
        );
    }

    /**
     * A payment still processing counts even on an expired page: it may still complete.
     *
     * @return void
     */
    public function testProcessingPaymentOnAnExpiredPageIsRefused(): void
    {
        $this->givenPreviousSession('expired', 'processing', false);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('still being processed');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * Within one request PayMongo is asked once per session: cancelling an order reads the same
     * session in the Failed controller, ReleaseHoldOnCancel and CloseCheckoutOnCancel (PYM-33).
     * Closing the page forgets the answer, so the next read asks PayMongo again.
     *
     * @return void
     */
    public function testAsksPayMongoOncePerSessionUntilThePageIsClosed(): void
    {
        $this->paymentConfirmation->expects($this->exactly(2))->method('checkCheckoutSession')
            ->with(self::SESSION_ID, 1)
            ->willReturn([
                'confirmed' => false, 'authorized' => false, 'status' => 'active',
                'payment_intent_id' => 'pi_32', 'payment_id' => null, 'pi_status' => 'awaiting_payment_method',
            ]);
        $this->paymongoClient->expects($this->once())->method('expireCheckoutSession');
        $order = $this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID);
        $start = $this->start();

        $this->assertSame(CheckoutStart::STATE_OPEN, $start->sessionState($order)['state']);
        $this->assertSame(CheckoutStart::STATE_OPEN, $start->sessionState($order)['state']);
        $start->closeSession($order);
        $start->sessionState($order);
    }

    /**
     * forget() drops PayMongo's answer, so an operation that ends (a cancellation) or changed the
     * payment (a released hold) never leaves a long-running process acting on an old state.
     *
     * @return void
     */
    public function testForgetMakesTheNextReadAskPayMongoAgain(): void
    {
        $this->paymentConfirmation->expects($this->exactly(2))->method('checkCheckoutSession')
            ->willReturn([
                'confirmed' => false, 'authorized' => false, 'status' => 'active',
                'payment_intent_id' => 'pi_32', 'payment_id' => null, 'pi_status' => 'awaiting_payment_method',
            ]);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $order = $this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID);
        $start = $this->start();

        $start->sessionState($order);
        $start->forget($order);
        $start->sessionState($order);
    }

    /**
     * openPage() is the one place a page is created (PYM-33). What it offers and whether it holds
     * the card come from the order's own payment method; PayMongo's session, its intent and the
     * capture mode are recorded on the order, which is saved, and the page is returned.
     *
     * @return void
     */
    public function testOpenPageCreatesAndRecordsTheSession(): void
    {
        $info = [];
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn('paymongo_gcash');
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(function (string $key) use (&$info) {
                return $info[$key] ?? null;
            });
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use (&$info, $payment) {
                $info[$key] = $value;
                return $payment;
            });
        $order = $this->createStub(Order::class);
        $order->method('load')->willReturnSelf();
        $order->method('getId')->willReturn(32);
        $order->method('getIncrementId')->willReturn('000000032');
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $gcash = $this->createStub(\PayMongo\Payment\Model\Method\Gcash::class);
        $gcash->method('getCheckoutSessionTypes')->willReturn(['gcash']);
        $paymentHelper = $this->createStub(\Magento\Payment\Helper\Data::class);
        $paymentHelper->method('getMethodInstance')->willReturnMap([['paymongo_gcash', $gcash]]);

        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->once())->method('createCheckoutSession')
            ->with($order, ['gcash'], false)
            ->willReturn(['data' => ['id' => 'cs_new', 'attributes' => [
                'checkout_url' => 'https://checkout.paymongo.com/cs_new',
                'payment_intent' => ['id' => 'pi_new'],
            ]]]);
        $captureMode = $this->createMock(CaptureMode::class);
        $captureMode->expects($this->once())->method('shouldAuthorizeOnly')
            ->with('paymongo_gcash', 1)
            ->willReturn(false);
        $captureMode->expects($this->once())->method('record')->with($payment, false);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->once())->method('save')->with($order);

        $page = $this->start(null, $order, $captureMode, $orderRepository, $paymentHelper)->openPage($order);

        $this->assertSame([
            'session_id'        => 'cs_new',
            'checkout_url'      => 'https://checkout.paymongo.com/cs_new',
            'payment_intent_id' => 'pi_new',
        ], $page);
        $this->assertSame('cs_new', $info['paymongo_checkout_session_id']);
        $this->assertSame('pi_new', $info['paymongo_payment_intent_id']);
    }

    /**
     * When PayMongo gives no page, nothing is recorded and the customer gets an error.
     *
     * @return void
     */
    public function testOpenPageWithoutAPageFromPayMongoRecordsNothing(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT, null);
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->once())->method('createCheckoutSession')->willReturn(['data' => []]);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unable to create PayMongo checkout session');

        $this->start(null, $order, null, $orderRepository)->openPage($order);
    }

    /**
     * A payment still processing on the previous page is not interrupted.
     *
     * @return void
     */
    public function testPreviousProcessingPaymentIsRefused(): void
    {
        $this->givenPreviousSession('active', 'processing', false);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('still being processed');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * When PayMongo cannot be asked, or the previous page cannot be closed, no second payable
     * page is opened.
     *
     * @return void
     */
    public function testUnreachablePayMongoOpensNoSecondPage(): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->willThrowException(PaymongoApiException::fromResponse('Bad Gateway'));
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not reach PayMongo');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * @return void
     */
    public function testPageThatCannotBeClosedOpensNoSecondPage(): void
    {
        $this->givenPreviousSession('active', 'awaiting_payment_method', false);
        $this->paymongoClient->expects($this->once())->method('expireCheckoutSession')
            ->willThrowException(PaymongoApiException::fromResponse('Bad Gateway'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not reach PayMongo');

        $this->start()->prepare($this->order(Order::STATE_PENDING_PAYMENT, self::SESSION_ID));
    }

    /**
     * @param string $status
     * @param string|null $piStatus
     * @param bool $confirmed
     * @return void
     */
    private function givenPreviousSession(string $status, ?string $piStatus, bool $confirmed): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with(self::SESSION_ID, 1)
            ->willReturn([
                'confirmed'         => $confirmed,
                'authorized'        => false,
                'status'            => $status,
                'payment_intent_id' => 'pi_32',
                'payment_id'        => $confirmed ? 'pay_32' : null,
                'pi_status'         => $piStatus,
            ]);
    }

    /**
     * @param string $state
     * @param string|null $sessionId
     * @return Order
     */
    private function order(string $state, ?string $sessionId): Order
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $key) => $key === 'paymongo_checkout_session_id' ? $sessionId : null
        );

        $order = $this->createStub(Order::class);
        $order->method('load')->willReturnSelf();
        $order->method('getId')->willReturn(32);
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000032');

        return $order;
    }

    /**
     * @param LockManagerInterface|null $lockManager
     * @param Order|null $fresh What loading the order inside the lock returns
     * @return CheckoutStart
     */
    private function start(
        ?LockManagerInterface $lockManager = null,
        ?Order $fresh = null,
        ?CaptureMode $captureMode = null,
        ?OrderRepositoryInterface $orderRepository = null,
        ?\Magento\Payment\Helper\Data $paymentHelper = null
    ): CheckoutStart {
        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturn($fresh ?? $this->order(Order::STATE_PENDING_PAYMENT, null));
        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturn(true);
        }

        return new CheckoutStart(
            $this->paymentConfirmation,
            $this->paymongoClient,
            $lockManager,
            $orderFactory,
            $this->createStub(Logger::class),
            $captureMode ?? $this->createStub(CaptureMode::class),
            $orderRepository ?? $this->createStub(OrderRepositoryInterface::class),
            $paymentHelper ?? $this->createStub(\Magento\Payment\Helper\Data::class),
            $this->createStub(\PayMongo\Payment\Model\Checkout\EnabledMethodTypes::class)
        );
    }
}
