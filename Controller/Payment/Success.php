<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Payment;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CaptureMode;
use PayMongo\Payment\Model\Order\PaidCancelledOrder;
use PayMongo\Payment\Model\Order\PaymentConfirmation;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Handles the return from PayMongo after a successful (or attempted) payment.
 *
 * Asks PayMongo whether the checkout was paid (Model\Order\PaymentConfirmation, the same
 * check the admin Refresh button uses), and for a paid one transitions the order to
 * Processing, creates an invoice, and sends the order confirmation email.
 *
 * Route: GET paymongo/payment/success?order_id={id}
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Success implements HttpGetActionInterface
{
    /** @var RequestInterface */
    private RequestInterface $request;

    /** @var PaymentConfirmation */
    private PaymentConfirmation $paymentConfirmation;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var InvoiceService */
    private InvoiceService $invoiceService;

    /** @var TransactionFactory */
    private TransactionFactory $transactionFactory;

    /** @var OrderSender */
    private OrderSender $orderSender;

    /** @var ResultFactory */
    private ResultFactory $resultFactory;

    /** @var ManagerInterface */
    private ManagerInterface $messageManager;

    /** @var CaptureMode */
    private CaptureMode $captureMode;

    /** @var Logger */
    private Logger $logger;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var OrderFactory */
    private OrderFactory $orderFactory;

    /** @var PaidCancelledOrder */
    private PaidCancelledOrder $paidCancelledOrder;

    /**
     * Same lock name and wait the Webhook controller uses, so the two paths serialise
     * against each other rather than each against itself.
     */
    private const ORDER_LOCK_PREFIX = \PayMongo\Payment\Model\Order\PaymentCapture::ORDER_LOCK_PREFIX;
    private const ORDER_LOCK_TIMEOUT = \PayMongo\Payment\Model\Order\PaymentCapture::ORDER_LOCK_TIMEOUT;

    /**
     * @param RequestInterface $request
     * @param PaymentConfirmation $paymentConfirmation
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param CaptureMode $captureMode
     * @param Logger $logger
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     * @param PaidCancelledOrder $paidCancelledOrder
     */
    public function __construct(
        RequestInterface $request,
        PaymentConfirmation $paymentConfirmation,
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderSender $orderSender,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        CaptureMode $captureMode,
        Logger $logger,
        LockManagerInterface $lockManager,
        OrderFactory $orderFactory,
        PaidCancelledOrder $paidCancelledOrder
    ) {
        $this->request = $request;
        $this->paymentConfirmation = $paymentConfirmation;
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderSender = $orderSender;
        $this->resultFactory = $resultFactory;
        $this->messageManager = $messageManager;
        $this->captureMode = $captureMode;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
        $this->orderFactory = $orderFactory;
        $this->paidCancelledOrder = $paidCancelledOrder;
    }

    /**
     * Process the return from PayMongo and redirect to the order success page.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $orderId = $this->request->getParam('order_id');

        try {
            if (!$orderId) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Invalid return from payment gateway.')
                );
            }

            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
            $payment         = $order->getPayment();
            $sessionId       = $payment->getAdditionalInformation('paymongo_checkout_session_id');
            $storedIntentId  = $payment->getAdditionalInformation('paymongo_payment_intent_id');

            $this->logger->info('Payment success callback', [
                'order_id'           => $order->getIncrementId(),
                'session_id'         => $sessionId,
                'stored_intent_id'   => $storedIntentId,
            ]);

            if ($sessionId) {
                // Checkout Sessions flow (every method, BPI and UnionBank included since PYM-6)
                $result = $this->paymentConfirmation->checkCheckoutSession($sessionId, (int) $order->getStoreId());

                $this->logger->info('PayMongo session status on return', [
                    'order_id'          => $order->getIncrementId(),
                    'status'            => $result['status'],
                    'pi_status'         => $result['pi_status'],
                    'payment_intent_id' => $result['payment_intent_id'],
                    'payment_id'        => $result['payment_id'],
                ]);

                // A session still sitting at "active" has NOT been paid. A customer who abandons
                // on PayMongo's hosted page and then lands back on this URL must never be
                // invoiced; PaymentConfirmation only confirms a paid session or a held card.
                if ($result['confirmed']) {
                    $isAuthorizeOnly = $result['authorized']
                        || $this->captureMode->isAuthorizeOnly($order);

                    $this->finalizeUnderLock(
                        $order,
                        $isAuthorizeOnly,
                        $result['payment_intent_id'],
                        $result['payment_id']
                    );
                } else {
                    $this->logger->warning('Unexpected PayMongo session status on return', [
                        'order_id' => $order->getIncrementId(),
                        'status'   => $result['status'],
                    ]);
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __(
                            'Your payment could not be confirmed (status: %1). Please contact support.',
                            $result['status'] ?? 'unknown'
                        )
                    );
                }
            } elseif ($storedIntentId) {
                // PIPM flow: BPI / UnionBank orders placed before they moved to Checkout Sessions
                $result = $this->paymentConfirmation->checkPaymentIntent($storedIntentId, (int) $order->getStoreId());

                $this->logger->info('PayMongo DOB payment intent status on return', [
                    'order_id'          => $order->getIncrementId(),
                    'payment_intent_id' => $storedIntentId,
                    'status'            => $result['status'],
                    'payment_id'        => $result['payment_id'],
                ]);

                if ($result['confirmed']) {
                    $this->finalizeUnderLock($order, $result['authorized'], null, $result['payment_id']);
                } else {
                    $this->logger->warning('Unexpected PayMongo DOB intent status on return', [
                        'order_id' => $order->getIncrementId(),
                        'status'   => $result['status'],
                    ]);
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __(
                            'Your payment could not be confirmed (status: %1). Please contact support.',
                            $result['status'] ?? 'unknown'
                        )
                    );
                }
            } else {
                $this->logger->warning('Missing payment session reference on success return', [
                    'order_id' => $order->getIncrementId(),
                ]);
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Payment session could not be verified. Please contact support.')
                );
            }

            $redirect->setPath('checkout/onepage/success');
        } catch (\Exception $e) {
            $this->logger->error('Payment callback error', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);
            $this->messageManager->addErrorMessage(
                __('There was an error processing your payment return. Please contact support.')
            );
            $redirect->setPath('checkout/cart');
        }

        return $redirect;
    }

    /**
     * Transition an authorised (not yet captured) order to Processing state without creating an invoice.
     *
     * Used for the authorize-only card flow: the card has been held but not charged.
     * The invoice is created later by the CaptureOnShipment observer when the merchant
     * ships the order and the payment intent is captured.
     *
     * @param Order $order
     * @return void
     */
    /**
     * Finalise a confirmed payment while holding the same per-order lock the Webhook
     * controller takes.
     *
     * Both this controller and the webhook can arrive for the same order at the same
     * moment — PayMongo fires checkout_session.payment.paid while the customer's browser
     * is being redirected back here. The "is it still awaiting payment?" guards in
     * processSuccessfulPayment() / processAuthorizedPayment() are check-then-act, so
     * without a shared lock both callers could read pending_payment, both pass
     * canInvoice(), and each register an invoice for the full grand total: doubled
     * total_paid and two confirmation emails.
     *
     * The order is deliberately re-read inside the lock. The copy loaded at the top of
     * execute() predates the wait, so acting on it would re-apply work the webhook has
     * already committed — which is precisely the double invoice this lock exists to stop.
     * The reload goes through OrderFactory rather than the repository on purpose:
     * OrderRepository::get() memoises instances for the request, so it would hand back
     * the very object we are trying to refresh.
     *
     * @param Order $order
     * @param bool $isAuthorizeOnly
     * @param string|null $paymentIntentId
     * @param string|null $paymentId
     * @return void
     */
    private function finalizeUnderLock(
        Order $order,
        bool $isAuthorizeOnly,
        ?string $paymentIntentId,
        ?string $paymentId
    ): void {
        $lockName = self::ORDER_LOCK_PREFIX . $order->getIncrementId();

        if (!$this->lockManager->lock($lockName, self::ORDER_LOCK_TIMEOUT)) {
            // The webhook is mid-flight and will finish the job; the customer still gets
            // the success page. Bailing out is safer than proceeding on unsynchronised state.
            $this->logger->warning('Success: could not acquire order lock, webhook likely processing', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        try {
            $fresh = $this->orderFactory->create()->load((int) $order->getEntityId());
            if ($fresh->getId()) {
                $order = $fresh;
            }
            $payment = $order->getPayment();

            if ($paymentIntentId) {
                $payment->setAdditionalInformation('paymongo_payment_intent_id', $paymentIntentId);
            }
            if ($paymentId) {
                $payment->setAdditionalInformation('paymongo_payment_id', $paymentId);
            }

            if ($isAuthorizeOnly) {
                $this->processAuthorizedPayment($order);
            } else {
                $this->processSuccessfulPayment($order);
            }
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function processAuthorizedPayment(Order $order): void
    {
        if (!in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)) {
            // A card authorised for a cancelled order: ReleaseHoldOnCancel ran before the hold
            // existed, so the admin is alerted; the alert asks PayMongo whether it is still a hold
            // or was captured (PYM-32). Saved with the IDs.
            if ($order->getState() === Order::STATE_CANCELED) {
                $this->paidCancelledOrder->alert($order, null);
            }
            $this->persistPaymentReferenceIfChanged($order);
            return;
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            __('Card authorised by PayMongo. Invoice will be created when shipment is captured.')
        );
        $this->orderRepository->save($order);

        $this->logger->info('Card payment authorised awaiting capture on shipment', [
            'order_id' => $order->getIncrementId(),
        ]);
    }

    /**
     * Transition a paid order to Processing state, create an invoice, and send the confirmation email.
     *
     * Idempotent: if the order is no longer awaiting payment (e.g. a webhook already
     * confirmed it before the customer's browser returned here, or the customer reloads
     * this page after the order has shipped or been cancelled), this still saves the
     * PayMongo payment/intent IDs set on $payment by execute() if they weren't already
     * stored — otherwise a webhook-confirmed-first order would never get one, which
     * breaks reconciliation matching and online refunds for that order.
     *
     * @param Order $order
     * @return void
     */
    private function processSuccessfulPayment(Order $order): void
    {
        if (!in_array($order->getState(), PaymentConfirmation::AWAITING_PAYMENT_STATES, true)) {
            // PayMongo took this payment, so a cancelled order means the customer paid for an
            // order the store will not fulfil: alert the admin (PYM-32). Saved with the IDs.
            if ($order->getState() === Order::STATE_CANCELED) {
                $this->paidCancelledOrder->alert(
                    $order,
                    $order->getPayment()->getAdditionalInformation('paymongo_payment_id') ?: null,
                    false
                );
            }
            $this->persistPaymentReferenceIfChanged($order);
            return;
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(__('Payment confirmed by PayMongo.'));

        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();

            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)
                ->addObject($order)
                ->save();

            $this->logger->info('Invoice created for order', [
                'order_id' => $order->getIncrementId(),
                'invoice_id' => $invoice->getIncrementId(),
            ]);
        }

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save the order only if execute() set a new PayMongo payment/intent ID on its
     * payment that wasn't already stored. Used on the "no longer awaiting payment" paths so a
     * webhook-confirmed-first order still ends up with its payment reference saved.
     *
     * @param Order $order
     * @return void
     */
    private function persistPaymentReferenceIfChanged(Order $order): void
    {
        if ($order->getPayment()->hasDataChanges()) {
            $this->orderRepository->save($order);
            $this->logger->info('Backfilled PayMongo payment reference on an already-processed order', [
                'order_id' => $order->getIncrementId(),
            ]);
        }
    }
}
