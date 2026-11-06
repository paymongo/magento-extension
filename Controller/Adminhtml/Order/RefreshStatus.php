<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Order;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Admin action: fetch payment status from PayMongo and update the order.
 *
 * Used when a webhook was delayed or never delivered, leaving an order stuck in
 * Pending Payment even though the customer paid. The merchant clicks
 * "Refresh from PayMongo" on the order view to recover manually.
 *
 * Whether the order is paid is decided by Model\Order\PaymentConfirmation, the same check
 * the customer's success page uses. Until PYM-27 this action treated a checkout session that
 * was still "active" (open on PayMongo, waiting for the customer) as paid, and invoiced
 * orders nobody had paid for. Only orders still awaiting payment are refreshed (see
 * REFRESHABLE_STATES): anything else has already been settled, cancelled or closed, and
 * must not be moved back to Processing.
 *
 * Route: GET paymongo/order/refreshStatus?order_id={id}
 * ACL:   Magento_Sales::actions_view
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class RefreshStatus extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

    /**
     * Order states that are still awaiting payment.
     */
    public const REFRESHABLE_STATES = PaymentConfirmation::AWAITING_PAYMENT_STATES;

    /**
     * Same lock name and wait the Webhook and Success controllers use, so a refresh that
     * coincides with the webhook cannot invoice the order twice.
     */
    private const ORDER_LOCK_PREFIX = \PayMongo\Payment\Model\Order\PaymentCapture::ORDER_LOCK_PREFIX;
    private const ORDER_LOCK_TIMEOUT = \PayMongo\Payment\Model\Order\PaymentCapture::ORDER_LOCK_TIMEOUT;

    public function __construct(
        Context $context,
        private readonly PaymentConfirmation $paymentConfirmation,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderFactory $orderFactory,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly CaptureMode $captureMode,
        private readonly LockManagerInterface $lockManager,
        private readonly Logger $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Invalid order.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        try {
            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();
            $sessionId = $payment->getAdditionalInformation('paymongo_checkout_session_id');
            $intentId = $payment->getAdditionalInformation('paymongo_payment_intent_id');

            if (!$sessionId && !$intentId) {
                $this->messageManager->addErrorMessage(
                    __('No PayMongo payment reference found for this order.')
                );
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            if (!$this->isRefreshable($order)) {
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            $this->logger->info('Admin initiated PayMongo status refresh', [
                'order_id' => $order->getIncrementId(),
                'session_id' => $sessionId,
                'intent_id' => $intentId,
            ]);

            $result = $sessionId
                ? $this->paymentConfirmation->checkCheckoutSession($sessionId, (int) $order->getStoreId())
                : $this->paymentConfirmation->checkPaymentIntent($intentId, (int) $order->getStoreId());

            $this->logger->info('PayMongo status on admin refresh', [
                'order_id' => $order->getIncrementId(),
                'status' => $result['status'],
                'pi_status' => $result['pi_status'],
                'payment_intent_id' => $result['payment_intent_id'],
                'payment_id' => $result['payment_id'],
            ]);

            if ($result['confirmed']) {
                $this->finalizeUnderLock($order, $result);
            } else {
                $this->messageManager->addWarningMessage(
                    __(
                        'PayMongo has not received payment for this order yet (status: "%1"). No changes made.',
                        $result['status'] ?? 'unknown'
                    )
                );
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('Admin PayMongo refresh error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                __('Could not retrieve payment status from PayMongo. Please try again.')
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Whether this order may be refreshed, adding a notice to the admin when it may not.
     *
     * @param Order $order
     * @return bool
     */
    private function isRefreshable(Order $order): bool
    {
        if (in_array($order->getState(), self::REFRESHABLE_STATES, true)) {
            return true;
        }

        if ($order->getState() === Order::STATE_PROCESSING) {
            $this->messageManager->addNoticeMessage(
                __('Order #%1 is already in Processing — no refresh needed.', $order->getIncrementId())
            );
        } else {
            $this->messageManager->addNoticeMessage(
                __(
                    'Order #%1 is no longer awaiting payment, so it was not refreshed. Check the payment in the PayMongo Dashboard.',
                    $order->getIncrementId()
                )
            );
        }

        return false;
    }

    /**
     * Confirm the order while holding the per-order lock the webhook takes.
     *
     * The order is re-read inside the lock, through OrderFactory rather than the memoising
     * repository, so a webhook that confirmed it while this request waited is seen and the
     * order is not invoiced twice. See Controller\Payment\Success::finalizeUnderLock().
     *
     * @param Order $order
     * @param array $result PaymentConfirmation result
     * @return void
     */
    private function finalizeUnderLock(Order $order, array $result): void
    {
        $lockName = self::ORDER_LOCK_PREFIX . $order->getIncrementId();

        if (!$this->lockManager->lock($lockName, self::ORDER_LOCK_TIMEOUT)) {
            $this->messageManager->addWarningMessage(
                __('A PayMongo update for this order is already in progress. Reload the page in a moment.')
            );
            return;
        }

        try {
            $fresh = $this->orderFactory->create()->load((int) $order->getEntityId());
            if ($fresh->getId()) {
                $order = $fresh;
            }

            if (!$this->isRefreshable($order)) {
                return;
            }

            $payment = $order->getPayment();
            if ($result['payment_intent_id']) {
                $payment->setAdditionalInformation('paymongo_payment_intent_id', $result['payment_intent_id']);
            }
            if ($result['payment_id']) {
                $payment->setAdditionalInformation('paymongo_payment_id', $result['payment_id']);
            }

            if ($result['authorized'] || $this->captureMode->isAuthorizeOnly($order)) {
                $this->processAuthorizedOrder($order);
            } else {
                $this->processSuccessfulOrder($order);
            }
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function processSuccessfulOrder(Order $order): void
    {
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(__('Payment confirmed by PayMongo (admin refresh).'));

        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();

            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)->addObject($order)->save();

            $this->logger->info('Invoice created via admin refresh', [
                'order_id' => $order->getIncrementId(),
                'invoice_id' => $invoice->getIncrementId(),
            ]);
        }

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order email on admin refresh', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->messageManager->addSuccessMessage(
            __('Order #%1 confirmed as paid and moved to Processing.', $order->getIncrementId())
        );

        $this->logger->info('Order confirmed as paid via admin refresh', [
            'order_id' => $order->getIncrementId(),
        ]);
    }

    private function processAuthorizedOrder(Order $order): void
    {
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            __('Card authorised by PayMongo (admin refresh). Invoice will be created on shipment capture.')
        );
        $this->orderRepository->save($order);

        $this->messageManager->addSuccessMessage(
            __('Order #%1 card authorisation confirmed. Capture will happen on shipment.', $order->getIncrementId())
        );

        $this->logger->info('Order confirmed as authorised via admin refresh', [
            'order_id' => $order->getIncrementId(),
        ]);
    }
}
