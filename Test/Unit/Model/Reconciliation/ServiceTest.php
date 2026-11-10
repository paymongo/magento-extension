<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Reconciliation;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use PayMongo\Payment\Model\Reconciliation\Service;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Covers the NOT_PAID_IN_PAYMONGO check: orders Magento treats as paid that PayMongo has
 * no payment for (PYM-27). The payment-driven checks are exercised only as far as this
 * needs; every test here passes no PayMongo payment that lacks a Magento order.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class ServiceTest extends TestCase
{
    /** @var PaymongoClient&Stub */
    private PaymongoClient $client;

    /** @var PaymentConfirmation&Stub */
    private PaymentConfirmation $paymentConfirmation;

    /** @var OrderCollectionFactory&Stub */
    private OrderCollectionFactory $collectionFactory;

    /** @var Service */
    private Service $service;

    /** @var array<int, array> where() calls made on the reverse check's order query */
    private array $whereCalls = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->client = $this->createStub(PaymongoClient::class);
        $this->client->method('getRefunds')->willReturn(['data' => []]);
        $this->paymentConfirmation = $this->createStub(PaymentConfirmation::class);
        $this->collectionFactory = $this->createStub(OrderCollectionFactory::class);

        $this->service = new Service(
            $this->client,
            $this->collectionFactory,
            $this->createStub(CaptureMode::class),
            $this->paymentConfirmation,
            $this->createStub(Logger::class)
        );
    }

    /**
     * @return void
     */
    public function testOrderMarkedPaidWithoutAPaymentIsFlagged(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([
            $this->createOrder('000000037', 504.0, [
                'paymongo_checkout_session_id' => 'cs_1',
                'paymongo_payment_intent_id' => 'pi_1',
            ]),
        ]);
        $this->paymentConfirmation->method('checkPaymentIntent')->willReturnMap([
            ['pi_1', 1, $this->confirmation(false, 'awaiting_payment_method')],
        ]);
        $this->paymentConfirmation->method('checkCheckoutSession')->willReturnCallback(
            fn () => $this->fail('With a payment intent stored, its checkout session must not be asked as well.')
        );

        $issues = $this->notPaidIssues();

        $this->assertCount(1, $issues);
        $this->assertSame('pi_1', $issues[0]['payment_id']);
        $this->assertSame('000000037', $issues[0]['order_increment']);
        $this->assertSame(504.0, $issues[0]['magento_amount']);
        $this->assertStringContainsString('status: awaiting_payment_method', $issues[0]['message']);
    }

    /**
     * @return void
     */
    public function testOrderWithOnlyACheckoutSessionIsCheckedBySession(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([
            $this->createOrder('000000042', 100.0, ['paymongo_checkout_session_id' => 'cs_5']),
        ]);
        $this->paymentConfirmation->method('checkCheckoutSession')->willReturnMap([
            ['cs_5', 1, $this->confirmation(false, 'active')],
        ]);

        $issues = $this->notPaidIssues();

        $this->assertCount(1, $issues);
        $this->assertSame('cs_5', $issues[0]['payment_id']);
        $this->assertStringContainsString('status: active', $issues[0]['message']);
    }

    /**
     * Past the lookup limit no more orders are checked, and a CHECK_INCOMPLETE row says how
     * many were left out, so a short list is not mistaken for a clean one.
     *
     * @return void
     */
    public function testLookupsStopAtTheLimitAndSayHowManyWereNotChecked(): void
    {
        $limit = (new \ReflectionClassConstant(Service::class, 'MAX_PAYMENT_LOOKUPS'))->getValue();
        $this->client->method('getPayments')->willReturn(['data' => []]);

        $orders = [];
        for ($i = 0; $i < $limit + 3; $i++) {
            $orders[] = $this->createOrder(sprintf('%09d', $i + 1), 100.0, ['paymongo_payment_intent_id' => 'pi_' . $i]);
        }
        $this->givenPaidOrders($orders);

        $lookups = 0;
        $this->paymentConfirmation->method('checkPaymentIntent')->willReturnCallback(
            function () use (&$lookups) {
                $lookups++;
                return $this->confirmation(true, 'succeeded');
            }
        );

        $result = $this->service->reconcile(new \DateTime('2026-10-15 00:00:00'), new \DateTime('2026-10-15 23:59:59'));
        $incomplete = array_values(array_filter(
            $result['discrepancies'],
            static fn (array $issue): bool => $issue['type'] === 'CHECK_INCOMPLETE'
        ));

        $this->assertSame($limit, $lookups);
        $this->assertCount(1, $incomplete);
        $this->assertStringContainsString('3 more were not checked', $incomplete[0]['message']);
    }

    /**
     * @return void
     */
    public function testNoLimitRowWhenEveryOrderWasChecked(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([
            $this->createOrder('000000043', 100.0, ['paymongo_payment_intent_id' => 'pi_6']),
        ]);
        $this->paymentConfirmation->method('checkPaymentIntent')->willReturn($this->confirmation(true, 'succeeded'));

        $result = $this->service->reconcile(new \DateTime('2026-10-15 00:00:00'), new \DateTime('2026-10-15 23:59:59'));

        $this->assertSame([], $result['discrepancies']);
    }

    /**
     * @return void
     */
    public function testOrdersAreLimitedToTheStoreWhenOneIsGiven(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([]);

        $this->service->reconcile(new \DateTime('2026-10-15 00:00:00'), new \DateTime('2026-10-15 23:59:59'), 2);

        $this->assertContains(['main_table.store_id = ?', 2], $this->whereCalls);
    }

    /**
     * @return void
     */
    public function testOrdersFromEveryStoreAreCheckedWhenNoStoreIsGiven(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([]);

        $this->service->reconcile(new \DateTime('2026-10-15 00:00:00'), new \DateTime('2026-10-15 23:59:59'));

        $this->assertNotContains('main_table.store_id = ?', array_column($this->whereCalls, 0));
    }

    /**
     * @return void
     */
    public function testOrderWhosePaymentIsListedCostsNoLookup(): void
    {
        $order = $this->createOrder('000000038', 100.0, [
            'paymongo_checkout_session_id' => 'cs_2',
            'paymongo_payment_id' => 'pay_2',
        ]);
        $this->client->method('getPayments')->willReturn(['data' => [
            ['id' => 'pay_2', 'attributes' => ['status' => 'paid', 'amount' => 10000]],
        ]]);
        $this->givenPaidOrders([$order], 1, $order);
        $this->paymentConfirmation->method('checkCheckoutSession')->willReturnCallback(
            fn () => $this->fail('A payment already in the PayMongo list must not be looked up again.')
        );

        $this->assertSame([], $this->notPaidIssues());
    }

    /**
     * A payment made just outside the window, or a card held for capture, is confirmed by
     * the direct lookup and not flagged.
     *
     * @return void
     */
    public function testOrderConfirmedByLookupIsNotFlagged(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([
            $this->createOrder('000000039', 100.0, ['paymongo_payment_intent_id' => 'pi_3']),
        ]);
        $this->paymentConfirmation->method('checkPaymentIntent')->willReturnMap([
            ['pi_3', 1, $this->confirmation(true, 'succeeded')],
        ]);

        $this->assertSame([], $this->notPaidIssues());
    }

    /**
     * @return void
     */
    public function testOrderPayMongoCannotBeAskedAboutIsSkipped(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([
            $this->createOrder('000000040', 100.0, ['paymongo_checkout_session_id' => 'cs_4']),
        ]);
        $this->paymentConfirmation->method('checkCheckoutSession')
            ->willThrowException(new \RuntimeException('timeout'));

        $this->assertSame([], $this->notPaidIssues());
    }

    /**
     * @return void
     */
    public function testOrderWithNoCheckoutIsFlagged(): void
    {
        $this->client->method('getPayments')->willReturn(['data' => []]);
        $this->givenPaidOrders([$this->createOrder('000000041', 100.0, [])]);

        $issues = $this->notPaidIssues();

        $this->assertCount(1, $issues);
        $this->assertSame('', $issues[0]['payment_id']);
        $this->assertStringContainsString('no PayMongo checkout', $issues[0]['message']);
    }

    /**
     * @return array
     */
    private function notPaidIssues(): array
    {
        $result = $this->service->reconcile(new \DateTime('2026-10-15 00:00:00'), new \DateTime('2026-10-15 23:59:59'));

        return array_values(array_filter(
            $result['discrepancies'],
            static fn (array $issue): bool => $issue['type'] === 'NOT_PAID_IN_PAYMONGO'
        ));
    }

    /**
     * Route collection creation. checkPayments() creates one collection per listed PayMongo
     * payment to look its order up by payment ID (answered with $matchedOrder), and the
     * reverse check creates the last one, holding $orders.
     *
     * @param Order[] $orders
     * @param int $listedPayments
     * @param Order|null $matchedOrder
     * @return void
     */
    private function givenPaidOrders(array $orders, int $listedPayments = 0, ?Order $matchedOrder = null): void
    {
        $lookup = $this->createCollection([]);
        $lookup->method('getFirstItem')->willReturn($matchedOrder ?? $this->createOrder('', 0.0, [], null));
        $paidOrders = $this->createCollection($orders, true);

        $calls = 0;
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$calls, $listedPayments, $lookup, $paidOrders) {
                return ++$calls <= $listedPayments ? $lookup : $paidOrders;
            }
        );
    }

    /**
     * @param Order[] $orders
     * @param bool $recordWhere Record where() calls in $whereCalls (the reverse check's query)
     * @return OrderCollection&Stub
     */
    private function createCollection(array $orders, bool $recordWhere = false): OrderCollection
    {
        $select = $this->createStub(Select::class);
        $select->method('join')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (...$args) use ($select, $recordWhere) {
                if ($recordWhere) {
                    // PHPUnit passes defaulted parameters too; keep the condition and its value.
                    $this->whereCalls[] = array_slice($args, 0, 2);
                }
                return $select;
            }
        );

        $resource = $this->createStub(OrderResource::class);
        $resource->method('getTable')->willReturnArgument(0);

        $collection = $this->createStub(OrderCollection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getResource')->willReturn($resource);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        return $collection;
    }

    /**
     * @param string $incrementId
     * @param float $grandTotal
     * @param array $additionalInformation
     * @param int|null $id
     * @return Order&Stub
     */
    private function createOrder(
        string $incrementId,
        float $grandTotal,
        array $additionalInformation,
        ?int $id = 1
    ): Order {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'paymongo_additional_information'
                ? json_encode($additionalInformation)
                : null
        );

        return $order;
    }

    /**
     * @param bool $confirmed
     * @param string $status
     * @return array
     */
    private function confirmation(bool $confirmed, string $status): array
    {
        return [
            'confirmed' => $confirmed,
            'authorized' => false,
            'status' => $status,
            'payment_intent_id' => null,
            'payment_id' => null,
            'pi_status' => null,
        ];
    }
}
