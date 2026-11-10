<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin alert for a payment PayMongo took for a cancelled order (PYM-32).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaidCancelledOrderTest extends TestCase
{
    /**
     * The admin gets a critical System Message and the order a comment, naming the payment.
     *
     * @return void
     */
    public function testAlertsTheAdminAndNotesTheOrder(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addCritical')->with(
            $this->stringContains('cancelled order #000000032'),
            $this->stringContains('pay_32')
        );
        $order = $this->order($info);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            static fn ($comment): bool => str_contains((string) $comment, 'ACTION NEEDED')
                && str_contains((string) $comment, 'pay_32')
        ));

        $alert = $this->service($notifier);
        $this->assertTrue($alert->alert($order, 'pay_32'));
        $this->assertSame('pi_32', $info[PaidCancelledOrder::ALERT_KEY]);
    }

    /**
     * The webhook's two events and the customer's return report the same payment, some by its
     * payment ID and some by its payment intent only: one alert per payment intent.
     *
     * @return void
     */
    public function testAlertsOncePerPayment(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32', PaidCancelledOrder::ALERT_KEY => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addCritical');
        $order = $this->order($info);
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $alert = $this->service($notifier);
        $this->assertFalse($alert->alert($order, 'pay_32'));
    }

    /**
     * Without a payment ID, the payment intent identifies the payment.
     *
     * @return void
     */
    public function testFallsBackToThePaymentIntent(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addCritical')
            ->with($this->anything(), $this->stringContains('pi_32'));

        $order = $this->order($info);
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static fn ($comment): bool => str_contains((string) $comment, 'pi_32')));

        ($this->service($notifier))->alert($order, null);

        $this->assertSame('pi_32', $info[PaidCancelledOrder::ALERT_KEY]);
    }

    /**
     * A card held for a cancelled order gets an alert worded for a hold, not a charge.
     *
     * @return void
     */
    public function testAHoldIsReportedAsAHold(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addCritical')
            ->with($this->stringContains('card hold'), $this->stringContains('holding the customer\'s card'));
        $order = $this->order($info);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            static fn ($comment): bool => str_contains((string) $comment, 'release the hold')
        ));

        ($this->service($notifier))->alert($order, null, true);
    }

    /**
     * When the caller cannot tell, PayMongo is asked: an Authorize Only order's payment can be a
     * hold or a capture, and only a hold is released rather than refunded.
     *
     * @return void
     */
    public function testAsksPayMongoWhetherTheCardIsHeld(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addCritical')->with($this->stringContains('card hold'));
        $order = $this->order($info);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $paymentConfirmation->expects($this->once())->method('checkPaymentIntent')->with('pi_32', 1)
            ->willReturn(['authorized' => true, 'pi_status' => 'awaiting_capture']);

        $this->service($notifier, $paymentConfirmation)->alert($order, 'pay_32');
    }

    /**
     * The reported false alarm: a hold ReleaseHoldOnCancel already released (the intent is
     * cancelled) took nothing, so a late report of it raises no alert, and a real charge on
     * the same checkout later still can.
     *
     * @return void
     */
    public function testReleasedHoldRaisesNoAlert(): void
    {
        $info = ['paymongo_payment_intent_id' => 'pi_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addCritical');
        $order = $this->order($info);
        $order->expects($this->never())->method('addCommentToStatusHistory');
        $paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn(['authorized' => false, 'pi_status' => 'cancelled']);

        $this->assertFalse($this->service($notifier, $paymentConfirmation)->alert($order, 'pay_32'));
        $this->assertArrayNotHasKey(PaidCancelledOrder::ALERT_KEY, $info);
    }

    /**
     * Every report of a checkout's payment, whatever ID it knows, raises one alert: the alert is
     * keyed by the checkout session, which every order paid through PayMongo has.
     *
     * @return void
     */
    public function testAlertsOncePerCheckoutSession(): void
    {
        $info = ['paymongo_checkout_session_id' => 'cs_32'];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addCritical');
        $order = $this->order($info);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $alert = $this->service($notifier);

        $this->assertTrue($alert->alert($order, null, false));
        $this->assertFalse($alert->alert($order, 'pay_32', false));
        $this->assertSame('cs_32', $info[PaidCancelledOrder::ALERT_KEY]);
    }

    /**
     * @param NotifierInterface $notifier
     * @param PaymentConfirmation|null $paymentConfirmation
     * @return PaidCancelledOrder
     */
    private function service(
        NotifierInterface $notifier,
        ?PaymentConfirmation $paymentConfirmation = null
    ): PaidCancelledOrder {
        if ($paymentConfirmation === null) {
            $paymentConfirmation = $this->createStub(PaymentConfirmation::class);
            $paymentConfirmation->method('checkPaymentIntent')
                ->willReturn(['authorized' => false, 'pi_status' => 'succeeded']);
        }

        return new PaidCancelledOrder($notifier, $paymentConfirmation, $this->createStub(Logger::class));
    }

    /**
     * @param array<string, string> $info Additional information, written to by reference
     * @return Order&\PHPUnit\Framework\MockObject\MockObject
     */
    private function order(array &$info): Order
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static function (string $key) use (&$info) {
                return $info[$key] ?? null;
            });
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(static function (string $key, $value) use (&$info, $payment) {
                $info[$key] = $value;
                return $payment;
            });

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000032');
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }
}
