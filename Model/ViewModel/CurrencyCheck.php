<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class CurrencyCheck implements ArgumentInterface
{
    private CheckoutSession $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    public function isPhpCurrency(): bool
    {
        $currency = $this->checkoutSession->getQuote()->getCurrency()->getQuoteCurrencyCode();
        return $currency === 'PHP';
    }
}
