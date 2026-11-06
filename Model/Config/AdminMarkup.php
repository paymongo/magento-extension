<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Config;

/**
 * The admin-side element ids and table class names that more than one file has to agree
 * on, kept in one place so they cannot drift apart silently.
 *
 * Both halves of the Payment Methods screen are string-matched rather than traversed:
 * Block\Adminhtml\Render\PaymentMethodsTable looks for the Payment Action field in
 * already-rendered HTML to decide whether that column exists in the current scope, and
 * view/adminhtml/templates/system/config/payment-action-mode.phtml hands the same ids to
 * js/payment-action-mode.js as CSS selectors. Neither raises anything when a name stops
 * matching — the column quietly reverts to a header over eleven em dashes, or the script
 * quietly stops toggling — so a typo would have been invisible until someone noticed the
 * screen looked wrong.
 *
 * FIELD_* values are the ids Magento derives from etc/adminhtml/system.xml as
 * "<section>_<group>_<field>"; renaming a section, group or field there means changing
 * them here. TABLE_* values are mirrored in view/adminhtml/web/css/styles.css, which is
 * the one copy that cannot import these constants.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AdminMarkup
{
    /** @var string Payment Display Mode select (General group of the Payment Methods section) */
    public const FIELD_DISPLAY_MODE = 'paymongo_payment_methods_info_display_mode';

    /** @var string Payment Action select, which only the Card group defines */
    public const FIELD_CARD_PAYMENT_ACTION = 'paymongo_payment_methods_card_payment_action';

    /** @var string The flat method table itself */
    public const TABLE = 'paymongo-methods-table';

    /** @var string Modifier hiding the columns All-in-One Checkout has no use for */
    public const TABLE_UNIFIED = 'paymongo-methods-table--unified';

    /** @var string Modifier hiding Payment Action where the field is out of scope */
    public const TABLE_NO_ACTION = 'paymongo-methods-table--no-action';

    /**
     * Return an id as a CSS id selector, for handing to JavaScript.
     *
     * @param string $fieldId
     * @return string
     */
    public static function selector(string $fieldId): string
    {
        return '#' . $fieldId;
    }
}
