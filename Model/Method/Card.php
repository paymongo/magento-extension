<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo Credit / Debit Card payment method.
 *
 * Maps to the 'card' payment_method_type in the PayMongo API.
 * Supports authorize-now-capture-later flow (configured per merchant via
 * the payment/paymongo/payment_action admin setting).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Card extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_card';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'card';
}
