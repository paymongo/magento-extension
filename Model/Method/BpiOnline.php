<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo BPI Online Banking payment method.
 *
 * Maps to the 'dob' payment_method_type in the PayMongo API, and goes through
 * Checkout Sessions like every other method. It used to use the Payment Intent flow
 * ('dob' + bank_code 'bpi'), which PayMongo can refuse with "Dob payment method on
 * bpi is not allowed for your account." even where a Checkout Session offering 'dob'
 * goes through, so BPI is offered that way instead (PYM-6).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class BpiOnline extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_bpi_online';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'dob';
}
