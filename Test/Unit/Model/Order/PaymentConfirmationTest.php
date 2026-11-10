<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Covers PaymentConfirmation, the "has this checkout actually been paid?" check shared by
 * the success page, the admin Refresh button and reconciliation. The key case is PYM-27:
 * a checkout session that is still "active" on PayMongo must never count as paid.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentConfirmationTest extends TestCase
{
    /** @var PaymongoClient&Stub */
    private PaymongoClient $client;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->client = $this->createStub(PaymongoClient::class);
        $this->paymentConfirmation = new PaymentConfirmation($this->client);
    }

    /**
     * @param string|null $sessionStatus
     * @param string|null $paymentId
     * @param string|null $piStatus
     * @param bool $expected
     * @return void
     */
    #[DataProvider('sessionStates')]
    public function testIsCheckoutSessionPaid(
        ?string $sessionStatus,
        ?string $paymentId,
        ?string $piStatus,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            PaymentConfirmation::isCheckoutSessionPaid($sessionStatus, $paymentId, $piStatus)
        );
    }

    /**
     * @return array<string, array{?string, ?string, ?string, bool}>
     */
    public static function sessionStates(): array
    {
        return [
            'active, waiting for the customer' => ['active', null, 'awaiting_payment_method', false],
            'active, intent not resolved' => ['active', null, null, false],
            'active, payment being processed' => ['active', null, 'processing', false],
            'expired' => ['expired', null, 'awaiting_payment_method', false],
            'paid' => ['paid', 'pay_1', 'succeeded', true],
            'paid, payments not listed yet' => ['paid', null, null, true],
            'active, but a paid payment exists' => ['active', 'pay_1', null, true],
            'active, intent succeeded' => ['active', null, 'succeeded', true],
            'card held for capture' => ['active', null, 'awaiting_capture', true],
        ];
    }

    /**
     * The QA case from PYM-27: an admin refreshes an order whose customer has not paid yet.
     *
     * @return void
     */
    public function testAnActiveCheckoutSessionIsNotConfirmed(): void
    {
        $this->client->method('retrieveCheckoutSession')->willReturnMap([
            ['cs_1', 3, ['data' => ['attributes' => [
                'status' => 'active',
                'payment_intent' => ['id' => 'pi_1'],
                'payments' => [],
            ]]]],
        ]);
        $this->client->method('retrievePaymentIntent')->willReturnMap([
            ['pi_1', 3, ['data' => ['attributes' => ['status' => 'awaiting_payment_method', 'payments' => []]]]],
        ]);

        $result = $this->paymentConfirmation->checkCheckoutSession('cs_1', 3);

        $this->assertFalse($result['confirmed']);
        $this->assertFalse($result['authorized']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('pi_1', $result['payment_intent_id']);
        $this->assertNull($result['payment_id']);
    }

    /**
     * @return void
     */
    public function testAPaidCheckoutSessionIsConfirmedWithItsPaymentId(): void
    {
        $this->client->method('retrieveCheckoutSession')->willReturn(
            ['data' => ['attributes' => [
                'status' => 'paid',
                'payment_intent' => ['id' => 'pi_1'],
                'payments' => [['id' => 'pay_1', 'attributes' => ['status' => 'paid']]],
            ]]]
        );
        $this->client->method('retrievePaymentIntent')->willReturn(
            ['data' => ['attributes' => ['status' => 'succeeded']]]
        );

        $result = $this->paymentConfirmation->checkCheckoutSession('cs_1', 1);

        $this->assertTrue($result['confirmed']);
        $this->assertFalse($result['authorized']);
        $this->assertSame('pay_1', $result['payment_id']);
    }

    /**
     * @return void
     */
    public function testAHeldCardIsConfirmedAsAuthorized(): void
    {
        $this->client->method('retrieveCheckoutSession')->willReturn(
            ['data' => ['attributes' => ['status' => 'active', 'payment_intent' => ['id' => 'pi_1']]]]
        );
        $this->client->method('retrievePaymentIntent')->willReturn(
            ['data' => ['attributes' => ['status' => 'awaiting_capture', 'payments' => []]]]
        );

        $result = $this->paymentConfirmation->checkCheckoutSession('cs_1', 1);

        $this->assertTrue($result['confirmed']);
        $this->assertTrue($result['authorized']);
        $this->assertNull($result['payment_id']);
    }

    /**
     * @return void
     */
    public function testAPaymentIntentIsConfirmedOnlyOnceSucceeded(): void
    {
        $this->client->method('retrievePaymentIntent')->willReturnOnConsecutiveCalls(
            ['data' => ['attributes' => ['status' => 'awaiting_next_action', 'payments' => []]]],
            ['data' => ['attributes' => [
                'status' => 'succeeded',
                'payments' => [['id' => 'pay_2', 'attributes' => ['status' => 'paid']]],
            ]]]
        );

        $pending = $this->paymentConfirmation->checkPaymentIntent('pi_2', 1);
        $this->assertFalse($pending['confirmed']);
        $this->assertSame('awaiting_next_action', $pending['status']);

        $paid = $this->paymentConfirmation->checkPaymentIntent('pi_2', 1);
        $this->assertTrue($paid['confirmed']);
        $this->assertSame('pay_2', $paid['payment_id']);
    }

    /**
     * Reconciliation checks a held card by its payment intent alone, so the intent check has
     * to recognise the hold too.
     *
     * @return void
     */
    public function testAHeldCardIntentIsConfirmedAsAuthorized(): void
    {
        $this->client->method('retrievePaymentIntent')->willReturn(
            ['data' => ['attributes' => ['status' => 'awaiting_capture', 'payments' => []]]]
        );

        $result = $this->paymentConfirmation->checkPaymentIntent('pi_3', 1);

        $this->assertTrue($result['confirmed']);
        $this->assertTrue($result['authorized']);
        $this->assertNull($result['payment_id']);
    }

    /**
     * @return void
     */
    public function testFindPaidPaymentIdSkipsUnpaidPayments(): void
    {
        $this->assertSame('pay_2', $this->paymentConfirmation->findPaidPaymentId([
            ['id' => 'pay_1', 'attributes' => ['status' => 'failed']],
            ['id' => 'pay_2', 'attributes' => ['status' => 'paid']],
        ]));
        $this->assertSame('pay_3', $this->paymentConfirmation->findPaidPaymentId([['id' => 'pay_3']]));
        $this->assertNull($this->paymentConfirmation->findPaidPaymentId([
            ['id' => 'pay_1', 'attributes' => ['status' => 'pending']],
        ]));
        $this->assertNull($this->paymentConfirmation->findPaidPaymentId([]));
    }
}
