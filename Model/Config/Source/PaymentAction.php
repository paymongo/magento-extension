<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the Payment Action admin select field on the Card method.
 *
 * Provides two options:
 * - authorize_capture: charge the card immediately on order placement (default)
 * - authorize: hold the authorisation and capture only when the shipment is created
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentAction implements OptionSourceInterface
{
    public const AUTHORIZE_CAPTURE = 'authorize_capture';
    public const AUTHORIZE = 'authorize';

    /**
     * Return the options array for the admin select field.
     *
     * The labels carry no parenthetical ("charge immediately" / "charge on shipment"):
     * this select sits in a six-column table row, and at those lengths it needed ~275px,
     * which either clipped the label or pushed the whole table into a horizontal scroll
     * on a 1440-wide admin. The timing explanation moved to the field's <comment> in
     * etc/adminhtml/system.xml, which Block\Adminhtml\System\Config\Form\Field\BareInput
     * renders as a hover hint beside the select.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTHORIZE_CAPTURE, 'label' => __('Authorize & Capture')],
            ['value' => self::AUTHORIZE,         'label' => __('Authorize Only')],
        ];
    }
}
