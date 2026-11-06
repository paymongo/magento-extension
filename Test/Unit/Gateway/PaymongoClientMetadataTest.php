<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Gateway;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * Covers the metadata PayMongo uses to attribute a transaction to this plugin. The key and
 * value are agreed with PayMongo (PYM-11: the key was renamed from origin to plugin), so a
 * rename here silently breaks their attribution and must fail a test.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymongoClientMetadataTest extends TestCase
{
    private const EXPECTED_METADATA = ['plugin' => 'magento_v2'];

    /** @var array<string, mixed>|null The decoded body of the last POST */
    private ?array $sentPayload = null;

    /** @var string|null The URL of the last POST */
    private ?string $sentUrl = null;

    /** @var PaymongoClient */
    private PaymongoClient $client;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('post')->willReturnCallback(function (string $url, string $body): void {
            $this->sentUrl = $url;
            $this->sentPayload = json_decode($body, true);
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"data":{"id":"res_11"}}');
        $curl->method('getHeaders')->willReturn([]);

        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn('sk_test_11');

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://shop.test/paymongo/payment/success');

        $paymentReference = $this->createStub(PaymentReference::class);
        $paymentReference->method('build')->willReturn('MGN-000000011');
        $paymentReference->method('buildDescription')->willReturn('Magento Order #MGN-000000011');

        $this->client = new PaymongoClient(
            $config,
            $curl,
            new Json(),
            $this->createStub(Logger::class),
            $urlBuilder,
            $paymentReference
        );
    }

    /**
     * A checkout session, which every payment method opens, carries plugin = magento_v2.
     *
     * @return void
     */
    public function testCheckoutSessionCarriesThePluginMetadata(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn('PHP');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getId')->willReturn(11);
        $order->method('getStoreId')->willReturn(1);

        $this->client->createCheckoutSession($order, 'gcash');

        $this->assertStringEndsWith('/checkout_sessions', (string) $this->sentUrl);
        $this->assertSame(self::EXPECTED_METADATA, $this->sentPayload['data']['attributes']['metadata']);
    }

    /**
     * A refund carries plugin = magento_v2 too.
     *
     * @return void
     */
    public function testRefundCarriesThePluginMetadata(): void
    {
        $this->client->createRefund('pay_11', 50000, 'requested_by_customer', null, 1);

        $this->assertStringEndsWith('/refunds', (string) $this->sentUrl);
        $this->assertSame(self::EXPECTED_METADATA, $this->sentPayload['data']['attributes']['metadata']);
    }
}
