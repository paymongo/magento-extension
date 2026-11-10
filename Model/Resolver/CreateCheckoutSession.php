<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Resolver;

use PayMongo\Payment\Model\Method\AbstractPaymongoMethod;
use PayMongo\Payment\Model\Order\CheckoutStart;
use PayMongo\Payment\Model\PaymongoPayment;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * GraphQL resolver: createPaymongoCheckoutSession
 *
 * Creates a PayMongo hosted checkout session for a cart that has already had
 * its order placed (the mutation is called after placeOrder). The caller
 * receives a checkout_url to redirect the customer to.
 *
 * Headless flow:
 *   1. placeOrder (standard Magento mutation)
 *   2. createPaymongoCheckoutSession(input: {cart_id, method_code})
 *   3. Redirect customer to checkout_url
 *   4. PayMongo calls success_url / cancel_url on return
 *   5. Poll paymongoOrderStatus until order_state === 'processing'
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CreateCheckoutSession implements ResolverInterface
{

    /** @var PaymentHelper */
    private PaymentHelper $paymentHelper;

    /** @var MaskedQuoteIdToQuoteIdInterface */
    private MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId;

    /** @var CartRepositoryInterface */
    private CartRepositoryInterface $cartRepository;

    /** @var OrderCollectionFactory */
    private OrderCollectionFactory $orderCollectionFactory;

    /** @var CheckoutStart */
    private CheckoutStart $checkoutStart;

    /**
     * @param PaymentHelper $paymentHelper
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param CartRepositoryInterface $cartRepository
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param CheckoutStart $checkoutStart
     */
    public function __construct(
        PaymentHelper $paymentHelper,
        MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        CartRepositoryInterface $cartRepository,
        OrderCollectionFactory $orderCollectionFactory,
        CheckoutStart $checkoutStart
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->cartRepository = $cartRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->checkoutStart = $checkoutStart;
    }

    /**
     * Resolve the createPaymongoCheckoutSession mutation.
     *
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $input      = $args['input'] ?? [];
        $maskedId   = $input['cart_id'] ?? '';
        $methodCode = $input['method_code'] ?? '';

        if (empty($maskedId) || empty($methodCode)) {
            throw new GraphQlInputException(__('cart_id and method_code are required.'));
        }

        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedId);
            $quote   = $this->cartRepository->get($quoteId);
        } catch (\Exception $e) {
            throw new GraphQlNoSuchEntityException(__('Cart not found.'));
        }

        $order = $this->resolveOrderForQuote((int) $quoteId);
        if (!$order) {
            throw new GraphQlNoSuchEntityException(
                __('No order found for this cart. Please call placeOrder first.')
            );
        }

        // The page is for the order's own payment method. Before PYM-33 the method_code sent here
        // decided what the page offered and whether it held the card, so a storefront could open
        // a card page, even an Authorize Only one, for an order placed with GCash.
        if ($methodCode !== (string) $order->getPayment()->getMethod()) {
            throw new GraphQlInputException(__('method_code does not match the payment method of the order.'));
        }
        $instance = $this->resolvePaymongoMethod($methodCode);

        // Under the order lock: refuses a cancelled or already-paid order, and closes the page an
        // earlier call opened, so only one PayMongo page can take a payment (PYM-32). What the page
        // offers, and whether it holds the card, come from the order (CheckoutStart::openPage()).
        try {
            $page = $this->checkoutStart->openPage($order);
            $sessionId = $page['session_id'];
            $checkoutUrl = $page['checkout_url'];
        } catch (\PayMongo\Payment\Gateway\PaymongoApiException $e) {
            // PayMongo's raw response stays in the PayMongo log, never in the storefront's errors.
            throw new GraphQlInputException(__('Unable to create the PayMongo payment page. Please try again.'), $e);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // CheckoutStart's messages are written for the customer.
            throw new GraphQlInputException(__('%1', $e->getMessage()), $e);
        }

        return [
            'session_id'          => $sessionId,
            'checkout_url'        => $checkoutUrl,
            // The method's own type, as paymongoPaymentMethods reports it — not the session's
            // types list, which for an order placed with the retired Direct Online Banking
            // can carry both banks. 'multi' is kept for the all-in-one method alone.
            'payment_method_type' => $instance !== null && $instance->getPaymongoMethodType() !== ''
                ? $instance->getPaymongoMethodType()
                : 'multi',
        ];
    }

    /**
     * Find the most recent order placed from the given quote.
     *
     * @param int $quoteId
     * @return \Magento\Sales\Model\Order|null
     */
    private function resolveOrderForQuote(int $quoteId): ?\Magento\Sales\Model\Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('quote_id', $quoteId);
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);

        $order = $collection->getFirstItem();
        return $order && $order->getId() ? $order : null;
    }

    /**
     * Resolve the individual PayMongo method behind a Magento method code.
     *
     * @param string $methodCode
     * @return AbstractPaymongoMethod|null null for the all-in-one method or a non-PayMongo code
     */
    private function resolvePaymongoMethod(string $methodCode): ?AbstractPaymongoMethod
    {
        if ($methodCode === PaymongoPayment::CODE) {
            return null;
        }

        try {
            $instance = $this->paymentHelper->getMethodInstance($methodCode);
        } catch (\Exception $e) {
            return null;
        }

        return $instance instanceof AbstractPaymongoMethod ? $instance : null;
    }
}
