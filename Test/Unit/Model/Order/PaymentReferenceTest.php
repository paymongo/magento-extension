<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers both directions of PaymentReference: the strings sent to PayMongo, and
 * resolving a reference_number or description from a webhook back to an increment ID.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentReferenceTest extends TestCase
{
    /** @var PaymentReference */
    private PaymentReference $paymentReference;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymentReference = new PaymentReference();
    }

    /**
     * @return void
     */
    public function testBuildersTagTheOrder(): void
    {
        $order = $this->createOrder('000000015');

        $this->assertSame('MGN-000000015', $this->paymentReference->build($order));
        $this->assertSame('Magento Order #MGN-000000015', $this->paymentReference->buildDescription($order));
        $this->assertSame(
            'Refund for Magento Order #MGN-000000015',
            $this->paymentReference->buildRefundNotes($order)
        );
    }

    /**
     * @return void
     */
    public function testBuildersWithoutAnOrderSendNothing(): void
    {
        foreach ([null, $this->createOrder('')] as $order) {
            $this->assertSame('', $this->paymentReference->build($order));
            $this->assertSame('', $this->paymentReference->buildDescription($order));
            $this->assertNull($this->paymentReference->buildRefundNotes($order));
        }
    }

    /**
     * What build() and buildDescription() send must resolve back to the same order.
     *
     * @return void
     */
    public function testRoundTrip(): void
    {
        foreach (['000000015', 'MGN-777', '2-000045'] as $incrementId) {
            $order = $this->createOrder($incrementId);

            $this->assertSame(
                $incrementId,
                $this->paymentReference->resolveIncrementId($this->paymentReference->build($order))
            );
            $this->assertSame(
                $incrementId,
                $this->paymentReference->resolveIncrementId($this->paymentReference->buildDescription($order))
            );
        }
    }

    /**
     * @param string $reference
     * @param string|null $expected
     * @return void
     */
    #[DataProvider('referenceProvider')]
    public function testResolveIncrementId(string $reference, ?string $expected): void
    {
        $this->assertSame($expected, $this->paymentReference->resolveIncrementId($reference));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function referenceProvider(): array
    {
        return [
            'description' => ['Magento Order #MGN-000000015', '000000015'],
            'reference_number' => ['MGN-000000015', '000000015'],
            'surrounding whitespace' => ['  Magento Order #MGN-000000015 ', '000000015'],
            'store whose IDs start with the prefix' => ['MGN-MGN-777', 'MGN-777'],
            'bare reference' => ['000000015', null],
            'pre-PYM-22 description' => ['Order #MGN-000000015', null],
            'pre-PYM-13 description' => ['Order #000000015', null],
            'other system, own label' => ['Shopify Order #000000015', null],
            'other system, our prefix' => ['Acme Order #MGN-000000015', null],
            'label not at the start' => ['Paid: Magento Order #MGN-000000015', null],
            'label without prefix' => ['Magento Order #000000015', null],
            'bare prefix' => ['MGN-', null],
            'empty' => ['', null],
        ];
    }

    /**
     * @param string $incrementId
     * @return OrderInterface
     */
    private function createOrder(string $incrementId): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }
}
