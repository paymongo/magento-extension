<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Resolver;

use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Resolver\CreateCheckoutSession;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers createPaymongoCheckoutSession opening the page for the order's own payment method,
 * never the one a storefront sends (PYM-33).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CreateCheckoutSessionTest extends TestCase
{
    /** @var CheckoutStart&MockObject */
    private CheckoutStart $checkoutStart;

    /** @var Order */
    private Order $order;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checkoutStart = $this->createMock(CheckoutStart::class);

        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn('paymongo_gcash');
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(33);
        $order->method('getPayment')->willReturn($payment);
        $this->order = $order;
    }

    /**
     * The reported hole: a storefront sending another method (a card, possibly Authorize Only)
     * for an order placed with GCash gets no page.
     *
     * @return void
     */
    public function testAnotherMethodThanTheOrdersIsRefused(): void
    {
        $this->checkoutStart->expects($this->never())->method('openPage');

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('method_code does not match the payment method of the order');

        $this->resolve('paymongo_card');
    }

    /**
     * The order's own method opens the page, which CheckoutStart builds from the order.
     *
     * @return void
     */
    public function testTheOrdersMethodOpensThePage(): void
    {
        $this->checkoutStart->expects($this->once())->method('openPage')->with($this->order)->willReturn([
            'session_id'        => 'cs_33',
            'checkout_url'      => 'https://checkout.paymongo.com/cs_33',
            'payment_intent_id' => 'pi_33',
        ]);

        $result = $this->resolve('paymongo_gcash');

        $this->assertSame('cs_33', $result['session_id']);
        $this->assertSame('https://checkout.paymongo.com/cs_33', $result['checkout_url']);
    }

    /**
     * @param string $methodCode
     * @return array
     */
    private function resolve(string $methodCode): array
    {
        $masked = $this->createStub(MaskedQuoteIdToQuoteIdInterface::class);
        $masked->method('execute')->willReturn(33);
        $carts = $this->createStub(CartRepositoryInterface::class);
        $carts->method('get')->willReturn($this->createStub(CartInterface::class));
        $collection = $this->createStub(Collection::class);
        foreach (['addFieldToFilter', 'setOrder', 'setPageSize'] as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getFirstItem')->willReturn($this->order);
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturn($collection);

        $resolver = new CreateCheckoutSession(
            $this->createStub(PaymentHelper::class),
            $masked,
            $carts,
            $collections,
            $this->checkoutStart
        );

        return $resolver->resolve(
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            null,
            ['input' => ['cart_id' => 'masked_33', 'method_code' => $methodCode]]
        );
    }
}
