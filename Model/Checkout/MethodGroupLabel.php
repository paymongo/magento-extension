<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Checkout;

use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Supplies the data behind the collapsed group header's label on checkout — the
 * "PayMongo (QRPh, Cards, E-Wallets, Banking, and More)" summary that tells a shopper
 * what is inside the group before they open it.
 *
 * The label is deliberately NOT a fixed string. Every store chooses which individual
 * methods to switch on, so a hardcoded list promises methods a shopper will not find:
 * turn QR Ph off and a static label still advertises QRPh. The label is therefore built
 * from the method codes that actually rendered in the group, which is also why the
 * joining happens in JavaScript rather than here — see
 * web/js/checkout/paymongo-group-label.js.
 *
 * Both checkouts render that group from the rows the active theme already produced, and
 * the set of rows is only known client-side:
 *
 *  - Luma keeps a live registry of the individual methods currently on screen
 *    (web/js/model/paymongo-group-state.js). Magento drops a method mid-session when it
 *    stops being available — a coupon pushing the total under a min_order_total, a
 *    country change — without a page reload, so a label computed here at page render
 *    would go on naming a group whose only method has just vanished.
 *  - Hyvä Checkout finds the rendered rows in the DOM and re-reads them after every
 *    Magewire round-trip (web/js/checkout/group-paymongo-methods.js).
 *
 * So this class owns the *data* — group order, translated group labels, the method
 * code -> group mapping and the joining patterns — and the shared JS module owns the
 * *joining*. Keeping the two in one place each avoids the same list-formatting rules
 * being written twice in two languages.
 *
 * Implements ArgumentInterface so it can be passed straight to the Hyvä script-loader
 * block as a view model, rather than through a Model/ViewModel wrapper whose only
 * behaviour would be to forward getJsConfig(). Luma reads the same array through
 * Model\ConfigProvider.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MethodGroupLabel implements ArgumentInterface
{
    /** @var string The group key used for methods that belong to no named group. */
    private const OVERFLOW_GROUP = 'more';

    /**
     * Group key -> shopper-facing label, in the order they are listed. The order is the
     * client's own wording from PYM-16 and is fixed rather than derived from sort_order:
     * the label reads as a sentence, so it must not reshuffle itself when a merchant
     * reorders methods in the admin.
     *
     * "QRPh" is spelled as the client specified it in the ticket, which is not the
     * "QR Ph" used by the method's own title and icon alt text elsewhere in the module.
     *
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'qrph' => 'QRPh',
        'card' => 'Cards',
        'wallet' => 'E-Wallets',
        'banking' => 'Banking',
        self::OVERFLOW_GROUP => 'More',
    ];

    /**
     * Payment method code -> group key. A code missing from this map contributes the
     * overflow group ("and More"), so a method added later still shows up in the label
     * as something rather than being silently unrepresented.
     *
     * Google Pay sits under E-Wallets even though its PayMongo payment_method_type is
     * 'google_pay_card' and it is settled as a card — a shopper looking for Google Pay
     * looks for a wallet, not a card form.
     *
     * bpi_online and unionbank_online both read as Banking, so they collapse into one
     * group entry here — even though unionbank_online uses PayMongo's 'dob_ubp' type
     * rather than BPI's 'dob'.
     *
     * @var array<string, string>
     */
    private const CODE_GROUPS = [
        'paymongo_qrph' => 'qrph',
        'paymongo_card' => 'card',
        'paymongo_gcash' => 'wallet',
        'paymongo_grab_pay' => 'wallet',
        'paymongo_maya' => 'wallet',
        'paymongo_shopeepay' => 'wallet',
        'paymongo_google_pay' => 'wallet',
        'paymongo_bpi_online' => 'banking',
        'paymongo_unionbank_online' => 'banking',
        'paymongo_billease' => self::OVERFLOW_GROUP,
    ];

    /**
     * Everything the JS label builder needs, ready to be JSON-encoded.
     *
     * The list-joining patterns are separate translatable strings rather than one
     * pattern with a variable number of placeholders, because English needs the comma
     * before "and" only from three items up ("Cards and More", but "Cards, E-Wallets,
     * and More") and other locales draw that line elsewhere.
     *
     * @return array{
     *     wrapper: string,
     *     fallback: string,
     *     pair: string,
     *     series: string,
     *     separator: string,
     *     overflow: string,
     *     order: array<int, string>,
     *     labels: array<string, string>,
     *     codeGroups: array<string, string>
     * }
     */
    public function getJsConfig(): array
    {
        $labels = [];
        foreach (self::GROUP_LABELS as $key => $label) {
            $labels[$key] = (string) __($label);
        }

        return [
            // "PayMongo" stays outside the translatable part of the pattern — it is the
            // brand, not a word — but the parentheses' placement is translatable, since
            // not every locale brackets a trailing qualifier the same way.
            'wrapper' => (string) __('PayMongo (%1)'),
            // Shown when the group's contents resolve to nothing worth listing: no
            // recognised group at all, or only the overflow group, where
            // "PayMongo (More)" would say less than the bare brand name does.
            'fallback' => (string) __('PayMongo'),
            'pair' => (string) __('%1 and %2'),
            'series' => (string) __('%1, and %2'),
            'separator' => ', ',
            'overflow' => self::OVERFLOW_GROUP,
            'order' => array_keys(self::GROUP_LABELS),
            'labels' => $labels,
            'codeGroups' => self::CODE_GROUPS,
        ];
    }
}
