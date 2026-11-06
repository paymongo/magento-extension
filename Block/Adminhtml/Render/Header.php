<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Adminhtml\Render;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Header extends Field
{
    /**
     * @var string
     */
    protected $_template = 'PayMongo_Payment::system/config/fieldset/header.phtml';

    public function render(AbstractElement $element): string
    {
        $element->addClass('paymongo');

        return $this->toHtml();
    }
}
