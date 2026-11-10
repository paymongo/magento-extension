<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use Magento\Sales\Model\Order;

/**
 * Works out when an Authorize Only order's card hold expires on PayMongo.
 *
 * PayMongo releases an uncaptured hold 7 days after it is placed, and it can no longer be
 * captured after that. PayMongo returns no expiry date, so it is worked out here from when
 * the order was created. The customer authorises the card on PayMongo's page after the
 * order is created, so this date is never later than the real one: the admin is warned a
 * few minutes early at most, never too late.
 *
 * Only orders PayMongo should still be holding have an expiry
 * (PaymentCapture::isAwaitingCapture()): confirmed Authorize Only orders that have not
 * been invoiced, with no capture attempted yet or a failed one that left the card held.
 * A Pending Payment order has no confirmed hold yet; a cancelled, closed or completed one
 * has nothing left to capture; and one whose capture found the hold expired, cancelled or
 * already captured must not be reported as held.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AuthorizationExpiry
{
    /**
     * Days PayMongo keeps an uncaptured hold before releasing it.
     */
    public const HOLD_DAYS = 7;

    /**
     * Days before expiry at which a hold counts as expiring soon, matching the PayMongo
     * Dashboard's "Expiring Soon" flag.
     */
    public const EXPIRING_SOON_DAYS = 2;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRING_SOON = 'expiring_soon';
    public const STATUS_EXPIRED = 'expired';

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /**
     * @param PaymentCapture $paymentCapture
     */
    public function __construct(PaymentCapture $paymentCapture)
    {
        $this->paymentCapture = $paymentCapture;
    }

    /**
     * When the order's card hold expires, or null if the order is not waiting for capture.
     *
     * @param Order $order
     * @return \DateTimeImmutable|null In UTC
     */
    public function getExpiresAt(Order $order): ?\DateTimeImmutable
    {
        if (!$this->paymentCapture->isAwaitingCapture($order)) {
            return null;
        }

        $createdAt = (string) $order->getCreatedAt();
        if ($createdAt === '') {
            return null;
        }

        try {
            $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }

        return $created->modify('+' . self::HOLD_DAYS . ' days');
    }

    /**
     * Whether a hold expiring at the given time is active, expiring soon or expired.
     *
     * @param \DateTimeImmutable $expiresAt From getExpiresAt()
     * @param \DateTimeImmutable|null $now Defaults to the current time
     * @return string One of the STATUS_* constants
     */
    public function getStatus(\DateTimeImmutable $expiresAt, ?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($now >= $expiresAt) {
            return self::STATUS_EXPIRED;
        }

        if ($now >= $expiresAt->modify('-' . self::EXPIRING_SOON_DAYS . ' days')) {
            return self::STATUS_EXPIRING_SOON;
        }

        return self::STATUS_ACTIVE;
    }
}
