<?php

declare(strict_types=1);

namespace PayMongo\Payment\Plugin\Block\Adminhtml\Order;

use PayMongo\Payment\Controller\Adminhtml\Order\CapturePayment;
use PayMongo\Payment\Model\Order\PaymentCapture;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;

/**
 * Adds a "Capture from PayMongo" button to the admin order view.
 *
 * The button is shown only when PaymentCapture::canRetry() says the order's capture on
 * shipment failed or could not be confirmed and the order has not been invoiced, and the
 * admin user may capture payments (Magento_Sales::capture).
 *
 * Clicking it asks for confirmation, then POSTs to paymongo/order/capturePayment, which
 * charges the customer's held card — or, if PayMongo already took the payment, invoices
 * the order without charging it again.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AddCaptureButton
{
    /** @var PaymentCapture */
    private PaymentCapture $paymentCapture;

    /** @var AuthorizationInterface */
    private AuthorizationInterface $authorization;

    /** @var Escaper */
    private Escaper $escaper;

    /**
     * @param PaymentCapture $paymentCapture
     * @param AuthorizationInterface $authorization
     * @param Escaper $escaper
     */
    public function __construct(
        PaymentCapture $paymentCapture,
        AuthorizationInterface $authorization,
        Escaper $escaper
    ) {
        $this->paymentCapture = $paymentCapture;
        $this->authorization = $authorization;
        $this->escaper = $escaper;
    }

    /**
     * Add the button before the order view lays out its buttons.
     *
     * @param View $subject
     * @param LayoutInterface $layout
     * @return array
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout): array
    {
        $order = $subject->getOrder();

        if (!$order instanceof Order
            || !$this->authorization->isAllowed(CapturePayment::ADMIN_RESOURCE)
            || !$this->paymentCapture->canRetry($order)
        ) {
            return [$layout];
        }

        $message = $this->escaper->escapeJs((string) __(
            'Ask PayMongo to charge the customer\'s held card for this order now? '
            . 'If PayMongo already took the payment, the customer is not charged again.'
        ));
        $url = $this->escaper->escapeJs(
            $subject->getUrl('paymongo/order/capturePayment', ['order_id' => $subject->getOrderId()])
        );

        $subject->addButton(
            'paymongo_capture_payment',
            [
                'label'   => __('Capture from PayMongo'),
                'class'   => 'action-default',
                'id'      => 'paymongo-capture-payment-button',
                'onclick' => sprintf("confirmSetLocation('%s', '%s', {data: {}})", $message, $url),
            ]
        );

        return [$layout];
    }
}
