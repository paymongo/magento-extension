/**
 * Shared state for grouping PayMongo's Separate-mode individual payment methods
 * (paymongo_card, paymongo_gcash, etc.) under one collapsible header on Luma /
 * standard checkout — labelled with what the group holds, e.g.
 * "PayMongo (QRPh, Cards, E-Wallets, Banking, and More)", which is why the registry
 * below feeds groupLabel as well as header ownership.
 *
 * Luma renders each payment method as a fully independent KO component (unlike Hyva
 * Checkout, which iterates a single shared method-list template) — there's no single
 * place to intercept and group them. Instead, every individual-method component reads
 * this shared observable for its expanded/collapsed visibility, and registers itself
 * here so that exactly one of them renders the shared header + toggle.
 *
 * Ownership is derived from a live registry rather than latched to the first claimant.
 * Magento drops a payment method mid-session whenever it stops being available (a coupon
 * pushing the total under a method's min_order_total, a country change, and so on) and
 * destroys its renderer, while deliberately preserving the object references of the
 * methods that survive — so survivors are never re-initialized and would never get the
 * chance to re-claim. A latched owner therefore took the header with it when it was
 * dropped, leaving every remaining PayMongo method collapsed with no way to expand it.
 */
define([
    'ko',
    'PayMongo_Payment/js/checkout/paymongo-group-label'
], function (ko, labelBuilder) {
    'use strict';

    var isExpanded = ko.observable(false),
        // Registration order, which follows Magento's own render order for the region.
        registeredCodes = ko.observableArray([]);

    return {
        isExpanded: isExpanded,

        /**
         * The header's label, summarising the methods currently registered — so it never
         * advertises a group whose only method has just been dropped from the checkout.
         *
         * Reading registeredCodes() is what makes this re-evaluate: pureComputed also
         * defers the first evaluation until the header actually binds, by which point
         * window.checkoutConfig is populated and every method has registered.
         */
        groupLabel: ko.pureComputed(function () {
            var payment = (window.checkoutConfig || {}).payment || {};

            return labelBuilder.build(registeredCodes(), payment.paymongoGroup);
        }),

        toggle: function () {
            isExpanded(!isExpanded());
        },

        /**
         * @param {String} code
         * @return {void}
         */
        registerMethod: function (code) {
            if (registeredCodes.indexOf(code) === -1) {
                registeredCodes.push(code);
            }
        },

        /**
         * Called when a renderer is destroyed, so the header passes to the next live
         * method instead of disappearing with the one that went away.
         *
         * @param {String} code
         * @return {void}
         */
        unregisterMethod: function (code) {
            registeredCodes.remove(code);
        },

        /**
         * Observable-backed, so the header re-renders on whichever method inherits it.
         *
         * @param {String} code
         * @return {Boolean}
         */
        isHeaderOwner: function (code) {
            var codes = registeredCodes();

            return codes.length > 0 && codes[0] === code;
        },

        /**
         * @param {String|null} code currently selected payment method code
         * @return {Boolean} whether the selection is one of the grouped individual methods
         */
        isGroupedMethodCode: function (code) {
            return !!code && code.indexOf('paymongo_') === 0;
        }
    };
});
