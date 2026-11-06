/**
 * Shared PayMongo payment method renderer for the Luma / standard Magento checkout.
 *
 * All PayMongo methods are redirect-based: after the order is placed, afterPlaceOrder()
 * sends the browser to paymongo/payment/redirect. That controller creates a PayMongo
 * checkout session and forwards the customer to the hosted payment page.
 *
 * redirectAfterPlaceOrder is false so Magento's default success-page redirect is
 * suppressed and afterPlaceOrder() controls where the browser goes next.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url',
    'PayMongo_Payment/js/model/paymongo-group-state'
], function (Component, url, groupState) {
    'use strict';

    return Component.extend({

        defaults: {
            template: 'PayMongo_Payment/payment/paymongo-method',
            redirectAfterPlaceOrder: false
        },

        /**
         * Registers this instance with the shared group state if it's one of PayMongo's
         * Separate-mode individual methods (see isIndividualMethod()). The first live
         * registrant renders the shared group header; see
         * js/model/paymongo-group-state.js for why ownership is a live registry rather
         * than a one-off claim.
         */
        initialize: function () {
            this._super();

            if (this.isIndividualMethod()) {
                groupState.registerMethod(this.getCode());
            }

            return this;
        },

        /**
         * Magento destroys a payment method's renderer as soon as the method stops being
         * available. Release the registration so header ownership can pass to a method
         * that is still on screen.
         */
        destroy: function () {
            if (this.isIndividualMethod()) {
                groupState.unregisterMethod(this.getCode());
            }

            this._super();
        },

        getCode: function () {
            return this.item.method;
        },

        /** True for PayMongo's Separate-mode individual methods, false for the all-in-one "paymongo" method. */
        isIndividualMethod: function () {
            return this.getCode().indexOf('paymongo_') === 0;
        },

        /** Whether THIS instance is the one that renders the shared PayMongo group header. */
        showGroupHeader: function () {
            return this.isIndividualMethod() && groupState.isHeaderOwner(this.getCode());
        },

        /**
         * The group header's own label — "PayMongo" plus a summary of what is inside,
         * e.g. "PayMongo (QRPh, Cards, E-Wallets, Banking, and More)". Built from the
         * methods currently registered rather than hardcoded, so it can never name a
         * payment method this store has switched off. See
         * PayMongo\Payment\Model\Checkout\MethodGroupLabel.
         *
         * @return {String}
         */
        getGroupLabel: function () {
            return groupState.groupLabel();
        },

        /**
         * The group also counts as expanded whenever one of its methods is the selected
         * payment method. Without this, a selected-but-collapsed method renders nothing —
         * no radio and no Place Order button — which happens routinely: Magento
         * auto-selects the method on a single-method store, and checkout-data-resolver
         * re-selects the remembered method when a customer returns from an abandoned
         * redirect. Reading isChecked() here registers the KO dependency that re-evaluates
         * this on every selection change.
         */
        isGroupExpanded: function () {
            return groupState.isExpanded() || groupState.isGroupedMethodCode(this.isChecked());
        },

        toggleGroup: function () {
            groupState.toggle();
        },

        /**
         * Keyboard equivalent of clicking the group header. The header carries role="button"
         * and tabindex="0", so Enter and Space have to be wired up by hand.
         *
         * @param {Object} data
         * @param {KeyboardEvent} event
         * @return {Boolean} false to swallow the event (stops Space scrolling the page)
         */
        toggleGroupOnKey: function (data, event) {
            if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                this.toggleGroup();

                return false;
            }

            return true;
        },

        getTitle: function () {
            return this.item.title;
        },

        isRedirectAfterPlaceOrder: function () {
            return true;
        },

        afterPlaceOrder: function () {
            window.location.replace(url.build('paymongo/payment/redirect'));
        },

        /**
         * Whether PayMongo method icons are enabled (Stores > Configuration > PayMongo >
         * Payment Methods > Checkout Display > Show Payment Method Icons). Populated by
         * Model\ConfigProvider.
         */
        iconsEnabled: function () {
            return !!(window.checkoutConfig.payment.paymongoIcons
                && window.checkoutConfig.payment.paymongoIcons.enabled);
        },

        isCardMethod: function () {
            return this.getCode() === 'paymongo_card';
        },

        /** The all-in-one method, shown as up to 2 logos + a "+N" badge via getUnifiedIcons(). */
        isUnifiedMethod: function () {
            return this.getCode() === 'paymongo';
        },

        /** Single icon URL for every method except Card/the unified method (they show multiple). */
        getIconUrl: function () {
            var icons = window.checkoutConfig.payment.paymongoIcons || {};
            return (icons.image || {})[this.getCode()] || '';
        },

        /** Visa + Mastercard, shown as two separate images only for the Card method. */
        getCardImages: function () {
            var icons = window.checkoutConfig.payment.paymongoIcons || {};
            return icons.cardImages || [];
        },

        /**
         * Up to 2 logos for whichever individual methods are currently enabled, shown
         * only for the all-in-one method. Populated by Model\ConfigProvider via
         * Model\Checkout\EnabledMethodTypes — mirrors Hyva Checkout's
         * Block\Checkout\Payment\AllInOneMethodIcon.
         */
        getUnifiedIcons: function () {
            var icons = window.checkoutConfig.payment.paymongoIcons || {};
            return icons.unifiedIcons || [];
        },

        /** How many more individual methods are enabled beyond getUnifiedIcons(). */
        getUnifiedOverflow: function () {
            var icons = window.checkoutConfig.payment.paymongoIcons || {};
            return icons.unifiedOverflow || 0;
        }
    });
});
