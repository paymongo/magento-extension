<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Plugin\Block\Adminhtml\Order;

use PayMongo\Payment\Controller\Adminhtml\Order\CapturePayment;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Plugin\Block\Adminhtml\Order\AddCaptureButton;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers when the "Capture from PayMongo" button appears on the admin order view (PYM-28):
 * only for an order whose capture can be retried, and only for admins who may capture.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AddCaptureButtonTest extends TestCase
{
    /** @var View&MockObject */
    private View $view;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->view = $this->createMock(View::class);
        $this->view->method('getOrder')->willReturn($this->createStub(Order::class));
        $this->view->method('getOrderId')->willReturn(28);
        $this->view->method('getUrl')
            ->willReturn('https://example.test/admin/paymongo/order/capturePayment/order_id/28/');
    }

    /**
     * An order whose capture can be retried gets a button that POSTs after a confirmation.
     *
     * @return void
     */
    public function testButtonIsAddedForARetryableOrder(): void
    {
        $this->view->expects($this->once())->method('addButton')->with(
            'paymongo_capture_payment',
            $this->callback(static fn (array $button): bool => (string) $button['label'] === 'Capture from PayMongo'
                && str_starts_with($button['onclick'], "confirmSetLocation('")
                && str_contains($button['onclick'], 'paymongo/order/capturePayment')
                && str_ends_with($button['onclick'], ", {data: {}})"))
        );

        $this->plugin(true, true)->beforeSetLayout($this->view, $this->createStub(LayoutInterface::class));
    }

    /**
     * An order with nothing to retry gets no button.
     *
     * @return void
     */
    public function testNoButtonWhenThereIsNothingToRetry(): void
    {
        $this->view->expects($this->never())->method('addButton');

        $this->plugin(false, true)->beforeSetLayout($this->view, $this->createStub(LayoutInterface::class));
    }

    /**
     * An admin who may not capture payments gets no button.
     *
     * @return void
     */
    public function testNoButtonWithoutCapturePermission(): void
    {
        $this->view->expects($this->never())->method('addButton');

        $this->plugin(true, false)->beforeSetLayout($this->view, $this->createStub(LayoutInterface::class));
    }

    /**
     * @param bool $canRetry
     * @param bool $allowed
     * @return AddCaptureButton
     */
    private function plugin(bool $canRetry, bool $allowed): AddCaptureButton
    {
        $paymentCapture = $this->createStub(PaymentCapture::class);
        $paymentCapture->method('canRetry')->willReturn($canRetry);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnMap([[CapturePayment::ADMIN_RESOURCE, null, $allowed]]);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeJs')->willReturnArgument(0);

        return new AddCaptureButton($paymentCapture, $authorization, $escaper);
    }
}
