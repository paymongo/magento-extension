<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo Maya payment method (formerly PayMaya).
 *
 * Maps to the 'paymaya' payment_method_type in the PayMongo API.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Maya extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_maya';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'paymaya';
}
