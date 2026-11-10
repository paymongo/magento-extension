<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers which Authorize Only orders PayMongo should still be holding a card for, which
 * ones the "Capture from PayMongo" button can act on, and the capture made when an
 * invoice is created with Capture Online (PYM-28).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentCaptureTest extends TestCase
{
    private const INTENT_ID = 'pi_28';
    private const PAYMENT_ID = 'pay_31';

    /** @var PaymongoClient&MockObject */
    private PaymongoClient $paymongoClient;

    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var bool */
    private bool $authorizeOnly = true;

    /** @var Order|null What the order factory returns: the order as stored in the database */
    private ?Order $databaseCopy = null;

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        // Only the API lookup is replaced: picking the paid payment out of PayMongo's response is real.
        $this->paymentConfirmation = $this->createPartialMock(PaymentConfirmation::class, ['checkPaymentIntent']);

        $captureMode = $this->createStub(CaptureMode::class);
        $captureMode->method('isAuthorizeOnly')->willReturnCallback(fn () => $this->authorizeOnly);

        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturnCallback(fn () => $this->databaseCopy);

        $this->paymentCapture = new PaymentCapture(
            $this->paymongoClient,
            $this->paymentConfirmation,
            $captureMode,
            $this->createStub(InvoiceService::class),
            $this->createStub(TransactionFactory::class),
            $orderFactory,
            $this->createStub(LockManagerInterface::class),
            $this->createStub(AuditLogger::class),
            $this->createStub(Logger::class)
        );
    }

    /**
     * @param string $state
     * @param string|null $result
     * @param string|null $status
     * @param float $invoiced
     * @param bool $expected
     * @return void
     */
    #[DataProvider('awaitingCaptureProvider')]
    public function testIsAwaitingCapture(
        string $state,
        ?string $result,
        ?string $status,
        float $invoiced,
        bool $expected
    ): void {
        $order = $this->order($state, $result, $status, $invoiced);
        $this->expectNoPayMongoCalls();

        $this->assertSame($expected, $this->paymentCapture->isAwaitingCapture($order));
    }

    /**
     * @return array<string, array{string, ?string, ?string, float, bool}>
     */
    public static function awaitingCaptureProvider(): array
    {
        return [
            'authorised, not captured yet'             => [Order::STATE_PROCESSING, null, null, 0.0, true],
            'on hold after a failed capture, held'     => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_NOT_CAPTURED, 'awaiting_capture', 0.0, true,
            ],
            'failed capture found the hold cancelled'  => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_NOT_CAPTURED, 'cancelled', 0.0, false,
            ],
            'capture unconfirmed'                      => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_UNCONFIRMED, null, 0.0, false,
            ],
            'captured but not invoiced'                => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, 'succeeded', 0.0, false,
            ],
            'invoiced'                                 => [Order::STATE_PROCESSING, null, null, 1500.0, false],
            'pending payment: no confirmed hold yet'   => [Order::STATE_PENDING_PAYMENT, null, null, 0.0, false],
            'cancelled'                                => [Order::STATE_CANCELED, null, null, 0.0, false],
            'complete'                                 => [Order::STATE_COMPLETE, null, null, 0.0, false],
        ];
    }

    /**
     * An order charged at checkout (Authorize & Capture) never had a hold.
     *
     * @return void
     */
    public function testOrderChargedAtCheckoutIsNotAwaitingCapture(): void
    {
        $this->authorizeOnly = false;
        $this->expectNoPayMongoCalls();

        $this->assertFalse($this->paymentCapture->isAwaitingCapture($this->order(Order::STATE_PROCESSING)));
    }

    /**
     * @param string $state
     * @param string|null $result
     * @param float $invoiced
     * @param float $shipped
     * @param bool $expected
     * @return void
     */
    #[DataProvider('canRetryProvider')]
    public function testCanRetry(string $state, ?string $result, float $invoiced, float $shipped, bool $expected): void
    {
        $order = $this->order($state, $result, null, $invoiced, $shipped);
        $this->expectNoPayMongoCalls();

        $this->assertSame($expected, $this->paymentCapture->canRetry($order));
    }

    /**
     * @return array<string, array{string, ?string, float, float, bool}>
     */
    public static function canRetryProvider(): array
    {
        return [
            'failed capture'                        => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_NOT_CAPTURED, 0.0, 1.0, true,
            ],
            'unconfirmed capture'                   => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_UNCONFIRMED, 0.0, 1.0, true,
            ],
            'captured but not invoiced'             => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, 0.0, 1.0, true,
            ],
            'shipped with no capture recorded'      => [Order::STATE_PROCESSING, null, 0.0, 1.0, true],
            'not shipped, no capture attempted'     => [Order::STATE_PROCESSING, null, 0.0, 0.0, false],
            'captured'                              => [
                Order::STATE_PROCESSING, PaymentCapture::RESULT_CAPTURED, 0.0, 1.0, false,
            ],
            'invoiced'                              => [
                Order::STATE_HOLDED, PaymentCapture::RESULT_NOT_CAPTURED, 1500.0, 1.0, false,
            ],
            'cancelled'                             => [
                Order::STATE_CANCELED, PaymentCapture::RESULT_NOT_CAPTURED, 0.0, 1.0, false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stored Additional information of the order as stored
     * @param float $invoiced
     * @param bool $expected
     * @return void
     */
    #[DataProvider('captureRecordedProvider')]
    public function testIsCaptureRecordedReadsTheDatabase(array $stored, float $invoiced, bool $expected): void
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static fn (string $key) => $stored[$key] ?? null);
        $copy = $this->createStub(Order::class);
        $copy->method('load')->willReturnSelf();
        $copy->method('getId')->willReturn(28);
        $copy->method('getPayment')->willReturn($payment);
        $copy->method('getBaseTotalInvoiced')->willReturn($invoiced);
        $this->databaseCopy = $copy;
        $this->expectNoPayMongoCalls();

        // The order object in hand still looks untouched; only the database copy has changed.
        $this->assertSame($expected, $this->paymentCapture->isCaptureRecorded($this->order(Order::STATE_PROCESSING)));
    }

    /**
     * @return array<string, array{array<string, mixed>, float, bool}>
     */
    public static function captureRecordedProvider(): array
    {
        return [
            'nothing recorded'                   => [[], 0.0, false],
            'captured by another shipment'       => [
                [PaymentCapture::RESULT_KEY => PaymentCapture::RESULT_CAPTURED], 0.0, true,
            ],
            'failed capture by another shipment' => [
                [PaymentCapture::RESULT_KEY => PaymentCapture::RESULT_NOT_CAPTURED], 0.0, true,
            ],
            'invoiced meanwhile'                 => [[], 1500.0, true],
        ];
    }

    /**
     * Capture Online on an Authorize Only order takes the held money and records it, with
     * the PayMongo payment the invoice's refund goes against (PYM-31).
     *
     * @return void
     */
    public function testCaptureOnInvoiceCapturesTheHold(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $info);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->with(self::INTENT_ID, 150000, 1)
            ->willReturn(['data' => ['attributes' => [
                'status'   => 'succeeded',
                'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
            ]]]);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');

        // The database copy is marked "captured but not invoiced" straight away, so a failed
        // invoice save still leaves a record that the customer was charged.
        $storedInfo = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $storedPayment = $this->createMock(Payment::class);
        $storedPayment->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, $value) use (&$storedInfo, $storedPayment) {
                $storedInfo[$key] = $value;
                return $storedPayment;
            });
        $storedPayment->expects($this->once())->method('save');
        $stored = $this->createStub(Order::class);
        $stored->method('load')->willReturnSelf();
        $stored->method('getId')->willReturn(28);
        $stored->method('getPayment')->willReturn($storedPayment);
        $this->databaseCopy = $stored;

        $this->paymentCapture->captureOnInvoice($order, 1500.0);

        $this->assertSame(PaymentCapture::RESULT_CAPTURED_NOT_INVOICED, $storedInfo[PaymentCapture::RESULT_KEY]);
        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $info[PaymentCapture::RESULT_KEY]);
        $this->assertSame(self::PAYMENT_ID, $info['paymongo_payment_id'] ?? null);
        $this->assertSame(self::PAYMENT_ID, $storedInfo['paymongo_payment_id'] ?? null);
    }

    /**
     * Only a payment PayMongo marks paid is stored, and nothing is stored when the capture
     * did not take the money, so a refund is never sent against a failed payment.
     *
     * @return void
     */
    public function testCaptureStoresOnlyAPaidPayment(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $info);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn(['data' => ['attributes' => ['payments' => [
                ['id' => 'pay_failed', 'attributes' => ['status' => 'failed']],
                ['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']],
            ]]]]);

        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');

        $this->paymentCapture->capture($order, self::INTENT_ID);

        $this->assertSame(self::PAYMENT_ID, $info['paymongo_payment_id'] ?? null);
    }

    /**
     * A stored payment ID is used as it is, without asking PayMongo.
     *
     * @return void
     */
    public function testFindPaymentIdUsesTheStoredPayment(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID, 'paymongo_payment_id' => self::PAYMENT_ID];
        $order = $this->order(Order::STATE_COMPLETE, null, null, 1500.0, 1.0, $info);
        $this->expectNoPayMongoCalls();

        $this->assertSame(self::PAYMENT_ID, $this->paymentCapture->findPaymentId($order));
    }

    /**
     * Without a stored ID, the intent's paid payment is looked up and stored, so a refund
     * goes to it rather than to a failed attempt listed before it.
     *
     * @return void
     */
    public function testFindPaymentIdLooksUpAndStoresThePaidPayment(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_COMPLETE, null, null, 1500.0, 1.0, $info);
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->with(self::INTENT_ID, 1)
            ->willReturn(['pi_status' => 'succeeded', 'payment_id' => self::PAYMENT_ID]);

        $this->assertSame(self::PAYMENT_ID, $this->paymentCapture->findPaymentId($order));
        $this->assertSame(self::PAYMENT_ID, $info['paymongo_payment_id'] ?? null);
    }

    /**
     * An order with no payment intent has nothing to look up.
     *
     * @return void
     */
    public function testFindPaymentIdWithoutAnIntentIsNull(): void
    {
        $info = [];
        $order = $this->order(Order::STATE_COMPLETE, null, null, 1500.0, 1.0, $info);
        $this->expectNoPayMongoCalls();

        $this->assertNull($this->paymentCapture->findPaymentId($order));
    }

    /**
     * A capture whose response names no paid payment looks it up once, so the invoice still
     * gets the payment as its transaction.
     *
     * @return void
     */
    public function testCaptureLooksUpAPaymentTheResponseDidNotName(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $info);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn(['data' => ['attributes' => ['status' => 'succeeded', 'payments' => []]]]);
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn(['pi_status' => 'succeeded', 'payment_id' => self::PAYMENT_ID]);

        $capture = $this->paymentCapture->capture($order, self::INTENT_ID);

        $this->assertSame(PaymentCapture::OUTCOME_CAPTURED, $capture['outcome']);
        $this->assertSame(self::PAYMENT_ID, $info['paymongo_payment_id'] ?? null);
    }

    /**
     * An intent already captured is asked about once: if it names no paid payment, it is not
     * asked again.
     *
     * @return void
     */
    public function testAlreadyCapturedIntentIsNotLookedUpTwice(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $info);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn(['pi_status' => 'succeeded', 'payment_id' => null]);

        $capture = $this->paymentCapture->capture($order, self::INTENT_ID);

        $this->assertSame(PaymentCapture::OUTCOME_ALREADY_CAPTURED, $capture['outcome']);
        $this->assertArrayNotHasKey('paymongo_payment_id', $info);
    }

    /**
     * Once PayMongo has taken the money, failing to store its payment ID never turns the
     * capture into a failed or unconfirmed one.
     *
     * @return void
     */
    public function testFailingToStoreThePaymentIdDoesNotFailTheCapture(): void
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $key) => $key === 'paymongo_payment_intent_id' ? self::INTENT_ID : null
        );
        $payment->method('setAdditionalInformation')
            ->willThrowException(new \RuntimeException('cannot write'));
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(1500.00);

        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willReturn(['data' => ['attributes' => [
                'payments' => [['id' => self::PAYMENT_ID, 'attributes' => ['status' => 'paid']]],
            ]]]);
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');

        $capture = $this->paymentCapture->capture($order, self::INTENT_ID);

        $this->assertSame(PaymentCapture::OUTCOME_CAPTURED, $capture['outcome']);
    }

    /**
     * A capture PayMongo refused leaves no payment ID behind, even if the intent names one.
     *
     * @return void
     */
    public function testFailedCaptureStoresNoPayment(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $info);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn(['pi_status' => 'awaiting_capture', 'payment_id' => self::PAYMENT_ID]);

        $capture = $this->paymentCapture->capture($order, self::INTENT_ID);

        $this->assertSame(PaymentCapture::OUTCOME_NOT_CAPTURED, $capture['outcome']);
        $this->assertArrayNotHasKey('paymongo_payment_id', $info);
    }

    /**
     * Capture Online on an order whose hold PayMongo dropped stops the invoice, so the
     * order is not marked paid with no money taken.
     *
     * @return void
     */
    public function testCaptureOnInvoiceStopsTheInvoiceWhenPayMongoDoesNotCapture(): void
    {
        $order = $this->order(Order::STATE_PROCESSING);
        $this->paymongoClient->expects($this->once())->method('capturePaymentIntent')
            ->willThrowException(new LocalizedException(__('PayMongo API error: resource_invalid_state')));
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willReturn(['pi_status' => 'cancelled', 'authorized' => false]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('the customer has not been charged');

        $this->paymentCapture->captureOnInvoice($order, 1500.0);
    }

    /**
     * Capture Online on an order PayMongo already captured (a shipment captured it but the
     * invoice failed) creates the invoice without capturing again. Only the missing payment
     * ID is looked up, and an unreachable PayMongo cannot block the invoice.
     *
     * @return void
     */
    public function testCaptureOnInvoiceDoesNotRecaptureAKnownCapture(): void
    {
        $info = ['paymongo_payment_intent_id' => self::INTENT_ID];
        $order = $this->order(
            Order::STATE_HOLDED,
            PaymentCapture::RESULT_CAPTURED_NOT_INVOICED,
            'succeeded',
            0.0,
            1.0,
            $info
        );
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->once())->method('checkPaymentIntent')
            ->willThrowException(new LocalizedException(__('Connection timed out')));

        $this->paymentCapture->captureOnInvoice($order, 1500.0);

        $this->assertSame(PaymentCapture::RESULT_CAPTURED, $info[PaymentCapture::RESULT_KEY]);
        $this->assertArrayNotHasKey('paymongo_payment_id', $info);
    }

    /**
     * An order paid at checkout, or already captured, is left to the method as before.
     *
     * @return void
     */
    public function testCaptureOnInvoiceLeavesOtherOrdersAlone(): void
    {
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');

        $this->paymentCapture->captureOnInvoice(
            $this->order(Order::STATE_PROCESSING, PaymentCapture::RESULT_CAPTURED),
            1500.0
        );
        // An order paid at checkout may still be invoiced in parts.
        $this->authorizeOnly = false;
        $this->paymentCapture->captureOnInvoice($this->order(Order::STATE_PROCESSING), 500.0);
    }

    /**
     * Capture Online for part of an order on a card hold is refused before PayMongo is
     * asked, so the customer is not charged the whole order for a part of it. That includes
     * the rest of an order part-invoiced before 2.0.1, which the register check lets through.
     *
     * @param float $invoiced
     * @param float $baseAmount
     * @return void
     */
    #[DataProvider('partialCaptureProvider')]
    public function testCaptureOnInvoiceRefusesAPartialInvoiceBeforeAskingPayMongo(
        float $invoiced,
        float $baseAmount
    ): void {
        $this->expectNoPayMongoCalls();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This invoice is for only part of order #000000028');

        $this->paymentCapture->captureOnInvoice(
            $this->order(Order::STATE_PROCESSING, null, null, $invoiced),
            $baseAmount
        );
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function partialCaptureProvider(): array
    {
        return [
            'part of the order'             => [0.0, 500.0],
            'rest of a part-invoiced order' => [500.0, 1000.0],
        ];
    }

    /**
     * While the card hold can still be captured, only an invoice for the whole order, to
     * the centavo, is allowed. Once PayMongo has taken the money or dropped the hold, any
     * invoice is, so a charged order can always be invoiced; so is the rest of an order
     * part-invoiced before this check existed. It never calls PayMongo.
     *
     * @param float $baseAmount
     * @param float $invoiced
     * @param string|null $result
     * @param string|null $status
     * @param bool $allowed
     * @return void
     */
    #[DataProvider('invoiceAmountProvider')]
    public function testAssertFullInvoice(
        float $baseAmount,
        float $invoiced,
        ?string $result,
        ?string $status,
        bool $allowed
    ): void {
        $this->expectNoPayMongoCalls();

        if (!$allowed) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage('Order #000000028 was paid by card with Authorize Only');
        }

        $this->paymentCapture->assertFullInvoice(
            $this->order(Order::STATE_PROCESSING, $result, $status, $invoiced),
            $baseAmount
        );
    }

    /**
     * @return array<string, array{float, float, ?string, ?string, bool}>
     */
    public static function invoiceAmountProvider(): array
    {
        $notCaptured = PaymentCapture::RESULT_NOT_CAPTURED;
        $capturedNotInvoiced = PaymentCapture::RESULT_CAPTURED_NOT_INVOICED;

        return [
            'whole order'                          => [1500.0, 0.0, null, null, true],
            'whole order, to the centavo'          => [1500.004, 0.0, null, null, true],
            'part of the order'                    => [500.0, 0.0, null, null, false],
            'a centavo more than the order'        => [1500.01, 0.0, null, null, false],
            'part, capture unconfirmed'            => [500.0, 0.0, PaymentCapture::RESULT_UNCONFIRMED, null, false],
            'part, capture failed but still held'  => [500.0, 0.0, $notCaptured, 'awaiting_capture', false],
            'part, hold gone'                      => [500.0, 0.0, $notCaptured, 'cancelled', true],
            'a centavo off, already captured'      => [1499.99, 0.0, $capturedNotInvoiced, 'succeeded', true],
            'part, already captured'               => [500.0, 0.0, PaymentCapture::RESULT_CAPTURED, 'succeeded', true],
            'rest of a part-invoiced order'        => [1000.0, 500.0, null, null, true],
        ];
    }

    /**
     * Orders without a card hold, or without a PayMongo payment intent, may be invoiced in parts.
     *
     * @return void
     */
    public function testAssertFullInvoiceAllowsPartsWithoutACardHold(): void
    {
        $this->expectNoPayMongoCalls();

        $this->authorizeOnly = false;
        $this->paymentCapture->assertFullInvoice($this->order(Order::STATE_PROCESSING), 500.0);

        $this->authorizeOnly = true;
        $noIntent = [];
        $this->paymentCapture->assertFullInvoice(
            $this->order(Order::STATE_PROCESSING, null, null, 0.0, 0.0, $noIntent),
            500.0
        );
    }

    /**
     * These checks run on every admin order page, so they must never call PayMongo.
     *
     * @return void
     */
    private function expectNoPayMongoCalls(): void
    {
        $this->paymongoClient->expects($this->never())->method('capturePaymentIntent');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
    }

    /**
     * @param string $state
     * @param string|null $result
     * @param string|null $status
     * @param float $invoiced
     * @param float $shipped
     * @param array<string, mixed>|null $info Additional information, written to by reference
     * @return Order
     */
    private function order(
        string $state,
        ?string $result = null,
        ?string $status = null,
        float $invoiced = 0.0,
        float $shipped = 0.0,
        ?array &$info = null
    ): Order {
        $info ??= ['paymongo_payment_intent_id' => self::INTENT_ID];
        if ($result !== null) {
            $info[PaymentCapture::RESULT_KEY] = $result;
            $info[PaymentCapture::STATUS_KEY] = $status;
        }

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

        $item = $this->createStub(Item::class);
        $item->method('getQtyShipped')->willReturn($shipped);

        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalInvoiced')->willReturn($invoiced);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getAllItems')->willReturn([$item]);
        $order->method('getIncrementId')->willReturn('000000028');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(1500.00);
        $order->method('getBaseGrandTotal')->willReturn(1500.00);

        return $order;
    }
}
