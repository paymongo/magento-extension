<?php

declare(strict_types=1);

namespace PayMongo\Payment\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Monolog handler that writes PayMongo log entries to a dedicated log file.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Handler extends Base
{
    /**
     * Minimum log level captured by this handler.
     *
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * Path to the log file relative to the Magento root.
     *
     * @var string
     */
    protected $fileName = '/var/log/payment.paymongo.log';
}
