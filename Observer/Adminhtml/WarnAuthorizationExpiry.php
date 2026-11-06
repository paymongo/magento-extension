<?php

declare(strict_types=1);

namespace PayMongo\Payment\Observer\Adminhtml;

use PayMongo\Payment\Model\Order\AuthorizationExpiry;
use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Shows when an Authorize Only order's card hold expires, on the admin order page and the
 * New Shipment page.
 *
 * Shipping is what captures the payment, so the date matters most there. A hold that is
 * still valid gets a notice, one within 2 days of expiry a warning, and one that has
 * expired an error saying PayMongo can no longer capture it. When the capture has to be
 * made with the "Capture from PayMongo" button instead (an earlier attempt failed, or the
 * order shipped without one), the message says so rather than pointing at shipping.
 * PayMongo returns no expiry date; see Model\Order\AuthorizationExpiry for how it is
 * worked out, and which orders have one.
 *
 * Observes controller_action_predispatch_sales_order_view and
 * controller_action_predispatch_adminhtml_order_shipment_new (etc/adminhtml/events.xml).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class WarnAuthorizationExpiry implements ObserverInterface
{
    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var AuthorizationExpiry */
    private AuthorizationExpiry $authorizationExpiry;

    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /** @var TimezoneInterface */
    private TimezoneInterface $timezone;

    /** @var ManagerInterface */
    private ManagerInterface $messageManager;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param AuthorizationExpiry $authorizationExpiry
     * @param PaymentCapture $paymentCapture
     * @param TimezoneInterface $timezone
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        AuthorizationExpiry $authorizationExpiry,
        PaymentCapture $paymentCapture,
        TimezoneInterface $timezone,
        ManagerInterface $messageManager
    ) {
        $this->orderRepository = $orderRepository;
        $this->authorizationExpiry = $authorizationExpiry;
        $this->paymentCapture = $paymentCapture;
        $this->timezone = $timezone;
        $this->messageManager = $messageManager;
    }

    /**
     * Add the expiry message for the order being viewed or shipped.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var RequestInterface $request */
        $request = $observer->getEvent()->getRequest();
        $orderId = (int) $request->getParam('order_id');
        if ($orderId <= 0) {
            return;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            return;
        }

        if (!$order instanceof Order) {
            return;
        }

        $expiresAt = $this->authorizationExpiry->getExpiresAt($order);
        if ($expiresAt === null) {
            return;
        }
        $status = $this->authorizationExpiry->getStatus($expiresAt);

        $date = $this->timezone->formatDateTime(
            $expiresAt,
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );

        $viaButton = $this->paymentCapture->canRetry($order);

        switch ($status) {
            case AuthorizationExpiry::STATUS_EXPIRED:
                $this->messageManager->addErrorMessage(__(
                    'The card authorisation for this order expired on %1, so PayMongo can no longer capture it. '
                    . 'Check the payment in the PayMongo Dashboard, and collect payment another way only if '
                    . 'PayMongo did not capture it.',
                    $date
                ));
                break;
            case AuthorizationExpiry::STATUS_EXPIRING_SOON:
                $this->messageManager->addWarningMessage($viaButton
                    ? __(
                        'The card authorisation for this order expires on %1. Use Capture from PayMongo before '
                        . 'then: after that, PayMongo releases the hold and the customer can no longer be charged '
                        . 'for it.',
                        $date
                    )
                    : __(
                        'The card authorisation for this order expires on %1. Ship before then: after that, '
                        . 'PayMongo releases the hold and the customer can no longer be charged for it.',
                        $date
                    ));
                break;
            default:
                $this->messageManager->addNoticeMessage($viaButton
                    ? __(
                        'PayMongo is holding the card payment for this order until %1. Use Capture from PayMongo '
                        . 'to charge the customer.',
                        $date
                    )
                    : __(
                        'PayMongo is holding the card payment for this order until %1. Creating a shipment '
                        . 'charges the customer.',
                        $date
                    ));
        }
    }
}
