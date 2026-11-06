<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\Render;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form;
use Magento\Config\Block\System\Config\Form\Field\Factory as FieldFactory;
use Magento\Config\Block\System\Config\Form\Fieldset\Factory as FieldsetFactory;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Structure;
use PayMongo\Payment\Model\Config\AdminMarkup;
use PayMongo\Payment\Model\Config\Source\PaymentDisplayMode;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;

/**
 * Wraps the "Payment Methods" section's rendered form in a single flat table for the
 * individual PayMongo methods, replacing Magento's default one-collapsible-fieldset-
 * per-method layout with one row per method (see Block\Adminhtml\System\Config\Form\
 * PaymentMethodRow, the per-group frontend_model that produces each
 * <tr class="paymongo-method-row">).
 *
 * The "Checkout Display" and "PayMongo Account Status" groups above the table aren't
 * tagged with that frontend_model, so they render normally and are left untouched by
 * the regex below — it only matches the contiguous run of method rows.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentMethodsTable extends Form
{
    private const XML_PATH_DISPLAY_MODE = 'payment/paymongo/display_mode';

    /** @var Escaper */
    private Escaper $escaper;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param ConfigFactory $configFactory
     * @param Structure $configStructure
     * @param FieldsetFactory $fieldsetFactory
     * @param FieldFactory $fieldFactory
     * @param Escaper $escaper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        ConfigFactory $configFactory,
        Structure $configStructure,
        FieldsetFactory $fieldsetFactory,
        FieldFactory $fieldFactory,
        Escaper $escaper,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $formFactory,
            $configFactory,
            $configStructure,
            $fieldsetFactory,
            $fieldFactory,
            $data
        );
        $this->escaper = $escaper;
    }

    /**
     * No _template is assigned — Form/Generic/Template's default _toHtml() would
     * render nothing without one, so this is overridden directly to return the
     * (regex-wrapped) form HTML instead of relying on a template file just to call
     * getFormHtml() the way Magento's own section blocks typically do.
     *
     * @return string
     */
    protected function _toHtml()
    {
        $html = parent::getFormHtml();

        return (string) preg_replace_callback(
            '/(?:<tr class="paymongo-method-row">.*?<\/tr>\s*)+/s',
            [$this, 'wrapMethodRows'],
            $html
        );
    }

    /**
     * @param array<int, string> $matches
     * @return string
     */
    private function wrapMethodRows(array $matches): string
    {
        /*
            Payment Action is Card-only and, unlike Enabled/Title/Sort Order beside it,
            is not store-editable (see etc/adminhtml/system.xml) — so at store scope no
            row renders the field and the column degrades to a live header standing over
            eleven em dashes. Drop it the same way All-in-One drops its columns: a
            modifier class rather than surgery on the cells, which keeps every row's cell
            count matching the header count.
        */
        $tableClass = AdminMarkup::TABLE;

        /*
            The All-in-One column collapse is rendered here rather than left to
            js/payment-action-mode.js alone. That script keys off the Payment Display
            Mode select, which the "info" group only shows at default and website scope
            — at store scope the field is absent, the script bails, and Title/Sort Order
            would stay on screen under a mode that ignores them. Emitting the class from
            the effective config covers every scope and removes the first-paint flash;
            the script still handles live changes wherever the select does exist.
        */
        if ($this->isUnifiedDisplayModeForCurrentScope()) {
            $tableClass .= ' ' . AdminMarkup::TABLE_UNIFIED;
        }

        if (!str_contains($matches[0], AdminMarkup::FIELD_CARD_PAYMENT_ACTION)) {
            $tableClass .= ' ' . AdminMarkup::TABLE_NO_ACTION;
        }

        // Column class => label, matching the cell classes PaymentMethodRow emits per
        // column so the width rules in view/adminhtml/web/css/styles.css apply to the
        // header and the body cells alike.
        $columns = [
            'paymongo-method-row__icon-cell' => '',
            'paymongo-method-row__name-cell' => (string) __('Method'),
            'paymongo-method-row__active-cell' => (string) __('Enabled'),
            'paymongo-method-row__title-cell' => (string) __('Title'),
            'paymongo-method-row__sort-order-cell' => (string) __('Sort Order'),
            'paymongo-method-row__action-cell' => (string) __('Payment Action'),
        ];

        $headerCells = '';
        foreach ($columns as $class => $column) {
            $headerCells .= '<th class="' . $this->escaper->escapeHtmlAttr($class) . '">'
                . $this->escaper->escapeHtml($column) . '</th>';
        }

        return '<div class="paymongo-methods-table-wrap">'
            . '<table class="' . $this->escaper->escapeHtmlAttr($tableClass) . '">'
            . '<thead><tr>' . $headerCells . '</tr></thead>'
            . '<tbody>' . $matches[0] . '</tbody>'
            . '</table></div>';
    }

    /**
     * Resolve the display mode for the scope currently being edited.
     *
     * The Config helper only reads store scope, so the website case is resolved here
     * directly — the admin's scope switcher passes the id in the "website"/"store"
     * request param, which is what getWebsiteCode()/getStoreCode() return.
     *
     * @return bool
     */
    private function isUnifiedDisplayModeForCurrentScope(): bool
    {
        $storeCode = (string) $this->getStoreCode();
        $websiteCode = (string) $this->getWebsiteCode();

        if ($storeCode !== '') {
            $value = $this->_scopeConfig->getValue(
                self::XML_PATH_DISPLAY_MODE,
                ScopeInterface::SCOPE_STORE,
                $storeCode
            );
        } elseif ($websiteCode !== '') {
            $value = $this->_scopeConfig->getValue(
                self::XML_PATH_DISPLAY_MODE,
                ScopeInterface::SCOPE_WEBSITE,
                $websiteCode
            );
        } else {
            $value = $this->_scopeConfig->getValue(self::XML_PATH_DISPLAY_MODE);
        }

        return (string) $value === PaymentDisplayMode::UNIFIED;
    }
}
