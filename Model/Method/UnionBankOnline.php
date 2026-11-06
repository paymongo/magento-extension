<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo UnionBank Online Banking payment method.
 *
 * Maps to the 'dob_ubp' payment_method_type in the PayMongo API, and goes through
 * Checkout Sessions like every other method. It used to share BPI's Payment Intent
 * flow ('dob' + bank_code 'ubp'), which PayMongo can refuse with "Dob payment method
 * on ubp is not allowed for your account." even where a Checkout Session offering
 * 'dob_ubp' goes through, so UnionBank is offered that way instead (PYM-6).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class UnionBankOnline extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_unionbank_online';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'dob_ubp';
}
