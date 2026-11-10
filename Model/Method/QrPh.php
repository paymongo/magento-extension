<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

/**
 * PayMongo QR Ph payment method.
 *
 * Maps to the 'qrph' payment_method_type in the PayMongo API.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class QrPh extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_qrph';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'qrph';
}
