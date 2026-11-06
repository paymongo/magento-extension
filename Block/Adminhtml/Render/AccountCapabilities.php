<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\Render;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Check PayMongo Account Status" button and results panel on the
 * Payment Methods config page.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AccountCapabilities extends Field
{
    /** @var string */
    protected $_template = 'PayMongo_Payment::system/config/fieldset/account_capabilities.phtml';

    public function render(AbstractElement $element): string
    {
        $element->addClass('paymongo');

        return $this->toHtml();
    }

    /**
     * URL for the AJAX capabilities check — forwards the store view the admin is
     * currently configuring, so the check reflects that store's own API keys
     * (Controller\Adminhtml\Config\CheckCapabilities already reads this "store"
     * param) instead of always checking the default scope's keys regardless of
     * which scope is being configured. Only store-view scope is forwarded, not
     * website scope — a website has no single representative store to check
     * against, so at website scope this still checks default-scope keys.
     *
     * @return string
     */
    public function getCheckUrl(): string
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        return $this->getUrl('paymongo/config/checkCapabilities', $storeId ? ['store' => $storeId] : []);
    }
}
