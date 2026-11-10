<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Observer;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\AdminNotice;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Observer\ReleaseHoldOnCancel;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers releasing the PayMongo card hold when an Authorize Only order is cancelled, where
 * PYM-28's bug was: cancelling only changed the order in Magento, and the customer's funds
 * stayed held on PayMongo until the authorisation expired.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class ReleaseHoldOnCancelTest extends TestCase
{
    private const INTENT_ID = 'pi_28';

    private const SESSION_ID = 'cs_28';

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var CaptureMode&MockObject */
    private CaptureMode $captureMode;

    /** @var AdminNotice&MockObject */
    private AdminNotice $adminNotice;

    /** @var Order&MockObject */
    private Order $order;

    /** @var array<string, string> */
    private array $additionalInformation = ['paymongo_payment_intent_id' => self::INTENT_ID];

    /** @var ReleaseHoldOnCancel */
    private ReleaseHoldOnCancel $observer;

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $this->captureMode = $this->createMock(CaptureMode::class);
        $this->adminNotice = $this->createMock(AdminNotice::class);

        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(fn (string $key) => $this->additionalInformation[$key] ?? null);

        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getIncrementId')->willReturn('000000028');

        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('getPaymentIntentId')
            ->willReturnCallback(fn () => $this->additionalInformation['paymongo_payment_intent_id'] ?? null);

        $this->observer = $this->observer($this->createStub(PaidCancelledOrder::class));
    }

    /**
     * @param PaidCancelledOrder $paidCancelledOrder
     * @return ReleaseHoldOnCancel
     */
    private function observer(PaidCancelledOrder $paidCancelledOrder): ReleaseHoldOnCancel
    {
        return new ReleaseHoldOnCancel(
            $this->paymongoClient,
            $this->paymentCapture,
            $this->paymentConfirmation,
            $this->captureMode,
            $this->adminNotice,
            $this->createStub(Logger::class),
            $paidCancelledOrder,
            new \PayMongo\Payment\Model\Order\CheckoutStart(
                $this->paymentConfirmation,
                $this->paymongoClient,
                $this->createStub(\Magento\Framework\Lock\LockManagerInterface::class),
                $this->createStub(\Magento\Sales\Model\OrderFactory::class),
                $this->createStub(Logger::class),
                $this->createStub(CaptureMode::class),
                $this->createStub(\Magento\Sales\Api\OrderRepositoryInterface::class),
                $this->createStub(\Magento\Payment\Helper\Data::class),
                $this->createStub(\PayMongo\Payment\Model\Checkout\EnabledMethodTypes::class)
            )
        );
    }

    /**
     * The reported bug: a held card is released on PayMongo, and the order says so.
     *
     * @return void
     */
    public function testHeldCardIsReleased(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->with(self::INTENT_ID, 1)
            ->willReturn($this->intentCheck('awaiting_capture'));
        $this->paymongoClient->expects($this->once())->method('cancelPaymentIntent')->with(self::INTENT_ID, 1);
        $this->expectComment('released on PayMongo');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * A Pending Payment order with only a checkout session stored is looked up through the
     * session, since its customer may have authorised the card without Magento hearing of it.
     *
     * @return void
     */
    public function testHoldIsFoundThroughTheCheckoutSession(): void
    {
        $this->additionalInformation = ['paymongo_checkout_session_id' => self::SESSION_ID];
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with(self::SESSION_ID, 1)
            ->willReturn($this->intentCheck('awaiting_capture'));
        $this->paymongoClient->expects($this->once())->method('cancelPaymentIntent')->with(self::INTENT_ID, 1);
        $this->expectComment('released on PayMongo');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * A payment that is not held (never authorised, failed, or already captured) is left alone.
     *
     * @return void
     */
    public function testPaymentThatIsNotHeldIsLeftAlone(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn($this->intentCheck('awaiting_payment_method'));
        $this->paymongoClient->expects($this->never())->method('cancelPaymentIntent');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * A cancelled order PayMongo already charged (a capture that could not be confirmed or
     * invoiced at the time) is not cancelled silently: the admin gets the persistent alert,
     * which also notes the order, and an on-screen error (PYM-32).
     *
     * @return void
     */
    public function testOrderAlreadyChargedIsFlaggedForARefund(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn($this->intentCheck('succeeded'));
        $this->paymongoClient->expects($this->never())->method('cancelPaymentIntent');
        $paidCancelledOrder = $this->createMock(PaidCancelledOrder::class);
        $paidCancelledOrder->expects($this->once())->method('alert')->with($this->order, self::INTENT_ID)
            ->willReturn(true);
        $this->observer = $this->observer($paidCancelledOrder);
        // The alert notes the order; the observer adds no comment of its own.
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'Refund the customer'))
        );

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * The reported gap: the customer completed the card authorisation on an earlier PayMongo page,
     * so the order's intent is that page's while its session is the newest one's. The stored
     * intent is asked about directly, and its hold is released.
     *
     * @return void
     */
    public function testHoldOnAnEarlierPagesIntentIsReleased(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->additionalInformation['paymongo_checkout_session_id'] = self::SESSION_ID;
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->willReturn(['payment_intent_id' => 'pi_newest_page'] + $this->intentCheck('awaiting_payment_method'));
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->with(self::INTENT_ID, 1)
            ->willReturn($this->intentCheck('awaiting_capture'));
        $this->paymongoClient->expects($this->once())->method('cancelPaymentIntent')->with(self::INTENT_ID, 1);
        $this->expectComment('released on PayMongo');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * A charge already reported (the alert raised before, by a webhook say) gets no second
     * on-screen error.
     *
     * @return void
     */
    public function testAlreadyReportedChargeGetsNoSecondNotice(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn($this->intentCheck('succeeded'));
        $this->paymongoClient->expects($this->never())->method('cancelPaymentIntent');
        $paidCancelledOrder = $this->createMock(PaidCancelledOrder::class);
        $paidCancelledOrder->expects($this->once())->method('alert')->willReturn(false);
        $this->observer = $this->observer($paidCancelledOrder);
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * An order with a checkout session is checked through CheckoutStart, which asks PayMongo once
     * for the whole cancellation, shared with the Failed controller and CloseCheckoutOnCancel
     * (PYM-33). The held card is released as before.
     *
     * @return void
     */
    public function testHoldIsCheckedThroughTheSharedSessionLookup(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->additionalInformation['paymongo_checkout_session_id'] = self::SESSION_ID;
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with(self::SESSION_ID, 1)
            ->willReturn($this->intentCheck('awaiting_capture') + ['status' => 'active']);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymongoClient->expects($this->once())->method('cancelPaymentIntent')->with(self::INTENT_ID, 1);
        $this->expectComment('released on PayMongo');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * An order charged at checkout never had a hold, so PayMongo is not asked at all.
     *
     * @return void
     */
    public function testOrderChargedAtCheckoutMakesNoApiCall(): void
    {
        $this->givenAuthorizeOnly(false);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymongoClient->expects($this->never())->method('cancelPaymentIntent');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->adminNotice->expects($this->never())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * A failed release does not stop the cancellation; the order says the funds may stay
     * held, and the admin is told.
     *
     * @return void
     */
    public function testFailedReleaseIsRecordedAndReported(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn($this->intentCheck('awaiting_capture'));
        $this->paymongoClient->expects($this->once())->method('cancelPaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->expectComment('could not be released');
        $this->adminNotice->expects($this->once())->method('error');

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * When PayMongo cannot be asked, the order says only that the hold could not be checked:
     * most orders cancelled this way, such as abandoned Pending Payment ones, never had one.
     *
     * @return void
     */
    public function testStatusLookupFailureIsRecordedAndReported(): void
    {
        $this->givenAuthorizeOnly(true);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));
        $this->paymongoClient->expects($this->never())->method('cancelPaymentIntent');
        $this->expectComment('could not be asked whether');
        $this->adminNotice->expects($this->once())->method('error')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'could not be checked'))
        );

        $this->observer->execute($this->cancelObserver());
    }

    /**
     * @param bool $authorizeOnly
     * @return void
     */
    private function givenAuthorizeOnly(bool $authorizeOnly): void
    {
        $this->captureMode->expects($this->once())->method('isAuthorizeOnly')->willReturn($authorizeOnly);
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
     * @param string $piStatus
     * @return array
     */
    private function intentCheck(string $piStatus): array
    {
        return [
            'confirmed' => in_array($piStatus, ['succeeded', 'awaiting_capture'], true),
            'authorized' => $piStatus === 'awaiting_capture',
            'status' => $piStatus,
            'payment_intent_id' => self::INTENT_ID,
            'payment_id' => null,
            'pi_status' => $piStatus,
        ];
    }

    /**
     * @return Observer
     */
    private function cancelObserver(): Observer
    {
        return new Observer(['event' => new Event(['order' => $this->order])]);
    }
}
