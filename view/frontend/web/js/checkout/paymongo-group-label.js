/**
 * Builds the collapsed PayMongo group header's label from the individual payment
 * methods that are actually inside the group right now — e.g.
 * "PayMongo (QRPh, Cards, E-Wallets, Banking, and More)".
 *
 * Shared by both checkouts, because both need the identical wording and neither can
 * compute it server-side: the set of rows in the group is only known once the page has
 * rendered, and on Luma it changes again mid-session whenever Magento drops a method
 * that has stopped being available. See Model\Checkout\MethodGroupLabel for the data
 * this consumes and for why the split lands here.
 *
 * Loaded two different ways, hence the UMD wrapper:
 *
 *  - Luma pulls it in as an AMD dependency of js/model/paymongo-group-state.js.
 *  - Hyvä Checkout has no RequireJS, so checkout/payment/group-script.phtml loads it as
 *    a plain deferred <script> and group-paymongo-methods.js reads the global.
 *
 * The global is set unconditionally, before the define() call, so the AMD and script-tag
 * paths cannot disagree about which copy of the module is in use.
 */
(function (root, factory) {
    'use strict';

    var api = factory();

    root.paymongoGroupLabel = api;

    if (typeof define === 'function' && define.amd) {
        define([], function () {
            return api;
        });
    }
}(window, function () {
    'use strict';

    /**
     * Substitute %1, %2 ... in one pass.
     *
     * Chained String.replace() calls would let a group label that happens to contain a
     * placeholder-looking substring be substituted again by the next call. Translated
     * labels are merchant- and translator-supplied, so that is not worth relying on.
     *
     * @param {String} pattern
     * @param {Array} values
     * @return {String}
     */
    function interpolate(pattern, values) {
        return String(pattern).replace(/%(\d+)/g, function (match, position) {
            var value = values[Number(position) - 1];

            return typeof value === 'undefined' ? match : value;
        });
    }

    /**
     * The group keys represented by these method codes, in the config's own fixed order
     * (never the order the codes arrived in — the label reads as a sentence).
     *
     * An unmapped code counts as the overflow group rather than being dropped, so a
     * payment method added to the module later is still represented in the label by
     * "and More" until it is given a group of its own.
     *
     * @param {Array} codes
     * @param {Object} config
     * @return {Array}
     */
    function groupKeys(codes, config) {
        var codeGroups = config.codeGroups || {},
            present = {};

        codes.forEach(function (code) {
            present[codeGroups[code] || config.overflow] = true;
        });

        return (config.order || []).filter(function (key) {
            return present[key] === true;
        });
    }

    /**
     * Join labels the way the locale's patterns say to: two items take the plain
     * conjunction, three or more take the serial comma before it.
     *
     * A pattern that is missing or translated to an empty string drops back to the plain
     * separator — "Cards, E-Wallets, More". Losing the conjunction reads worse than the
     * pattern would have, but it is still the locale's own separator and its own group
     * names, which a hardcoded English "%1 and %2" here would not be.
     *
     * @param {Array} labels
     * @param {Object} config
     * @return {String}
     */
    function joinLabels(labels, config) {
        var separator = config.separator || ', ';

        if (labels.length === 1) {
            return labels[0];
        }

        if (labels.length === 2) {
            return config.pair ? interpolate(config.pair, labels) : labels.join(separator);
        }

        if (!config.series) {
            return labels.join(separator);
        }

        return interpolate(config.series, [
            labels.slice(0, -1).join(separator),
            labels[labels.length - 1]
        ]);
    }

    return {
        /**
         * A config missing any of the pieces it needs degrades to the brand name rather
         * than to a broken string. getJsConfig() always supplies all of them, so this
         * guards two things it does not control: a translation of the wrapper pattern
         * that comes back empty, and a payload that reached the page some other way.
         *
         * @param {Array} codes payment method codes currently in the group
         * @param {Object} config Model\Checkout\MethodGroupLabel::getJsConfig()
         * @return {String} the group header label, always non-empty
         */
        build: function (codes, config) {
            var keys, labels;

            config = config || {};

            // Falls back to the bare brand name whenever the summary would be absent or
            // useless: no config (a Hyvä install whose sibling module predates this
            // feature), no rows yet, or nothing but the overflow group, where
            // "PayMongo (More)" tells a shopper less than "PayMongo" does.
            if (!config.order || !config.wrapper || !codes || !codes.length) {
                return config.fallback || 'PayMongo';
            }

            keys = groupKeys(codes, config);

            if (!keys.length || (keys.length === 1 && keys[0] === config.overflow)) {
                return config.fallback || 'PayMongo';
            }

            labels = keys.map(function (key) {
                return (config.labels || {})[key] || key;
            });

            return interpolate(config.wrapper, [joinLabels(labels, config)]);
        }
    };
}));
