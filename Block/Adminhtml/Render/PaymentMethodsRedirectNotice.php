<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\Render;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a small redirect notice for PayMongo's entry on the stock
 * Stores > Configuration > Sales > Payment Methods page — mirrors the pattern
 * other payment integrations (e.g. Mollie, visible on that same page) use to
 * point merchants at their own dedicated config tab instead of duplicating
 * every field there.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentMethodsRedirectNotice extends Field
{
    /** @var string */
    protected $_template = 'PayMongo_Payment::system/config/fieldset/payment-methods-redirect-notice.phtml';

    public function render(AbstractElement $element): string
    {
        $element->addClass('paymongo');

        return $this->toHtml();
    }

    /**
     * URL to PayMongo's own dedicated config tab (Stores > Configuration > PayMongo > General).
     *
     * @return string
     */
    public function getPaymongoConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'paymongo_general']);
    }
}
