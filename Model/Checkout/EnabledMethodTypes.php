<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Checkout;

use PayMongo\Payment\Model\Method\AbstractPaymongoMethod;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Determines which individual PayMongo payment methods are currently enabled,
 * independent of Payment Display Mode — each individual method's own isAvailable()
 * hides it in All-in-One mode, so its "Enabled" config value has to be read directly
 * instead of relying on isAvailable(). This is the same logic
 * Controller\Payment\Redirect uses to build the actual PayMongo API
 * payment_method_types list for the all-in-one checkout session, factored out here so
 * it can also decide which logos to show on the all-in-one method's own checkout icon
 * (see Block\Checkout\Payment\AllInOneMethodIcon and Model\ConfigProvider).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class EnabledMethodTypes
{
    /**
     * PayMongo API type -> list of [icon asset filename (relative to
     * view/frontend/web/images/methods/), human label] entries. Every type has exactly
     * one entry except 'card', which has two (Visa, Mastercard) — matching the same
     * separate-logo treatment Card's own standalone checkout icon uses (see
     * PayMongo\PaymentHyva\Plugin\Checkout\MultiLogoIconRenderer), rather than a single combined image.
     * bpi_online and unionbank_online collapse into a single "Online Banking" entry
     * here — unionbank_online through ICON_TYPE_ALIASES, since its own type is 'dob_ubp'.
     *
     * @var array<string, array<int, array{file: string, label: string}>>
     */
    private const TYPE_ICONS = [
        'card' => [
            ['file' => 'visa.webp', 'label' => 'Visa'],
            ['file' => 'mastercard.webp', 'label' => 'Mastercard'],
        ],
        'gcash' => [['file' => 'gcash.webp', 'label' => 'GCash']],
        'grab_pay' => [['file' => 'grabpay.webp', 'label' => 'GrabPay']],
        'paymaya' => [['file' => 'maya.webp', 'label' => 'Maya']],
        'google_pay_card' => [['file' => 'google-pay.webp', 'label' => 'Google Pay']],
        'shopee_pay' => [['file' => 'shopeepay.webp', 'label' => 'ShopeePay']],
        'billease' => [['file' => 'billease.webp', 'label' => 'BillEase']],
        'dob' => [['file' => 'bank-generic.svg', 'label' => 'Online Banking']],
        'qrph' => [['file' => 'qrph.webp', 'label' => 'QR Ph']],
    ];

    /**
     * PayMongo API type -> the TYPE_ICONS entry it is shown under, for a type that has no
     * icon of its own.
     *
     * @var array<string, string>
     */
    private const ICON_TYPE_ALIASES = [
        'dob_ubp' => 'dob',
    ];

    /** @var PaymentHelper */
    private PaymentHelper $paymentHelper;

    /**
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(PaymentHelper $paymentHelper)
    {
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Ordered by each method's Sort Order — the same order Separate mode lists them in at
     * checkout — so All-in-One shows the same priority (QR Ph, Cards, GCash first by
     * default, PYM-21) in the logos that win the "+N" cut and in the payment_method_types
     * list sent to PayMongo's hosted page. Methods sharing a Sort Order keep config
     * declaration order (usort is stable).
     *
     * @return array<int, string> Deduplicated PayMongo API types (e.g. ['qrph', 'card'])
     */
    public function getEnabledTypes(): array
    {
        $types = [];
        foreach ($this->getEnabledMethods() as $method) {
            foreach ($method->getCheckoutSessionTypes() as $type) {
                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * Every individually-enabled PayMongo method, in Sort Order (see getEnabledTypes()).
     *
     * @return AbstractPaymongoMethod[]
     */
    private function getEnabledMethods(): array
    {
        $methods = [];

        foreach ($this->paymentHelper->getPaymentMethods() as $code => $data) {
            try {
                $instance = $this->paymentHelper->getMethodInstance((string) $code);
            } catch (\Exception $e) {
                continue;
            }

            if (!$instance instanceof AbstractPaymongoMethod) {
                continue;
            }

            // isActive() rather than the raw config value, so a retired method that
            // answers false (Method\Dob) stays out whatever its stored flag says.
            if (!$instance->isActive()) {
                continue;
            }

            $methods[] = $instance;
        }

        usort(
            $methods,
            static fn (AbstractPaymongoMethod $a, AbstractPaymongoMethod $b): int =>
                (int) $a->getConfigData('sort_order') <=> (int) $b->getConfigData('sort_order')
        );

        return $methods;
    }

    /**
     * The first $limit enabled METHODS' icons (flattened — Card contributes two entries,
     * Visa and Mastercard, everything else contributes one), plus how many more methods
     * (not images) are enabled beyond that — used to show e.g. 2 method icons and a "+5"
     * badge on the all-in-one method's icon. $limit counts methods, not resulting images,
     * so if Card is one of the $limit shown, the actual image count returned is one
     * higher than $limit.
     *
     * Kept intentionally small (2, not 3) — this row has to fit next to the "PayMongo"
     * label on one line at every checkout width down to the narrowest phones; the fewer
     * images it needs room for, the less often it has to wrap into a tall stacked column
     * (see web/css/checkout-icons.css, which additionally hides the icon row entirely
     * below ~380px rather than let it wrap at all).
     *
     * @param int $limit
     * @return array{icons: array<int, array{type: string, file: string, label: string}>, overflow: int}
     */
    public function getIconEntries(int $limit = 2): array
    {
        // Keyed by each method's own type, not its Checkout Session types — a method
        // counts as one here however many types its session offers.
        $known = [];
        foreach ($this->getEnabledMethods() as $method) {
            $type = $method->getPaymongoMethodType();
            $type = self::ICON_TYPE_ALIASES[$type] ?? $type;
            if (isset(self::TYPE_ICONS[$type]) && !in_array($type, $known, true)) {
                $known[] = $type;
            }
        }

        $shown = array_slice($known, 0, $limit);
        $overflow = max(0, count($known) - count($shown));

        $icons = [];
        foreach ($shown as $type) {
            foreach (self::TYPE_ICONS[$type] as $entry) {
                $icons[] = array_merge(['type' => $type], $entry);
            }
        }

        return ['icons' => $icons, 'overflow' => $overflow];
    }
}
