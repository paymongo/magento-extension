<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

/**
 * Another process held the order's lock for the whole wait, so the work was not done.
 *
 * The webhook answers PayMongo with a non-2xx response and does not mark the event processed,
 * so PayMongo delivers it again later (PYM-32). Before, a busy order was logged and answered
 * 200, and a payment PayMongo reported at that moment was never confirmed.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class OrderBusyException extends \RuntimeException
{
}
