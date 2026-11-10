<?php

declare(strict_types=1);

namespace PayMongo\Payment\Logger;

/**
 * Custom logger instance for the PayMongo payment module.
 *
 * Injects the dedicated Monolog channel so that all PayMongo log entries
 * are written to var/log/payment.paymongo.log instead of the default system log.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Logger extends \Monolog\Logger
{
}
