/**
 * Groups PayMongo's Separate-mode individual payment methods (paymongo_card,
 * paymongo_gcash, etc.) under one collapsible header on Hyva Checkout, without knowing
 * or replicating any specific theme's payment-method-row markup.
 *
 * The header is labelled with what the group actually holds — e.g.
 * "PayMongo (QRPh, Cards, E-Wallets, Banking, and More)" — built by the shared
 * paymongo-group-label.js from the rows found below, so it can never advertise a
 * payment method this store has switched off. The rows are re-read on every pass, so
 * the label also follows any method that appears or disappears across a Magewire
 * round-trip.
 *
 * An earlier version of this feature worked by overriding Hyva Checkout's
 * checkout.payment.methods block template. That broke as soon as it met a theme with
 * its own override of that template (this project's own DevTeam/Shop theme, in fact) —
 * the override replaced the theme's entire payment method list styling, not just
 * PayMongo's part, and a different Hyva theme would look different again. There is no
 * way to safely replicate "every possible theme's row markup" from one static template.
 *
 * Instead, this runs client-side, after whichever template actually rendered the list:
 * it finds the already-rendered row elements for PayMongo's individual methods (by their
 * id, "payment-method-option-{code}" — a Hyva Checkout base convention every reasonable
 * theme override keeps, since wire:key/id on that element is what Magewire's own DOM
 * morphing relies on to track it; renaming it would break Magewire itself, not just this
 * feature), clones the FIRST row's own class list onto a new group wrapper (so the
 * header automatically matches whatever theme is active, using the theme's own class
 * names — never hardcoded Tailwind tokens), and reparents the individual rows into a
 * collapsible content area inside it. Nothing about the rows' own markup is touched.
 *
 * checkout.payment.methods is a Magewire component: selecting a different payment method
 * triggers a real server round-trip that re-renders and morphs its whole HTML fragment.
 * Since the server's template has no idea this grouping exists, morphdom reconciles
 * against the flat (ungrouped) structure it always renders — meaning our injected group
 * wrapper doesn't survive a morph, and the individual rows can reappear flat. So this
 * doesn't only run once: it re-runs after every Magewire round-trip via the
 * "message.processed" hook, and is written to be safe to call repeatedly (it only ever
 * acts on rows it finds still directly under #payment-method-list — anything already
 * moved into the group is left alone).
 */
