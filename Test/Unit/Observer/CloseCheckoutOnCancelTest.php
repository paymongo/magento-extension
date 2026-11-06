<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Observer;

use PayMongo\Payment\Gateway\PaymongoApiException;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Observer\CloseCheckoutOnCancel;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers closing PayMongo's payment page when a PayMongo order is cancelled, so the customer
 * cannot pay for an order the store will not fulfil (PYM-32).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CloseCheckoutOnCancelTest extends TestCase
{
    private const SESSION_ID = 'cs_32';

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var PaidCancelledOrder&MockObject */
    private PaidCancelledOrder $paidCancelledOrder;

    /** @var bool */
    private bool $authorizeOnly = false;

    /** @var Order&MockObject */
    private Order $order;

    /** @var string */
    private string $method = 'paymongo_card';

    /** @var array<string, string> */
    private array $additionalInformation = ['paymongo_checkout_session_id' => self::SESSION_ID];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $this->paidCancelledOrder = $this->createMock(PaidCancelledOrder::class);

        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturnCallback(fn () => $this->method);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(fn (string $key) => $this->additionalInformation[$key] ?? null);

        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getIncrementId')->willReturn('000000032');
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function openStatuses(): array
    {
        return [
            'no payment yet'                  => [null],
            'card declined, awaiting a retry' => ['awaiting_payment_method'],
            'customer mid-way through 3DS'    => ['awaiting_next_action'],
        ];
    }

    /**
     * The reported bug: while the customer could still pay, the payment page is closed and
     * the order says so.
     *
     * @param string|null $piStatus
     * @return void
     */
    #[DataProvider('openStatuses')]
    public function testOpenPaymentPageIsClosed(?string $piStatus): void
    {
        $this->givenSession('active', $piStatus);
        $this->paymongoClient->expects($this->once())->method('expireCheckoutSession')->with(self::SESSION_ID, 1);
        $this->paidCancelledOrder->expects($this->never())->method('alert');
        $this->expectComment('can no longer pay for this cancelled order');

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * Each cancellation asks PayMongo afresh: the answer is forgotten when the cancellation is
     * done, so a long-running cron process never acts on an earlier state (PYM-33).
     *
     * @return void
     */
    public function testEachCancellationAsksPayMongoAfresh(): void
    {
        $this->paymentConfirmation->expects($this->exactly(2))->method('checkCheckoutSession')->willReturn([
            'confirmed' => false, 'authorized' => false, 'status' => 'expired',
            'payment_intent_id' => 'pi_32', 'payment_id' => null, 'pi_status' => 'awaiting_payment_method',
        ]);
        $this->expectNothingDone();
        $observer = $this->observer();

        $observer->execute($this->cancelObserver());
        $observer->execute($this->cancelObserver());
    }

    /**
     * A session PayMongo has already expired is left alone.
     *
     * @return void
     */
    public function testExpiredPageIsLeftAlone(): void
    {
        $this->givenSession('expired', 'awaiting_payment_method');
        $this->expectNothingDone();

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * A customer already charged for the cancelled order is reported to the admin.
     *
     * @return void
     */
    public function testChargedOrderAlertsTheAdmin(): void
    {
        $this->givenSession('active', 'succeeded', 'pay_32');
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $this->paidCancelledOrder->expects($this->once())->method('alert')->with($this->order, 'pay_32');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * A session PayMongo reports as paid, even with no payment intent to read, raises the alert
     * instead of an attempt to close a paid page.
     *
     * @return void
     */
    public function testPaidSessionWithoutAnIntentAlertsTheAdmin(): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')->willReturn([
            'confirmed' => true, 'authorized' => false, 'status' => 'paid',
            'payment_intent_id' => null, 'payment_id' => 'pay_32', 'pi_status' => null,
        ]);
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $this->paidCancelledOrder->expects($this->once())->method('alert')->with($this->order, 'pay_32');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * An Authorize Only order's charge or hold is ReleaseHoldOnCancel's to report and release.
     *
     * @return array<string, array{string}>
     */
    public static function releaseHoldStatuses(): array
    {
        return [
            'charged' => ['succeeded'],
            'on hold' => ['awaiting_capture'],
        ];
    }

    /**
     * @param string $piStatus
     * @return void
     */
    #[DataProvider('releaseHoldStatuses')]
    public function testAuthorizeOnlyChargesAndHoldsAreLeftToReleaseHoldOnCancel(string $piStatus): void
    {
        $this->authorizeOnly = true;
        $this->givenSession('active', $piStatus, $piStatus === 'succeeded' ? 'pay_32' : null);
        $this->expectNothingDone();

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * A payment still processing is not interrupted: if it completes, the payment report
     * raises the alert.
     *
     * @return void
     */
    public function testProcessingPaymentIsLeftAlone(): void
    {
        $this->givenSession('active', 'processing');
        $this->expectNothingDone();

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * Cancelling never depends on PayMongo: if the page cannot be checked or closed, the order
     * says it may still be open.
     *
     * @return void
     */
    public function testPageThatCannotBeClosedIsNoted(): void
    {
        $this->givenSession('active', 'awaiting_payment_method');
        $this->paymongoClient->expects($this->once())->method('expireCheckoutSession')
            ->willThrowException(PaymongoApiException::fromResponse('{"errors":[{"detail":"Server error"}]}'));
        $this->paidCancelledOrder->expects($this->never())->method('alert');
        $this->expectComment('could not be closed');

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * @return void
     */
    public function testUncheckablePageIsNoted(): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->willThrowException(PaymongoApiException::fromResponse('Bad Gateway'));
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $this->paidCancelledOrder->expects($this->never())->method('alert');
        $this->expectComment('could not be closed');

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * Orders of other payment methods, or with no PayMongo checkout session, are not looked up.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    public static function notPayMongoCheckouts(): array
    {
        return [
            'another payment method' => ['checkmo', ['paymongo_checkout_session_id' => self::SESSION_ID]],
            'no checkout session'    => ['paymongo_card', []],
        ];
    }

    /**
     * @param string $method
     * @param array<string, string> $information
     * @return void
     */
    #[DataProvider('notPayMongoCheckouts')]
    public function testOtherOrdersAreNotLookedUp(string $method, array $information): void
    {
        $this->method = $method;
        $this->additionalInformation = $information;
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->expectNothingDone();

        $this->observer()->execute($this->cancelObserver());
    }

    /**
     * @param string $status Checkout session status
     * @param string|null $piStatus Payment intent status
     * @param string|null $paymentId
     * @return void
     */
    private function givenSession(string $status, ?string $piStatus, ?string $paymentId = null): void
    {
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with(self::SESSION_ID, 1)
            ->willReturn([
                'confirmed'         => $piStatus === 'succeeded',
                'authorized'        => $piStatus === 'awaiting_capture',
                'status'            => $status,
                'payment_intent_id' => $piStatus === null ? null : 'pi_32',
                'payment_id'        => $paymentId,
                'pi_status'         => $piStatus,
            ]);
    }

    /**
     * @return void
     */
    private function expectNothingDone(): void
    {
        $this->paymongoClient->expects($this->never())->method('expireCheckoutSession');
        $this->paidCancelledOrder->expects($this->never())->method('alert');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
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
     * @return CloseCheckoutOnCancel
     */
    private function observer(): CloseCheckoutOnCancel
    {
        $captureMode = $this->createStub(CaptureMode::class);
        $captureMode->method('isAuthorizeOnly')->willReturnCallback(fn () => $this->authorizeOnly);

        // The real session rules, around the PayMongo mocks.
        $checkoutStart = new CheckoutStart(
            $this->paymentConfirmation,
            $this->paymongoClient,
            $this->createStub(LockManagerInterface::class),
            $this->createStub(OrderFactory::class),
            $this->createStub(Logger::class),
            $this->createStub(\PayMongo\Payment\Model\Order\CaptureMode::class),
            $this->createStub(\Magento\Sales\Api\OrderRepositoryInterface::class),
            $this->createStub(\Magento\Payment\Helper\Data::class),
            $this->createStub(\PayMongo\Payment\Model\Checkout\EnabledMethodTypes::class)
        );

        return new CloseCheckoutOnCancel(
            $checkoutStart,
            $captureMode,
            $this->paidCancelledOrder,
            $this->createStub(Logger::class)
        );
    }

    /**
     * @return Observer
     */
    private function cancelObserver(): Observer
    {
        return new Observer(['event' => new Event(['order' => $this->order])]);
    }
}
