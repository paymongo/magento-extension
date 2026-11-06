<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders one PayMongo payment method's config group as a single flat <tr> — Enabled,
 * Title, Sort Order (and Payment Action for Card only) as adjacent cells — instead of
 * Magento's default collapsible per-method fieldset. See Block\Adminhtml\Render\
 * PaymentMethodsTable, which wraps the contiguous run of these rows in one <table>.
 *
 * Each of the "active"/"title"/"sort_order"/"payment_action" fields still renders via
 * its own Block\Adminhtml\System\Config\Form\Field\BareInput frontend_model (set in
 * etc/adminhtml/system.xml) — this block only arranges those already-rendered bare
 * inputs into table cells, it doesn't build them itself, so every field keeps its own
 * config_path, scope handling, and "Payment Display Mode" dependency behavior exactly
 * as before.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentMethodRow extends Field
{
    private const ASSET_PATH = 'PayMongo_Payment::images/methods/';

    /**
     * Admin group id (the last segment of "paymongo_payment_methods/<id>") -> icon
     * asset filename(s), relative to ASSET_PATH. Card shows both Visa and Mastercard
     * small logos side by side, matching the two-logo treatment used at checkout (see
     * PayMongo\PaymentHyva\Plugin\Checkout\MultiLogoIconRenderer); every other method has one icon.
     *
     * @var array<string, array<int, string>>
     */
    private const GROUP_ICONS = [
        'card' => ['visa.webp', 'mastercard.webp'],
        'gcash' => ['gcash.webp'],
        'grab_pay' => ['grabpay.webp'],
        'maya' => ['maya.webp'],
        'google_pay' => ['google-pay.webp'],
        'shopeepay' => ['shopeepay.webp'],
        'billease' => ['billease.webp'],
        'bpi_online' => ['bpi.webp'],
        'unionbank_online' => ['unionbank.webp'],
        'qrph' => ['qrph.webp'],
    ];

    /**
     * Field id suffixes (the part after "paymongo_payment_methods_<group>_"), in the
     * fixed column order every method row uses. "payment_action" only exists on the
     * Card group — every other row shows an em dash in that column instead.
     *
     * @var array<int, string>
     */
    private const COLUMN_FIELDS = ['active', 'title', 'sort_order', 'payment_action'];

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $groupId = (string) preg_replace('/^paymongo_payment_methods_/', '', (string) $element->getHtmlId());

        $fields = [];
        foreach ($element->getElements() as $field) {
            $fieldId = (string) $field->getId();
            foreach (self::COLUMN_FIELDS as $suffix) {
                if (substr($fieldId, -(strlen($suffix) + 1)) === '_' . $suffix) {
                    $fields[$suffix] = $field;
                }
            }
        }

        $cells = '<td class="paymongo-method-row__icon-cell">' . $this->getIconsHtml($groupId) . '</td>';
        $cells .= '<td class="paymongo-method-row__name-cell">'
            . '<span id="' . $element->getHtmlId() . '-head">' . $element->getLegend() . '</span>'
            . '</td>';

        foreach (['active', 'title', 'sort_order'] as $suffix) {
            $cells .= '<td class="paymongo-method-row__' . str_replace('_', '-', $suffix) . '-cell">'
                . (isset($fields[$suffix]) ? $fields[$suffix]->toHtml() : '&mdash;')
                . '</td>';
        }

        $cells .= '<td class="paymongo-method-row__action-cell">'
            . (isset($fields['payment_action']) ? $fields['payment_action']->toHtml() : '&mdash;')
            . '</td>';

        return '<tr class="paymongo-method-row">' . $cells . '</tr>';
    }

    /**
     * @param string $groupId
     * @return string
     */
    private function getIconsHtml(string $groupId): string
    {
        $html = '';
        foreach (self::GROUP_ICONS[$groupId] ?? [] as $file) {
            $html .= '<img src="' . $this->escapeUrl($this->getViewFileUrl(self::ASSET_PATH . $file)) . '"'
                . ' alt="" class="paymongo-method-row__icon" />';
        }

        return $html;
    }
}
