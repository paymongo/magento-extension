<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Model\Checkout\EnabledMethodTypes;
use PayMongo\Payment\Model\Checkout\MethodGroupLabel;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * Supplies PayMongo payment method icon URLs to Luma / standard checkout, so merchants
 * not using Hyva Checkout still get the same icons shown there (see
 * PayMongo_PaymentHyva's view/frontend/layout/hyva_checkout_components.xml and
 * PayMongo\PaymentHyva\Plugin\Checkout\MultiLogoIconRenderer for the Hyva Checkout side of this).
 *
 * Also supplies the data behind the collapsed group header's label (see
 * Model\Checkout\MethodGroupLabel) under a separate window.checkoutConfig.payment
 * .paymongoGroup key — deliberately not nested inside paymongoIcons, since the label
 * is shown whether or not method icons are switched on.
 *
 * Read by view/frontend/web/js/view/payment/method-renderer/paymongo-method.js via
 * window.checkoutConfig.payment.paymongoIcons. Card gets two separate images (Visa and
 * Mastercard) since, unlike Hyva Checkout, Luma's KO template can render as many <img>
 * tags as needed — there's no one-image-per-method limitation to work around here.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class ConfigProvider implements ConfigProviderInterface
{
    private const ASSET_PATH = 'PayMongo_Payment::images/methods/';

    /**
     * Method code -> icon asset filename (relative to ASSET_PATH). Kept in sync with the
     * per-method icons registered for Hyva Checkout in PayMongo_PaymentHyva's
     * hyva_checkout_components.xml.
     * "paymongo" (all-in-one) uses a generic placeholder glyph since no dedicated logo
     * was provided for it.
     *
     * @var array<string, string>
     */
    private const ICONS = [
        'paymongo' => 'wallet-generic.svg',
        'paymongo_gcash' => 'gcash.webp',
        'paymongo_grab_pay' => 'grabpay.webp',
        'paymongo_maya' => 'maya.webp',
        'paymongo_google_pay' => 'google-pay.webp',
        'paymongo_shopeepay' => 'shopeepay.webp',
        'paymongo_billease' => 'billease.webp',
        'paymongo_bpi_online' => 'bpi.webp',
        'paymongo_unionbank_online' => 'unionbank.webp',
        'paymongo_qrph' => 'qrph.webp',
    ];

    /** @var Config */
    private Config $config;

    /** @var AssetRepository */
    private AssetRepository $assetRepository;

    /** @var EnabledMethodTypes */
    private EnabledMethodTypes $enabledMethodTypes;

    /** @var MethodGroupLabel */
    private MethodGroupLabel $methodGroupLabel;

    /**
     * @param Config $config
     * @param AssetRepository $assetRepository
     * @param EnabledMethodTypes $enabledMethodTypes
     * @param MethodGroupLabel $methodGroupLabel
     */
    public function __construct(
        Config $config,
        AssetRepository $assetRepository,
        EnabledMethodTypes $enabledMethodTypes,
        MethodGroupLabel $methodGroupLabel
    ) {
        $this->config = $config;
        $this->assetRepository = $assetRepository;
        $this->enabledMethodTypes = $enabledMethodTypes;
        $this->methodGroupLabel = $methodGroupLabel;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'payment' => [
                'paymongoIcons' => $this->getIconConfig(),
                'paymongoGroup' => $this->methodGroupLabel->getJsConfig(),
            ],
        ];
    }

    /**
     * @return array
     */
    private function getIconConfig(): array
    {
        if (!$this->config->showPaymentIcons()) {
            return [
                'enabled' => false,
                'image' => [],
                'cardImages' => [],
                'unifiedIcons' => [],
                'unifiedOverflow' => 0,
            ];
        }

        $images = [];
        foreach (self::ICONS as $code => $file) {
            $images[$code] = $this->assetRepository->getUrl(self::ASSET_PATH . $file);
        }

        // The all-in-one "paymongo" method shows up to 2 real logos for whichever
        // individual methods are currently enabled, plus a count of the rest, instead of
        // the single generic icon in $images['paymongo'] above (kept only as a fallback
        // for the rare case no enabled method resolves to a known icon).
        $result = $this->enabledMethodTypes->getIconEntries(2);
        $unifiedIcons = array_map(
            fn (array $icon): array => [
                'src' => $this->assetRepository->getUrl(self::ASSET_PATH . $icon['file']),
                'alt' => $icon['label'],
            ],
            $result['icons']
        );

        return [
            'enabled' => true,
            'image' => $images,
            'cardImages' => [
                ['src' => $this->assetRepository->getUrl(self::ASSET_PATH . 'visa.webp'), 'alt' => 'Visa'],
                ['src' => $this->assetRepository->getUrl(self::ASSET_PATH . 'mastercard.webp'), 'alt' => 'Mastercard'],
            ],
            'unifiedIcons' => $unifiedIcons,
            'unifiedOverflow' => $result['overflow'],
        ];
    }
}
