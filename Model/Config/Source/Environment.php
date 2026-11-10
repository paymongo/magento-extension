<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the Environment admin select field.
 *
 * Determines which stored credential set (Sandbox or Production) the module
 * actually uses at runtime — see Helper\Config::getSecretKey()/getPublicKey()/
 * getWebhookSecret().
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Environment implements OptionSourceInterface
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    /**
     * Return the options array for the admin select field.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox (Test Mode)')],
            ['value' => self::PRODUCTION, 'label' => __('Production (Live Mode)')],
        ];
    }
}
