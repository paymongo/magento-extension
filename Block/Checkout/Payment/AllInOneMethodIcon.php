<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Checkout\Payment;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Model\Checkout\EnabledMethodTypes;
use Magento\Framework\View\Element\Template\Context;

/**
 * Icon metadata for the all-in-one "paymongo" method specifically: instead of one
 * static generic icon, shows up to 2 real logos for whichever individual methods are
 * currently enabled (see Model\Checkout\EnabledMethodTypes), plus a "+N" badge for any
 * more enabled beyond that — via the same 'multi_src' mechanism Card's Visa/Mastercard
 * icons use (see PayMongo\PaymentHyva\Plugin\Checkout\MultiLogoIconRenderer).
 *
 * Falls back to the static generic icon set in layout XML if, for whatever reason, no
 * enabled methods resolve to a known icon (e.g. every individual method disabled).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AllInOneMethodIcon extends MethodIcon
{
    private const ASSET_PATH = 'PayMongo_Payment::images/methods/';

    /** @var EnabledMethodTypes */
    private EnabledMethodTypes $enabledMethodTypes;

    /**
     * @param Context $context
     * @param Config $config
     * @param EnabledMethodTypes $enabledMethodTypes
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        EnabledMethodTypes $enabledMethodTypes,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);
        $this->enabledMethodTypes = $enabledMethodTypes;
    }

    /**
     * @param string $key
     * @param int|string|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null)
    {
        if ($key !== 'metadata') {
            return parent::getData($key, $index);
        }

        // Respects the "Show Payment Method Icons" toggle via the parent first.
        $fallback = parent::getData('metadata', $index);
        if (empty($fallback)) {
            return [];
        }

        $result = $this->enabledMethodTypes->getIconEntries(2);
        if (empty($result['icons'])) {
            return $fallback;
        }

        $multiSrc = [];
        foreach ($result['icons'] as $icon) {
            $multiSrc[] = [
                'src' => self::ASSET_PATH . $icon['file'],
                'alt' => $icon['label'],
            ];
        }
        if ($result['overflow'] > 0) {
            $multiSrc[] = ['badge' => '+' . $result['overflow']];
        }

        return [
            'icon' => [
                'multi_src' => $multiSrc,
                'attributes' => [
                    'class' => 'paymongo-icon',
                ],
            ],
        ];
    }
}
