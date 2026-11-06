/**
 * Shows a notice in the payment step when the cart currency is not PHP.
 *
 * PayMongo only processes PHP. When a non-PHP currency is active all PayMongo
 * payment methods are hidden by isAvailable(). This component makes the reason
 * explicit to the customer instead of leaving them wondering why no methods appear.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'ko'
], function (Component, quote, ko) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PayMongo_Payment/payment/paymongo-currency-notice'
        },

        isVisible: ko.observable(false),

        initialize: function () {
            this._super();

            var self = this;

            var checkCurrency = function (totals) {
                if (totals && totals.quote_currency_code) {
                    self.isVisible(totals.quote_currency_code !== 'PHP');
                }
            };

            checkCurrency(quote.totals());
            quote.totals.subscribe(checkCurrency);

            return this;
        }
    });
});
