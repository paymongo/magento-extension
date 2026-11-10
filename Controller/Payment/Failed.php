<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Payment;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\Order\PaymentCapture;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;

/**
 * Handles the return from PayMongo when a payment is cancelled or fails.
 *
 * Cancels the pending order, restores the customer's cart, and shows an
 * error message so the customer can retry with a different payment method.
 *
 * Only the visitor's own order is cancelled: the one their checkout session just placed, and
 * only while it is a PayMongo order still awaiting payment. Before PYM-32 any order_id was
 * cancelled, so anyone could cancel other customers' orders by guessing IDs, which now also
 * closes their PayMongo page and releases Authorize Only holds. A visitor without that
 * checkout session (a headless storefront, another browser) gets the message and the cart,
 * and the order is cancelled by Magento's stale-order cron if it is never paid.
 *
 * PayMongo is asked first, under the order lock the webhook and Success use: a customer can
 * pay, or start a GCash or Maya payment, and still follow PayMongo's back link. An order
 * PayMongo has been paid for, or whose payment is still processing, is left for the webhook
 * to confirm, and the cart is not restored, so it cannot be bought twice. When PayMongo
 * cannot be asked, the order is left too; the cron cancels it if it is never paid.
 *
 * Route: GET paymongo/payment/failed?order_id={id}
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Failed implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private RequestInterface $request;

    /** @var CheckoutSession */
    private CheckoutSession $checkoutSession;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var OrderManagementInterface */
    private OrderManagementInterface $orderManagement;

    /** @var ResultFactory */
    private ResultFactory $resultFactory;

    /** @var ManagerInterface */
    private ManagerInterface $messageManager;

    /** @var Logger */
    private Logger $logger;

    /** @var CheckoutStart */
    private CheckoutStart $checkoutStart;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var OrderFactory */
    private OrderFactory $orderFactory;

    /**
     * @param RequestInterface $request
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderManagementInterface $orderManagement
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param Logger $logger
     * @param CheckoutStart $checkoutStart
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        Logger $logger,
        CheckoutStart $checkoutStart,
        LockManagerInterface $lockManager,
        OrderFactory $orderFactory
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->orderManagement = $orderManagement;
        $this->resultFactory = $resultFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->checkoutStart = $checkoutStart;
        $this->lockManager = $lockManager;
        $this->orderFactory = $orderFactory;
    }

    /**
     * Cancel the order and redirect the customer back to the cart.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('checkout/cart');
        $orderId = (int) $this->request->getParam('order_id');

        if (!$orderId || !$this->isVisitorsOwnOrder($orderId)) {
            $this->messageManager->addErrorMessage(__('Payment was cancelled or failed. Please try again.'));
            return $redirect;
        }

        try {
            $this->cancelUnderLock($orderId);
        } catch (\Exception $e) {
            $this->logger->error('Payment failed callback error', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);
            $this->messageManager->addErrorMessage(
                __('An error occurred while processing your cancellation. Please contact support.')
            );
        }

        return $redirect;
    }

    /**
     * Cancel the order, if PayMongo has not been paid for it, holding the order's lock.
     *
     * Tells the customer what happened.
     *
     * @param int $orderId
     * @return void
     */
    private function cancelUnderLock(int $orderId): void
    {
        // The lock is named by the order's increment ID, read through the factory so the
        // repository first loads the order inside the lock: the webhook may confirm it meanwhile.
        $incrementId = (string) $this->orderFactory->create()->load($orderId)->getIncrementId();
        $lockName = PaymentCapture::ORDER_LOCK_PREFIX . $incrementId;
        if (!$this->lockManager->lock($lockName, PaymentCapture::ORDER_LOCK_TIMEOUT)) {
            $this->messageManager->addNoticeMessage(
                __('We are still checking your payment. Please check your order status shortly.')
            );
            return;
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);

            $this->logger->info('Payment failed/cancelled', ['order_id' => $order->getIncrementId()]);

            if (!$this->checkoutStart->isPaymongoOrder($order)
                || !in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)
                || !$order->canCancel()
            ) {
                $this->messageManager->addNoticeMessage(__('This order can no longer be cancelled here.'));
                return;
            }

            try {
                $state = $this->checkoutStart->sessionState($order)['state'];
            } catch (\Exception $e) {
                $this->logger->warning('Payment failed callback: PayMongo could not be asked, order left open', [
                    'order_id' => $order->getIncrementId(),
                    'error'    => $e->getMessage(),
                ]);
                $this->messageManager->addNoticeMessage(__(
                    'We could not confirm your payment status yet. If you were charged, your order will be '
                    . 'confirmed shortly; otherwise it will be cancelled automatically.'
                ));
                return;
            }

            // Paid, still processing, or waiting on the customer's 3DS check or e-wallet app: the
            // payment may still complete, so the order is left for PayMongo to report.
            if (in_array(
                $state,
                [CheckoutStart::STATE_PAID, CheckoutStart::STATE_PROCESSING, CheckoutStart::STATE_ACTION],
                true
            )) {
                $this->logger->info('Payment failed callback: PayMongo has the payment, order left open', [
                    'order_id' => $order->getIncrementId(),
                    'state'    => $state,
                ]);
                $this->messageManager->addNoticeMessage(__(
                    'Your payment for order #%1 has been received or is still being completed. If you '
                    . 'approved it, you will get a confirmation shortly; if not, the order is cancelled '
                    . 'automatically.',
                    $order->getIncrementId()
                ));
                return;
            }

            $this->orderManagement->cancel((int) $order->getId());
            $order = $this->orderRepository->get($orderId);
            $order->addCommentToStatusHistory(
                __('Order cancelled: customer returned from PayMongo without completing payment.')
            );
            $this->orderRepository->save($order);

            // Restore the quote so the customer can retry. Only for an order just cancelled:
            // restoring the cart of a paid order would let it be bought twice.
            $this->checkoutSession->restoreQuote();

            $this->messageManager->addErrorMessage(__('Payment was cancelled or failed. Please try again.'));
        } finally {
            if (isset($order)) {
                $this->checkoutStart->forget($order);
            }
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Whether the order is the one this visitor's checkout session just placed.
     *
     * @param int $orderId
     * @return bool
     */
    private function isVisitorsOwnOrder(int $orderId): bool
    {
        $own = (int) $this->checkoutSession->getLastOrderId() === $orderId;
        if (!$own) {
            $this->logger->warning('Payment failed callback for an order not placed in this session, ignored', [
                'order_id' => $orderId,
            ]);
        }

        return $own;
    }
}
