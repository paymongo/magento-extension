<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Renders a single config field as a bare input, with no label/scope-label/hint
 * columns — for use inside one flat <tr> per payment method (see
 * Block\Adminhtml\System\Config\Form\PaymentMethodRow) instead of Magento's default
 * one-<tr>-per-field layout inside a collapsible fieldset.
 *
 * Keeps the same "row_<htmlId>" wrapper id Magento's own default field renderer uses
 * (Magento\Config\Block\System\Config\Form\Field::_decorateRowHtml()), because the
 * existing "Payment Display Mode" dependency JS (lib/web/mage/adminhtml/form.js) looks
 * up an element with that id before falling back to DOM-ancestor traversal — keeping
 * it means Title/Sort Order still hide correctly in All-in-One mode, scoped to just
 * this cell instead of an entire shared table row.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class BareInput extends Field
{
    /** @var SecureHtmlRenderer */
    private SecureHtmlRenderer $secureRenderer;

    /**
     * @param Context $context
     * @param SecureHtmlRenderer $secureRenderer
     * @param array $data
     */
    public function __construct(
        Context $context,
        SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureRenderer);
        $this->secureRenderer = $secureRenderer;
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $isCheckboxRequired = $this->_isInheritCheckboxRequired($element);

        if ($element->getInherit() == 1 && $isCheckboxRequired) {
            $element->setDisabled(true);
        }
        if ($element->getIsDisableInheritance()) {
            $element->setReadonly(true);
        }

        $rowId = 'row_' . $element->getHtmlId();

        $html = '<span class="paymongo-method-cell__value">' . $this->_getElementHtml($element) . '</span>';
        if ($isCheckboxRequired) {
            $html .= $this->renderInlineInheritCheckbox($element);
        }
        if ($element->getComment()) {
            $html .= $this->renderCommentHint($element);
        }

        $wrapped = '<span id="' . $rowId . '" class="paymongo-method-cell">' . $html . '</span>';

        if (!empty($element->getFieldConfig()['depends']['fields'])) {
            $wrapped .= $this->secureRenderer->renderStyleAsTag('display: none;', '#' . $rowId);
        }

        return $wrapped;
    }

    /**
     * Compact version of the parent's _renderInheritCheckbox() — same checkbox/label
     * markup, without the <td class="use-default"> wrapper a flat table cell has no
     * use for. Rendered as the value span's next sibling (not nested inside it) so
     * "Element.previous(this.parentNode)" in mage/adminhtml/tools.js's
     * toggleValueElements() still resolves to the value span, exactly like it resolves
     * to the adjacent <td class="value"> in Magento's default row layout.
     *
     * @param AbstractElement $element
     * @return string
     */
    private function renderInlineInheritCheckbox(AbstractElement $element): string
    {
        $htmlId = $element->getHtmlId();
        $namePrefix = preg_replace('#\[value\](\[\])?$#', '', (string) $element->getName());
        $checkedHtml = $element->getInherit() == 1 ? ' checked="checked"' : '';
        $disabled = $element->getIsDisableInheritance() == true ? ' disabled="disabled" readonly="1"' : '';
        $label = $element->getCanUseWebsiteValue() ? __('Use Website') : __('Use Default');

        $html = '<span class="paymongo-method-cell__inherit">';
        $html .= '<input id="' . $htmlId . '_inherit" name="' . $namePrefix
            . '[inherit]" type="checkbox" value="1" class="checkbox config-inherit"'
            . $checkedHtml . $disabled . ' />';
        $html .= $this->secureRenderer->renderEventListenerAsTag(
            'onclick',
            'toggleValueElements(this, Element.previous(this.parentNode))',
            'input#' . $htmlId . '_inherit'
        );
        $html .= '<label for="' . $htmlId . '_inherit" class="inherit">' . $label . '</label>';
        $html .= '</span>';

        return $html;
    }

    /**
     * A field's <comment> has nowhere to sit as a full paragraph inside a compact table
     * cell the way Magento's default row layout shows it — rendered instead as a small
     * hint icon carrying the same text as a native title tooltip, so a comment added to
     * any of these fields is still surfaced rather than silently dropped. Payment Action
     * relies on this: its option labels are deliberately short so the column fits, and
     * the capture-timing explanation lives in its <comment> instead.
     *
     * @param AbstractElement $element
     * @return string
     */
    private function renderCommentHint(AbstractElement $element): string
    {
        return '<span class="paymongo-method-cell__hint" title="'
            . $this->escapeHtmlAttr((string) $element->getComment()) . '">&#9888;</span>';
    }
}
