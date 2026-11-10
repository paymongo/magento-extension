/**
 * Registers all PayMongo payment method renderers with the Luma checkout.
 *
 * This component acts as the entry point for Magento's standard (Luma) checkout.
 * It uses the uiComponent mixin pattern: each method code listed in the layout XML
 * is mapped to the shared paymongo-method renderer. Hyvä Checkout does not load
 * this file — it uses the Magewire PlaceOrderService instead.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    var methods = [
        'paymongo',
        'paymongo_card',
        'paymongo_gcash',
        'paymongo_grab_pay',
        'paymongo_maya',
        'paymongo_google_pay',
        'paymongo_shopeepay',
        'paymongo_billease',
        'paymongo_bpi_online',
        'paymongo_unionbank_online',
        'paymongo_qrph'
    ];

    methods.forEach(function (code) {
        rendererList.push({
            type: code,
            component: 'PayMongo_Payment/js/view/payment/method-renderer/paymongo-method'
        });
    });

    return Component.extend({});
});
