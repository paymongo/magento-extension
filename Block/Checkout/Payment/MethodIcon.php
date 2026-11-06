<?php

declare(strict_types=1);

namespace PayMongo\Payment\Block\Checkout\Payment;

use PayMongo\Payment\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Carries the "metadata" block argument Hyvä Checkout's payment method-list template
 * reads via Hyva\Checkout\ViewModel\Checkout\Payment\MethodList::getMethodMetaData()
 * (which looks up a child block named after the payment method's code and reads its
 * "metadata" data key — see that class and Hyva\Checkout\Model\AbstractMethodMetaData).
 *
 * Renders no visible HTML itself (no template) — its only job is to expose icon data
 * for Hyvä Checkout to pick up. Gated by the "Show Payment Method Icons" config toggle
 * so switching that off suppresses every method's icon immediately, without needing to
 * touch layout XML or clear any block-level cache.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MethodIcon extends Template
{
    /** @var Config */
    protected Config $config;

    /**
     * @param Context $context
     * @param Config $config
     * @param array $data
     */
    public function __construct(Context $context, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
    }

    /**
     * @param string $key
     * @param int|string|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null)
    {
        if ($key === 'metadata' && !$this->config->showPaymentIcons()) {
            return [];
        }

        return parent::getData($key, $index);
    }
}
