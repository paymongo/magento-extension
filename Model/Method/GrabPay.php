<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo GrabPay payment method.
 *
 * Maps to the 'grab_pay' payment_method_type in the PayMongo API.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class GrabPay extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_grab_pay';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'grab_pay';
}