(function () {
    'use strict';

    var METHOD_ROW_ID = 'payment-method-option-';
    var METHOD_ROW_ID_PREFIX = METHOD_ROW_ID + 'paymongo_';
    var GROUP_ID = 'paymongo-method-group';
    var GROUP_CONTENT_ID = 'paymongo-method-group-content';
    var GROUP_CHEVRON_ID = 'paymongo-method-group-chevron';
    var GROUP_HEADER_ID = 'paymongo-method-group-header';
    var GROUP_LABEL_ID = 'paymongo-method-group-label';
    var GROUP_CONFIG_ID = 'paymongo-method-group-config';
    var FALLBACK_LABEL = 'PayMongo';

    // Survives Magewire morphs. The wrapper itself never exists in the server HTML, so
    // morphdom discards it on every round-trip and the group is rebuilt collapsed —
    // which silently snapped the group shut each time the shopper touched anything on
    // the payment step. Keeping the flag out here lets the rebuild restore it.
    var isExpanded = false;

    /**
     * The group-label data emitted by checkout/payment/group-script.phtml. Read once —
     * it is a static <script type="application/json"> element outside the Magewire
     * fragment, so no morph can replace it.
     *
     * Returns null when the element is absent (a PayMongo_PaymentHyva older than this
     * feature passes no view model) or unparseable, which the label builder handles by
     * falling back to the bare brand name.
     */
    var groupConfig = (function () {
        var element = document.getElementById(GROUP_CONFIG_ID);

        if (!element) {
            return null;
        }

        try {
            return JSON.parse(element.textContent);
        } catch (e) {
            return null;
        }
    }());

    function isPaymongoIndividualRow(el) {
        return !!(el.id && el.id.indexOf(METHOD_ROW_ID_PREFIX) === 0);
    }

    /**
     * The payment method code a row belongs to, recovered from the id Hyva Checkout
     * gives it — the same convention isPaymongoIndividualRow() matches on.
     */
    function methodCode(row) {
        return String(row.id).slice(METHOD_ROW_ID.length);
    }

    /**
     * Re-label the header for the rows currently in the group. Called on every pass, not
     * just when the wrapper is built: a Magewire round-trip can add or drop a method, and
     * a stale label would keep naming one that is no longer there.
     */
    function setLabel(group, rows) {
        var label = group.querySelector('#' + GROUP_LABEL_ID);

        if (!label) {
            return;
        }

        label.textContent = window.paymongoGroupLabel
            ? window.paymongoGroupLabel.build(rows.map(methodCode), groupConfig)
            : FALLBACK_LABEL;
    }

    function isMethodSelected(row) {
        var input = row.querySelector('input[type="radio"]');
        return !!(input && input.checked);
    }

    function setExpanded(content, chevron, expanded) {
        isExpanded = expanded;
        content.style.display = expanded ? '' : 'none';
        if (chevron) {
            chevron.style.transform = expanded ? 'rotate(180deg)' : '';
        }
        var header = document.getElementById(GROUP_HEADER_ID);
        if (header) {
            header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    /**
     * Build the wrapper's class list from the tokens every row shares.
     *
     * The wrapper borrows row classes so it looks native to the active theme, but themes
     * encode selection state in that same list (active/inactive plus background and border
     * tokens), and the selected row is rendered that way by the server. Cloning one row
     * therefore made the group header itself render as the chosen payment method.
     *
     * Picking an "unselected" row instead is not reliable: this script is deferred and runs
     * before Alpine hydrates, so no radio reports checked yet even when one is. Intersecting
     * the rows' class lists sidesteps the question entirely — whatever differs between rows
     * is by definition per-row state, and only the shared structural classes survive. No
     * theme-specific token is ever named here.
     */
    function sharedClassList(rows) {
        if (rows.length < 2) {
            return rows[0].className;
        }

        // Once Alpine has hydrated, exactly one radio reports checked and an unselected row
        // can be identified directly. Borrowing its full class list keeps the theme's normal
        // unselected look on the header, rather than the bare structural classes the
        // intersection below would leave. Only trusted when a row actually reports selected;
        // before hydration nothing does, and every row would look equally "unselected".
        var unselected = rows.filter(function (row) {
            return !isMethodSelected(row);
        });

        if (unselected.length > 0 && unselected.length < rows.length) {
            return unselected[0].className;
        }

        var tokenSets = rows.map(function (row) {
            return String(row.className).split(/\s+/).filter(Boolean);
        });

        var shared = tokenSets[0].filter(function (token) {
            return tokenSets.every(function (set) {
                return set.indexOf(token) !== -1;
            });
        });

        return shared.length ? shared.join(' ') : rows[0].className;
    }

    function buildGroup(groupClass) {
        var group = document.createElement('div');
        group.id = GROUP_ID;
        // Reuse the theme's own row classes, so the group header looks native to whichever
        // theme is active without this script knowing any theme's class names.
        group.className = groupClass;

        var content = document.createElement('div');
        content.id = GROUP_CONTENT_ID;
        content.style.cssText = 'display:none;';

        var chevron = document.createElement('span');
        chevron.id = GROUP_CHEVRON_ID;
        chevron.setAttribute('aria-hidden', 'true');
        chevron.style.cssText = 'display:inline-flex;transition:transform .15s ease;margin-left:auto;';
        // Same chevron markup as the Luma checkout template, for visual parity.
        chevron.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" '
            + 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<polyline points="6 9 12 15 18 9"></polyline></svg>';

        // Text is set by setLabel() once the rows are known, on this pass and every
        // pass after it.
        var label = document.createElement('span');
        label.id = GROUP_LABEL_ID;

        var header = document.createElement('button');
        header.type = 'button';
        header.id = GROUP_HEADER_ID;
        header.setAttribute('aria-expanded', 'false');
        header.setAttribute('aria-controls', GROUP_CONTENT_ID);
        header.style.cssText = 'display:flex;align-items:center;width:100%;gap:0.5rem;'
            + 'cursor:pointer;background:none;border:none;font:inherit;text-align:left;padding:1rem;';
        header.appendChild(label);
        header.appendChild(chevron);
        header.addEventListener('click', function () {
            setExpanded(content, chevron, content.style.display === 'none');
        });

        group.appendChild(header);
        group.appendChild(content);

        return group;
    }

    function groupPaymongoMethods() {
        var list = document.getElementById('payment-method-list');
        if (!list) {
            return;
        }

        var flatRows = Array.prototype.filter.call(list.children, isPaymongoIndividualRow);
        var group = document.getElementById(GROUP_ID);

        // Rows already sitting inside the group. A morph does not always push them back out
        // to the list — when it leaves them in place there is nothing to re-parent, but the
        // wrapper's own class list and the open/closed state still need re-applying, so this
        // must not bail out early on flatRows alone.
        var groupedRows = group
            ? Array.prototype.slice.call(group.querySelectorAll('[id^="' + METHOD_ROW_ID_PREFIX + '"]'))
            : [];

        if (flatRows.length + groupedRows.length === 0) {
            return;
        }

        // The wrapper and its content area were previously looked up independently, so any
        // state where a morph left the wrapper behind without its content produced a
        // TypeError on the appendChild below. Treat a wrapper missing its content as
        // unusable and rebuild it outright, rather than assuming the pair is intact —
        // lifting any rows back out to the list first, since removing the wrapper would
        // otherwise take them out of the DOM along with it.
        if (group && !group.querySelector('#' + GROUP_CONTENT_ID)) {
            groupedRows.forEach(function (row) {
                list.appendChild(row);
            });
            flatRows = flatRows.concat(groupedRows);
            groupedRows = [];
            group.parentNode.removeChild(group);
            group = null;
        }

        var allRows = flatRows.concat(groupedRows);
        var groupClass = sharedClassList(allRows);

        if (!group) {
            group = buildGroup(groupClass);
            flatRows[0].parentNode.insertBefore(group, flatRows[0]);
        } else {
            // Refresh on every pass: which row is selected changes between renders, and the
            // header must never inherit the selected row's styling.
            group.className = groupClass;
        }

        setLabel(group, allRows);

        var content = group.querySelector('#' + GROUP_CONTENT_ID);
        var chevron = group.querySelector('#' + GROUP_CHEVRON_ID);

        flatRows.forEach(function (row) {
            content.appendChild(row);
        });

        // Restore the shopper's own expand state across the morph, and auto-expand if one
        // of these methods is the current selection so it is never hidden from them.
        // Checked against every row, not just the ones re-parented on this pass.
        setExpanded(content, chevron, isExpanded || allRows.some(isMethodSelected));
    }

    /**
     * Magewire iterates its "message.processed" listeners without any error handling, and
     * this script is deferred so it registers before Hyva Checkout's own hooks and the
     * theme's. A throw in here would therefore take out every listener registered after
     * it — including the theme's address-form logic — on every round-trip. Grouping
     * payment methods is cosmetic; it must never be able to break the rest of checkout.
     */
    function safeGroupPaymongoMethods() {
        try {
            groupPaymongoMethods();
        } catch (e) {
            if (window.console && window.console.error) {
                window.console.error('[PayMongo_Payment] failed to group payment methods:', e);
            }
        }
    }

    function init() {
        safeGroupPaymongoMethods();

        // This script is deferred, so it runs before Alpine hydrates — at which point no
        // radio reports checked yet, even on a fresh load where the quote already has a
        // PayMongo method selected. Selecting a method reloads the page rather than
        // morphing, so without a second pass the group came back collapsed every time,
        // hiding the shopper's own current selection. Re-run once Alpine has settled.
        document.addEventListener('alpine:initialized', safeGroupPaymongoMethods);

        if (window.Magewire && typeof window.Magewire.hook === 'function') {
            window.Magewire.hook('message.processed', safeGroupPaymongoMethods);
        }
    }

    if (window.Magewire) {
        init();
    } else {
        document.addEventListener('magewire:load', init, { once: true });
    }
}());
