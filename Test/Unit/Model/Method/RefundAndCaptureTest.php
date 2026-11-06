<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Method;

use PayMongo\Payment\Gateway\PaymongoApiException;
use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Logger\AuditLogger;
use PayMongo\Payment\Model\Method\CaptureAndRefund;
use PayMongo\Payment\Model\Method\Card;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentReference;
use PayMongo\Payment\Model\PaymongoPayment;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the payment methods' online refund and Capture Online (PYM-31): a refund goes to
 * the paid PayMongo payment and records PayMongo's refund ID, and a card hold's capture is
 * audited once. The legacy all-in-one method is audited like the individual methods.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class RefundAndCaptureTest extends TestCase
{
    private const PAYMENT_ID = 'pay_31';
    private const REFUND_ID = 'ref_31';
    private const PAYOUT_ERROR = '{"errors":[{"code":"parameter_above_maximum",'
        . '"detail":"Refund amount is greater than the available payout amount."}]}';

    /** @var (PaymongoClient&MockObject)|null A test that checks PayMongo calls sets this; else a stub */
    private ?PaymongoClient $paymongoClient = null;

    /** @var (PaymentCapture&MockObject)|null */
    private ?PaymentCapture $paymentCapture = null;

    /** @var (AuditLogger&MockObject)|null */
    private ?AuditLogger $auditLogger = null;

    /** @var Order */
    private Order $order;

    /** @var array<string, mixed> What the payment's setters received */
    private array $set = [];

    /** @var ObjectManagerInterface|null The global object manager before the test */
    private ?ObjectManagerInterface $previousObjectManager = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000031');
        $this->order = $order;

        // Magento's AbstractMethod fetches its directory helper from the global object manager.
        $instance = new \ReflectionProperty(AppObjectManager::class, '_instance');
        $this->previousObjectManager = $instance->getValue();
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => $this->createStub($class));
        AppObjectManager::setInstance($objectManager);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        (new \ReflectionProperty(AppObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }

    /**
     * A refund goes to the payment PaymentCapture finds (the paid one, looked up if not
     * stored), and the refund transaction is named after PayMongo's refund, so a second
     * partial refund of the invoice does not reuse the first one's ID.
     *
     * @return void
     */
    public function testRefundGoesToThePaidPaymentAndRecordsPayMongosRefund(): void
    {
        $this->paymentCapture = $this->createMock(PaymentCapture::class);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->paymentCapture->expects($this->once())->method('findPaymentId')
            ->with($this->order)->willReturn(self::PAYMENT_ID);
        $this->paymongoClient->expects($this->once())->method('createRefund')
            ->with(self::PAYMENT_ID, 3700, 'requested_by_customer', $this->anything(), 1)
            ->willReturn(['data' => ['id' => self::REFUND_ID]]);
        $this->auditLogger->expects($this->once())->method('logRefund')
            ->with('000000031', 37.0, self::PAYMENT_ID, $this->anything());

        $this->card()->refund($this->payment($this->order), 37.0);

        $this->assertSame(self::REFUND_ID, $this->set['transaction_id'] ?? null);
        $this->assertTrue($this->set['is_transaction_closed'] ?? false);
    }

    /**
     * When PayMongo lists no paid payment, nothing is refunded and the admin is told why.
     *
     * @return void
     */
    public function testRefundWithoutAPaidPaymentIsRefused(): void
    {
        $this->paymentCapture = $this->createMock(PaymentCapture::class);
        $this->paymentCapture->expects($this->once())->method('findPaymentId')->willReturn(null);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymongoClient->expects($this->never())->method('createRefund');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('PayMongo payment ID not found');

        $this->card()->refund($this->payment($this->order), 37.0);
    }

    /**
     * A refund PayMongo refuses because the account's payout is too low is explained in
     * PayMongo's words, with what to do, instead of PayMongo's raw response. Nothing is recorded,
     * so Magento saves no credit memo and the admin can retry.
     *
     * @return void
     */
    public function testRefundRefusedForTheAvailablePayoutIsExplained(): void
    {
        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('findPaymentId')->willReturn(self::PAYMENT_ID);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymongoClient->expects($this->once())->method('createRefund')
            ->willThrowException(PaymongoApiException::fromResponse(self::PAYOUT_ERROR));
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->expects($this->never())->method('logRefund');

        $message = $this->refusedMessage(fn () => $this->card()->refund($this->payment($this->order), 37.0));

        $this->assertStringStartsWith(
            'PayMongo did not refund this payment: Refund amount is greater than the available payout amount. '
            . 'Nothing was refunded and no credit memo was created.',
            $message
        );
        $this->assertStringContainsString('Top up your PayMongo balance', $message);
        $this->assertStringNotContainsString('{', $message);
        $this->assertArrayNotHasKey('transaction_id', $this->set);
    }

    /**
     * Any other refusal is given in PayMongo's words, without the payout advice.
     *
     * @return void
     */
    public function testOtherRefundRefusalsUsePayMongosWords(): void
    {
        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('findPaymentId')->willReturn(self::PAYMENT_ID);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymongoClient->expects($this->once())->method('createRefund')->willThrowException(
            PaymongoApiException::fromResponse('{"errors":[{"code":"resource_invalid_state",'
                . '"detail":"The payment has already been fully refunded."}]}')
        );

        $message = $this->refusedMessage(fn () => $this->card()->refund($this->payment($this->order), 37.0));

        $this->assertSame(
            'PayMongo did not refund this payment: The payment has already been fully refunded. '
            . 'Nothing was refunded and no credit memo was created.',
            $message
        );
    }

    /**
     * The legacy all-in-one method explains a refused refund the same way.
     *
     * @return void
     */
    public function testLegacyMethodRefundRefusalIsExplained(): void
    {
        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('findPaymentId')->willReturn(self::PAYMENT_ID);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->paymongoClient->expects($this->once())->method('createRefund')
            ->willThrowException(PaymongoApiException::fromResponse(self::PAYOUT_ERROR));

        $message = $this->refusedMessage(fn () => $this->legacy()->refund($this->payment($this->order), 37.0));

        $this->assertStringContainsString('available payout amount', $message);
        $this->assertStringContainsString('Top up your PayMongo balance', $message);
    }

    /**
     * The legacy all-in-one method refunds the same way.
     *
     * @return void
     */
    public function testLegacyMethodRefundGoesToThePaidPaymentAndRecordsPayMongosRefund(): void
    {
        $this->paymentCapture = $this->createMock(PaymentCapture::class);
        $this->paymongoClient = $this->createMock(PaymongoClient::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->paymentCapture->expects($this->once())->method('findPaymentId')->willReturn(self::PAYMENT_ID);
        $this->paymongoClient->expects($this->once())->method('createRefund')
            ->with(self::PAYMENT_ID, 3700)
            ->willReturn(['data' => ['id' => self::REFUND_ID]]);
        // Before 2.0.1 the all-in-one method wrote no audit entry for a refund.
        $this->auditLogger->expects($this->once())->method('logRefund')
            ->with('000000031', 37.0, self::PAYMENT_ID, $this->anything());

        $this->legacy()->refund($this->payment($this->order), 37.0);

        $this->assertSame(self::REFUND_ID, $this->set['transaction_id'] ?? null);
    }

    /**
     * The legacy all-in-one method audits a payment made at checkout when its invoice is captured
     * online, with both the payment intent and the payment, like the individual methods.
     *
     * @return void
     */
    public function testLegacyMethodCaptureIsAudited(): void
    {
        $this->paymentCapture = $this->createMock(PaymentCapture::class);
        $this->paymentCapture->expects($this->once())->method('captureOnInvoice')->with($this->order, 37.0);
        $this->paymentCapture->method('getResult')->willReturn(null);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->expects($this->once())->method('logCapture')
            ->with('000000031', 37.0, 'pi_31', self::PAYMENT_ID);

        $this->legacy()->capture(
            $this->payment(
                $this->order,
                ['paymongo_payment_id' => self::PAYMENT_ID, 'paymongo_payment_intent_id' => 'pi_31']
            ),
            37.0
        );

        $this->assertSame(self::PAYMENT_ID, $this->set['transaction_id'] ?? null);
    }

    /**
     * A card hold's capture on the legacy method is audited once, by PaymentCapture.
     *
     * @return void
     */
    public function testLegacyMethodCardHoldIsNotAuditedTwice(): void
    {
        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('getResult')->willReturn(PaymentCapture::RESULT_CAPTURED);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->expects($this->never())->method('logCapture');

        $this->legacy()->capture($this->payment($this->order, ['paymongo_payment_id' => self::PAYMENT_ID]), 37.0);

        $this->assertSame(self::PAYMENT_ID, $this->set['transaction_id'] ?? null);
    }

    /**
     * A payment made at checkout is audited when its invoice is captured online.
     *
     * @return void
     */
    public function testCaptureOfACheckoutPaymentIsAudited(): void
    {
        $this->paymentCapture = $this->createMock(PaymentCapture::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->paymentCapture->expects($this->once())->method('captureOnInvoice')->with($this->order, 37.0);
        $this->paymentCapture->method('getResult')->willReturn(null);
        $this->auditLogger->expects($this->once())->method('logCapture')->with('000000031', 37.0, self::PAYMENT_ID);

        $this->card()->capture($this->payment($this->order, ['paymongo_payment_id' => self::PAYMENT_ID]), 37.0);

        $this->assertSame(self::PAYMENT_ID, $this->set['transaction_id'] ?? null);
    }

    /**
     * A card hold's capture is audited by PaymentCapture when PayMongo takes the money, so
     * its invoice's capture is not audited a second time.
     *
     * @return void
     */
    public function testCaptureOfACardHoldIsNotAuditedTwice(): void
    {
        $this->paymentCapture = $this->createStub(PaymentCapture::class);
        $this->paymentCapture->method('getResult')->willReturn(PaymentCapture::RESULT_CAPTURED);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->expects($this->never())->method('logCapture');

        $this->card()->capture($this->payment($this->order, ['paymongo_payment_id' => self::PAYMENT_ID]), 37.0);

        $this->assertSame(self::PAYMENT_ID, $this->set['transaction_id'] ?? null);
    }

    /**
     * The message of the LocalizedException a refund throws.
     *
     * @param callable $refund
     * @return string
     */
    private function refusedMessage(callable $refund): string
    {
        try {
            $refund();
        } catch (LocalizedException $e) {
            $this->assertInstanceOf(PaymongoApiException::class, $e->getPrevious());
            return $e->getMessage();
        }
        $this->fail('The refund was not refused.');
    }

    /**
     * @return Card
     */
    private function card(): Card
    {
        return new Card(
            ...$this->magentoArguments(),
            configHelper: $this->createStub(Config::class),
            captureAndRefund: $this->captureAndRefund()
        );
    }

    /**
     * @return PaymongoPayment
     */
    private function legacy(): PaymongoPayment
    {
        return new PaymongoPayment(
            ...$this->magentoArguments(),
            configHelper: $this->createStub(Config::class),
            captureAndRefund: $this->captureAndRefund()
        );
    }

    /**
     * The capture and refund every PayMongo method shares, around this test's mocks (PYM-33).
     *
     * @return CaptureAndRefund
     */
    private function captureAndRefund(): CaptureAndRefund
    {
        return new CaptureAndRefund(
            $this->paymongoClient ?? $this->createStub(PaymongoClient::class),
            $this->auditLogger ?? $this->createStub(AuditLogger::class),
            $this->createStub(PaymentReference::class),
            $this->paymentCapture ?? $this->createStub(PaymentCapture::class)
        );
    }

    /**
     * The constructor arguments every Magento payment method takes.
     *
     * @return array<string, object>
     */
    private function magentoArguments(): array
    {
        return [
            'context'                => $this->createStub(Context::class),
            'registry'               => $this->createStub(Registry::class),
            'extensionFactory'       => $this->createStub(ExtensionAttributesFactory::class),
            'customAttributeFactory' => $this->createStub(AttributeValueFactory::class),
            'paymentData'            => $this->createStub(PaymentHelper::class),
            'scopeConfig'            => $this->createStub(ScopeConfigInterface::class),
            'logger'                 => $this->createStub(Logger::class),
        ];
    }

    /**
     * A payment that records what its transaction setters receive.
     *
     * @param Order|null $order
     * @param array<string, mixed> $information Additional information
     * @return Payment
     */
    private function payment(?Order $order, array $information = []): Payment
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(static fn (string $key) => $information[$key] ?? null);
        $payment->method('setTransactionId')->willReturnCallback(function ($id) use ($payment) {
            $this->set['transaction_id'] = $id;
            return $payment;
        });
        $payment->method('setIsTransactionClosed')->willReturnCallback(function ($closed) use ($payment) {
            $this->set['is_transaction_closed'] = $closed;
            return $payment;
        });

        return $payment;
    }
}
