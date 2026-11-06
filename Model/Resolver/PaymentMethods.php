<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Resolver;

use PayMongo\Payment\Model\Method\AbstractPaymongoMethod;
use PayMongo\Payment\Model\PaymongoPayment;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * GraphQL resolver: paymongoPaymentMethods
 *
 * Returns all PayMongo payment methods that are currently enabled for the
 * resolved store. Headless frontends use this to render the payment step —
 * show only the methods this query returns; use their codes as input to
 * createPaymongoCheckoutSession.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentMethods implements ResolverInterface
{
    /** @var PaymentHelper */
    private PaymentHelper $paymentHelper;

    /**
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(PaymentHelper $paymentHelper)
    {
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Resolve the paymongoPaymentMethods query.
     *
     * Iterates all registered payment methods, filters to those that are
     * instances of AbstractPaymongoMethod and are currently available,
     * and returns code, title, and paymongo_type for each.
     *
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $methods = [];

        foreach ($this->paymentHelper->getPaymentMethods() as $code => $data) {
            try {
                $instance = $this->paymentHelper->getMethodInstance((string) $code);
            } catch (\Exception $e) {
                continue;
            }

            $isAbstractPaymongoMethod = $instance instanceof AbstractPaymongoMethod;
            if (!$isAbstractPaymongoMethod && !$instance instanceof PaymongoPayment) {
                continue;
            }

            if (!$instance->isAvailable()) {
                continue;
            }

            $methods[] = [
                'code' => $code,
                'title' => $data['title'] ?? $instance->getTitle(),
                // The all-in-one method has no single PayMongo type — it presents whichever
                // methods the merchant has enabled on one hosted page. Report it as "multi",
                // matching what createPaymongoCheckoutSession returns for the same method,
                // rather than an empty string a client might forward to the API verbatim.
                'paymongo_type' => $isAbstractPaymongoMethod ? $instance->getPaymongoMethodType() : 'multi',
            ];
        }

        return $methods;
    }
}
