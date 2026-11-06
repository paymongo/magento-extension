<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Observer\Adminhtml;

use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Observer\Adminhtml\WarnAuthorizationExpiry;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the expiry message on the admin order and New Shipment pages (PYM-28): each
 * authorised order shows when its hold expires, with a warning before an order whose
 * hold has lapsed, or is about to, is shipped.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class WarnAuthorizationExpiryTest extends TestCase
{
    private const FORMATTED_DATE = 'Oct 17, 2026, 4:00 PM';

    /** @var OrderRepositoryInterface&MockObject */
    private OrderRepositoryInterface $orderRepository;

    /** @var AuthorizationExpiry&MockObject */
    private AuthorizationExpiry $authorizationExpiry;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $messageManager;

    /** @var bool Whether the order's capture is left to the Capture from PayMongo button */
    private bool $viaButton = false;

    /** @var WarnAuthorizationExpiry */
    private WarnAuthorizationExpiry $observer;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->authorizationExpiry = $this->createMock(AuthorizationExpiry::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturn(self::FORMATTED_DATE);

        $paymentCapture = $this->createStub(PaymentCapture::class);
        $paymentCapture->method('canRetry')->willReturnCallback(fn () => $this->viaButton);

        $this->observer = new WarnAuthorizationExpiry(
            $this->orderRepository,
            $this->authorizationExpiry,
            $paymentCapture,
            $timezone,
            $this->messageManager
        );
    }

    /**
     * An expired hold is an error saying shipping will not charge the customer.
     *
     * @return void
     */
    public function testExpiredHoldIsAnError(): void
    {
        $this->givenStatus(AuthorizationExpiry::STATUS_EXPIRED);
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->messageContaining('PayMongo can no longer capture it'));
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->observer->execute($this->predispatch('28'));
    }

    /**
     * A hold within 2 days of expiry is a warning to ship before then.
     *
     * @return void
     */
    public function testHoldExpiringSoonIsAWarning(): void
    {
        $this->givenStatus(AuthorizationExpiry::STATUS_EXPIRING_SOON);
        $this->messageManager->expects($this->once())->method('addWarningMessage')
            ->with($this->messageContaining('Ship before then'));
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->observer->execute($this->predispatch('28'));
    }

    /**
     * A valid hold is a notice giving its expiry date.
     *
     * @return void
     */
    public function testActiveHoldIsANotice(): void
    {
        $this->givenStatus(AuthorizationExpiry::STATUS_ACTIVE);
        $this->messageManager->expects($this->once())->method('addNoticeMessage')
            ->with($this->messageContaining('until ' . self::FORMATTED_DATE));
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->observer->execute($this->predispatch('28'));
    }

    /**
     * When the capture is left to the Capture from PayMongo button (an earlier attempt
     * failed, or the order shipped without one), the message points there, not at shipping.
     *
     * @return void
     */
    public function testHeldOrderAwaitingTheButtonPointsToIt(): void
    {
        $this->viaButton = true;
        $this->givenStatus(AuthorizationExpiry::STATUS_ACTIVE);
        $this->messageManager->expects($this->once())->method('addNoticeMessage')
            ->with($this->callback(
                static fn ($message): bool => str_contains((string) $message, 'Capture from PayMongo')
                    && !str_contains((string) $message, 'Creating a shipment')
            ));
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->observer->execute($this->predispatch('28'));
    }

    /**
     * An order with no hold waiting for capture gets no message.
     *
     * @return void
     */
    public function testOrderWithoutAHoldGetsNoMessage(): void
    {
        $this->givenStatus(null);
        $this->expectNoMessage();

        $this->observer->execute($this->predispatch('28'));
    }

    /**
     * An order that does not exist is left to the page itself to report.
     *
     * @return void
     */
    public function testMissingOrderGetsNoMessage(): void
    {
        $this->orderRepository->expects($this->once())->method('get')
            ->willThrowException(new NoSuchEntityException(__('The entity that was requested doesn\'t exist.')));
        $this->authorizationExpiry->expects($this->never())->method('getExpiresAt');
        $this->expectNoMessage();

        $this->observer->execute($this->predispatch('999'));
    }

    /**
     * @param string|null $status
     * @return void
     */
    private function givenStatus(?string $status): void
    {
        $order = $this->createStub(Order::class);
        $expiresAt = new \DateTimeImmutable('2026-10-17 08:00:00', new \DateTimeZone('UTC'));
        $this->orderRepository->expects($this->once())->method('get')->with(28)->willReturn($order);
        $this->authorizationExpiry->expects($this->once())->method('getExpiresAt')
            ->with($order)
            ->willReturn($status === null ? null : $expiresAt);
        if ($status === null) {
            $this->authorizationExpiry->expects($this->never())->method('getStatus');
        } else {
            $this->authorizationExpiry->expects($this->once())->method('getStatus')
                ->with($expiresAt)
                ->willReturn($status);
        }
    }

    /**
     * @return void
     */
    private function expectNoMessage(): void
    {
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');
    }

    /**
     * @param string $text
     * @return \PHPUnit\Framework\Constraint\Callback
     */
    private function messageContaining(string $text): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(static fn ($message): bool => str_contains((string) $message, $text));
    }

    /**
     * @param string $orderId
     * @return Observer
     */
    private function predispatch(string $orderId): Observer
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order_id', null, $orderId]]);

        return new Observer(['event' => new Event(['request' => $request])]);
    }
}
