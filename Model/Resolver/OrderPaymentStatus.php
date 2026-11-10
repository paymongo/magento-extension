<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Resolver;

use PayMongo\Payment\Gateway\PaymongoClient;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * GraphQL resolver: paymongoOrderStatus
 *
 * Returns the PayMongo payment status for a placed order. Headless frontends
 * poll this query after redirecting the customer back from PayMongo to confirm
 * the payment completed before showing the success page.
 *
 * Requires a logged-in customer. The order must belong to the authenticated
 * customer. Accepts the order increment ID (e.g. "000000055") — the value
 * returned by the standard placeOrder mutation.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class OrderPaymentStatus implements ResolverInterface
{
    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var SearchCriteriaBuilder */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param PaymongoClient $paymongoClient
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        PaymongoClient $paymongoClient
    ) {
        $this->orderRepository       = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->paymongoClient        = $paymongoClient;
    }

    /**
     * Resolve the paymongoOrderStatus query.
     *
     * Verifies the customer is authenticated, loads the order by increment ID,
     * confirms ownership, then returns the Magento order state plus the PayMongo
     * session status, payment ID, and payment intent ID.
     *
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        if (!$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('Customer is not authenticated.'));
        }

        $orderNumber = trim((string) ($args['order_number'] ?? ''));
        if ($orderNumber === '') {
            throw new GraphQlInputException(__('order_number is required.'));
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderNumber)
            ->create();

        $items = $this->orderRepository->getList($searchCriteria)->getItems();
        $order = reset($items);

        if (!$order) {
            throw new GraphQlNoSuchEntityException(__('Order not found.'));
        }

        if ((int) $order->getCustomerId() !== (int) $context->getUserId()) {
            throw new GraphQlAuthorizationException(__('Order does not belong to the current customer.'));
        }

        $payment        = $order->getPayment();
        $additionalInfo = $payment->getAdditionalInformation() ?: [];
        $sessionId      = (string) ($additionalInfo['paymongo_checkout_session_id'] ?? '');
        $paymentId      = $additionalInfo['paymongo_payment_id'] ?? null;
        $paymentIntentId = $additionalInfo['paymongo_payment_intent_id'] ?? null;
        $sessionStatus  = null;

        if ($sessionId !== '' && $order->getState() !== \Magento\Sales\Model\Order::STATE_PROCESSING) {
            try {
                $session       = $this->paymongoClient->retrieveCheckoutSession($sessionId, (int) $order->getStoreId());
                $sessionStatus = $session['data']['attributes']['status'] ?? null;
            } catch (\Exception) {
                // Non-fatal — return what we have from Magento
            }
        }

        return [
            'order_increment_id' => (string) $order->getIncrementId(),
            'order_state'        => (string) $order->getState(),
            'session_status'     => $sessionStatus,
            'payment_id'         => $paymentId ?: null,
            'payment_intent_id'  => $paymentIntentId ?: null,
        ];
    }
}
