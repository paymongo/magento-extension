<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the Payment Display Mode admin select field.
 *
 * Provides two options:
 * - separate: each enabled PayMongo method (Card, GCash, GrabPay, etc.) appears
 *   individually at checkout (default)
 * - unified: a single "PayMongo" option appears at checkout, opening a hosted
 *   PayMongo page listing every individually-enabled method together
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentDisplayMode implements OptionSourceInterface
{
    public const SEPARATE = 'separate';
    public const UNIFIED = 'unified';

    /**
     * Return the options array for the admin select field.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SEPARATE, 'label' => __('Separate Payment Methods')],
            ['value' => self::UNIFIED,  'label' => __('All-in-One Checkout')],
        ];
    }
}
