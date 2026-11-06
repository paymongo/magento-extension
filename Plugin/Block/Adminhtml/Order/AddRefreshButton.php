<?php

declare(strict_types=1);

namespace PayMongo\Payment\Plugin\Block\Adminhtml\Order;

use PayMongo\Payment\Controller\Adminhtml\Order\RefreshStatus;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;

/**
 * Adds a "Refresh from PayMongo" button to the admin order view.
 *
 * The button is shown only when:
 *   - The order was paid via a PayMongo payment method
 *   - The order is still awaiting payment (RefreshStatus::REFRESHABLE_STATES). A
 *     cancelled, closed or completed order is never moved back to Processing.
 *
 * Clicking the button calls paymongo/order/refreshStatus, which fetches the current
 * payment status from PayMongo and processes the order if paid — providing a manual
 * recovery path when a webhook was delayed or never delivered.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AddRefreshButton
{
    /**
     * @param View $subject
     * @param LayoutInterface $layout
     * @return array
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout): array
    {
        $order = $subject->getOrder();

        if (!$order) {
            return [$layout];
        }

        $method = (string) ($order->getPayment()?->getMethod() ?? '');
        $isPaymongoOrder = $method === 'paymongo' || str_starts_with($method, 'paymongo_');

        if (!$isPaymongoOrder) {
            return [$layout];
        }

        if (!in_array($order->getState(), RefreshStatus::REFRESHABLE_STATES, true)) {
            return [$layout];
        }

        $subject->addButton(
            'paymongo_refresh_status',
            [
                'label'   => __('Refresh from PayMongo'),
                'class'   => 'action-default',
                'id'      => 'paymongo-refresh-status-button',
                'onclick' => sprintf(
                    "confirmSetLocation('%s', '%s')",
                    __('Fetch the current payment status from PayMongo and update this order if paid?'),
                    $subject->getUrl(
                        'paymongo/order/refreshStatus',
                        ['order_id' => $subject->getOrderId()]
                    )
                ),
            ]
        );

        return [$layout];
    }
}
