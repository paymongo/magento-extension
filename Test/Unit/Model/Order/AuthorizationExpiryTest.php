<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Order;

use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers how long an Authorize Only order's card hold lasts (PYM-28): PayMongo releases it
 * 7 days after it is placed and returns no expiry date, so the module works it out from
 * the order's creation time, and only for orders PayMongo should still be holding (see
 * PaymentCaptureTest for which those are).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AuthorizationExpiryTest extends TestCase
{
    private const CREATED_AT = '2026-10-10 08:00:00';

    /**
     * The hold expires 7 days after the order was created, in UTC.
     *
     * @return void
     */
    public function testExpiresSevenDaysAfterTheOrderWasCreated(): void
    {
        $expiresAt = $this->expiry(true)->getExpiresAt($this->order());

        $this->assertNotNull($expiresAt);
        $this->assertSame('2026-10-17 08:00:00', $expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $expiresAt->getTimezone()->getName());
    }

    /**
     * @param string $now
     * @param string $status
     * @return void
     */
    #[DataProvider('statusProvider')]
    public function testStatus(string $now, string $status): void
    {
        $expiry = $this->expiry(true);
        $expiresAt = $expiry->getExpiresAt($this->order());
        $this->assertNotNull($expiresAt);
        $this->assertSame(
            $status,
            $expiry->getStatus($expiresAt, new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'just authorised'        => ['2026-10-10 08:05:00', AuthorizationExpiry::STATUS_ACTIVE],
            'just over 2 days to go' => ['2026-10-15 07:59:59', AuthorizationExpiry::STATUS_ACTIVE],
            '2 days to go'           => ['2026-10-15 08:00:00', AuthorizationExpiry::STATUS_EXPIRING_SOON],
            'a minute to go'         => ['2026-10-17 07:59:00', AuthorizationExpiry::STATUS_EXPIRING_SOON],
            'at expiry'              => ['2026-10-17 08:00:00', AuthorizationExpiry::STATUS_EXPIRED],
            'a week after expiry'    => ['2026-10-24 08:00:00', AuthorizationExpiry::STATUS_EXPIRED],
        ];
    }

    /**
     * An order PayMongo is not holding a card for has no expiry — the reported case being
     * one whose capture found the hold already cancelled, which the banner called held.
     *
     * @return void
     */
    public function testOrderWithoutAHoldHasNoExpiry(): void
    {
        $this->assertNull($this->expiry(false)->getExpiresAt($this->order()));
    }

    /**
     * @param bool $awaitingCapture
     * @return AuthorizationExpiry
     */
    private function expiry(bool $awaitingCapture): AuthorizationExpiry
    {
        $paymentCapture = $this->createStub(PaymentCapture::class);
        $paymentCapture->method('isAwaitingCapture')->willReturn($awaitingCapture);

        return new AuthorizationExpiry($paymentCapture);
    }

    /**
     * @return Order
     */
    private function order(): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getCreatedAt')->willReturn(self::CREATED_AT);

        return $order;
    }
}
