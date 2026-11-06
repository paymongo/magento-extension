/**
 * Collapses the method table's Separate-mode-only columns on Stores > Configuration >
 * PayMongo > Payment Methods.
 *
 * Title, Sort Order and Payment Action only mean something in Separate mode: All-in-One
 * hands the whole payment step to PayMongo's hosted page, which uses neither the per-
 * method title nor its sort order, and cannot mix a card authorisation hold with other
 * payment methods in one session. Each field already hides itself via <depends> in
 * etc/adminhtml/system.xml, but a <th> is not a field, so that leaves three empty
 * columns under live headers — hence the table-level class toggled here, which takes
 * the columns out whole (see view/adminhtml/web/css/styles.css).
 *
 * This only reacts to the admin *changing* the mode. The initial state is rendered
 * server-side by Block\Adminhtml\Render\PaymentMethodsTable, which is what covers store
 * scope: the Payment Display Mode field is default/website-only, so at store scope the
 * bail-out below fires and the server-rendered class is deliberately left untouched.
 * Making that early return "safer" by toggling the class anyway would strip the collapse
 * off every store-scope page.
 *
 * The Card row's Payment Action is hidden in All-in-One but deliberately left on whatever
 * it was. All-in-One Checkout always captures immediately and never reads it (see
 * Model\Order\CaptureMode), so there is nothing to coerce — and keeping it means a
 * merchant who switches back to Separate still has their Authorize Only setting. Up to
 * 2.0.1 this script forced the value to "Authorize & Capture" here instead, which only
 * covered admins who changed the mode on this page in this scope (PYM-24).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        var $mode = $(config.displayModeSelector),
            $table = $(config.tableSelector);

        if (!$mode.length) {
            return;
        }

        /**
         * Match the table's columns to the current mode.
         */
        function sync() {
            $table.toggleClass(config.unifiedClass, $mode.val() === config.unifiedValue);
        }

        $mode.on('change', sync);
        sync();
    };
});
