<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Controller\Adminhtml\Order;

use PayMongo\Payment\Controller\Adminhtml\Order\RefreshStatus;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the guards in the admin "Refresh from PayMongo" button, where PYM-27's bug was:
 * an order that is not awaiting payment, or that PayMongo has no payment for, must be left
 * exactly as it is, and confirming one must not race the webhook into a second invoice.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class RefreshStatusTest extends TestCase
{
    /** @var PaymentConfirmation&MockObject */
    private PaymentConfirmation $paymentConfirmation;

    /** @var OrderRepositoryInterface&MockObject */
    private OrderRepositoryInterface $orderRepository;

    /** @var OrderFactory&MockObject */
    private OrderFactory $orderFactory;

    /** @var InvoiceService&MockObject */
    private InvoiceService $invoiceService;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $messageManager;

    /** @var RefreshStatus */
    private RefreshStatus $controller;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymentConfirmation = $this->createMock(PaymentConfirmation::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order_id', null, '7']]);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $this->controller = new RefreshStatus(
            $context,
            $this->paymentConfirmation,
            $this->orderRepository,
            $this->orderFactory,
            $this->invoiceService,
            $this->createStub(TransactionFactory::class),
            $this->createStub(OrderSender::class),
            $this->createStub(CaptureMode::class),
            $this->lockManager,
            $this->createStub(Logger::class)
        );
    }

    /**
     * A cancelled, closed or completed order is never sent to PayMongo at all.
     *
     * @return void
     */
    public function testOrderNotAwaitingPaymentMakesNoApiCall(): void
    {
        $this->givenOrder(Order::STATE_CANCELED);
        $this->paymentConfirmation->expects($this->never())->method('checkCheckoutSession');
        $this->paymentConfirmation->expects($this->never())->method('checkPaymentIntent');
        $this->lockManager->expects($this->never())->method('lock');
        $this->orderFactory->expects($this->never())->method('create');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->messageManager->expects($this->once())->method('addNoticeMessage');

        $this->controller->execute();
    }

    /**
     * The reported bug: an order PayMongo has no payment for is not saved or invoiced.
     *
     * @return void
     */
    public function testUnpaidOrderIsLeftUnchanged(): void
    {
        $this->givenOrder(Order::STATE_NEW);
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with('cs_7', 1)
            ->willReturn($this->confirmation(false, 'active'));

        $this->lockManager->expects($this->never())->method('lock');
        $this->orderFactory->expects($this->never())->method('create');
        $this->orderRepository->expects($this->never())->method('save');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->messageManager->expects($this->once())->method('addWarningMessage')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'has not received payment'))
        );

        $this->controller->execute();
    }

    /**
     * While the webhook (or the customer's return) holds the order's lock, Refresh leaves the
     * order alone instead of confirming it a second time.
     *
     * @return void
     */
    public function testOrderIsLeftAloneWhenTheLockIsHeld(): void
    {
        $this->givenOrder(Order::STATE_NEW);
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with('cs_7', 1)
            ->willReturn($this->confirmation(true, 'paid'));

        $this->lockManager->expects($this->once())->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->orderFactory->expects($this->never())->method('create');
        $this->orderRepository->expects($this->never())->method('save');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->messageManager->expects($this->once())->method('addWarningMessage')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'already in progress'))
        );

        $this->controller->execute();
    }

    /**
     * An order the webhook confirmed while Refresh waited for the lock is seen on the re-read
     * inside the lock and not invoiced again.
     *
     * @return void
     */
    public function testOrderAlreadyProcessingOnTheReReadIsSkipped(): void
    {
        $this->givenOrder(Order::STATE_NEW);
        $this->paymentConfirmation->expects($this->once())->method('checkCheckoutSession')
            ->with('cs_7', 1)
            ->willReturn($this->confirmation(true, 'paid'));
        $this->lockManager->expects($this->once())->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');

        $fresh = $this->createStub(Order::class);
        $fresh->method('load')->willReturnSelf();
        $fresh->method('getId')->willReturn(7);
        $fresh->method('getIncrementId')->willReturn('000000007');
        $fresh->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->orderFactory->expects($this->once())->method('create')->willReturn($fresh);

        $this->orderRepository->expects($this->never())->method('save');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->messageManager->expects($this->once())->method('addNoticeMessage')->with(
            $this->callback(static fn ($message): bool => str_contains((string) $message, 'already in Processing'))
        );

        $this->controller->execute();
    }

    /**
     * @param string $state
     * @return void
     */
    private function givenOrder(string $state): void
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['paymongo_checkout_session_id', 'cs_7'],
            ['paymongo_payment_intent_id', 'pi_7'],
        ]);

        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStoreId')->willReturn(1);

        $this->orderRepository->method('get')->with(7)->willReturn($order);
    }

    /**
     * @param bool $confirmed
     * @param string $status
     * @return array
     */
    private function confirmation(bool $confirmed, string $status): array
    {
        return [
            'confirmed' => $confirmed,
            'authorized' => false,
            'status' => $status,
            'payment_intent_id' => 'pi_7',
            'payment_id' => $confirmed ? 'pay_7' : null,
            'pi_status' => $confirmed ? 'succeeded' : 'awaiting_payment_method',
        ];
    }
}
