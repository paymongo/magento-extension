<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Payment;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\CheckoutStart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * Initiates the PayMongo redirect flow after the order is placed.
 *
 * Creates a PayMongo hosted checkout session for the last real order, stores
 * the session ID on the payment record, and redirects the customer to the
 * PayMongo checkout URL. On failure the customer is sent back to the cart.
 *
 * Detects the payment method code from the order and passes the matching
 * PayMongo API payment_method_type to the checkout session so the customer
 * lands on the correct payment screen (e.g. GCash-only for paymongo_gcash
 * orders). The all-in-one paymongo method presents every individually-enabled
 * method together and always captures immediately; Card Payment Action's
 * Authorize Only applies to the standalone Card method alone (see
 * Model\Order\CaptureMode).
 *
 * Route: GET paymongo/payment/redirect
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Redirect implements HttpGetActionInterface
{
    /** @var CheckoutSession */
    private CheckoutSession $checkoutSession;

    /** @var ResultFactory */
    private ResultFactory $resultFactory;

    /** @var ManagerInterface */
    private ManagerInterface $messageManager;

    /** @var Logger */
    private Logger $logger;

    /** @var CheckoutStart */
    private CheckoutStart $checkoutStart;

    /**
     * @param CheckoutSession $checkoutSession
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param Logger $logger
     * @param CheckoutStart $checkoutStart
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        Logger $logger,
        CheckoutStart $checkoutStart
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->resultFactory = $resultFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->checkoutStart = $checkoutStart;
    }

    /**
     * Create a checkout session and redirect the customer to PayMongo.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getId()) {
                $this->logger->error('PayMongo redirect: No order found in session');
                $this->messageManager->addErrorMessage(__('No order found. Please try again.'));
                $redirect->setPath('checkout/cart');
                return $redirect;
            }

            $this->logger->info('Processing PayMongo redirect', [
                'order_id' => $order->getIncrementId(),
                'payment_method' => $order->getPayment()->getMethod(),
            ]);

            // Under the order lock: refuses a cancelled or already-paid order, and closes the page
            // a previous visit opened, so only one PayMongo page can take a payment (PYM-32).
            $page = $this->checkoutStart->openPage($order);

            $this->logger->info('Redirecting to PayMongo checkout', [
                'order_id'          => $order->getIncrementId(),
                'session_id'        => $page['session_id'],
                'payment_intent_id' => $page['payment_intent_id'],
            ]);

            $redirect->setUrl($page['checkout_url']);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->logger->error('PayMongo redirect error', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage($e->getMessage());
            $redirect->setPath('checkout/cart');
        } catch (\Exception $e) {
            $this->logger->error('PayMongo redirect error', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Unable to redirect to payment gateway. Please try again.')
            );
            $redirect->setPath('checkout/cart');
        }

        return $redirect;
    }
}
