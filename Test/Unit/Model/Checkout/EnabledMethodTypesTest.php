<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Checkout;

use PayMongo\Payment\Model\Checkout\EnabledMethodTypes;
use PayMongo\Payment\Model\Method\AbstractPaymongoMethod;
use PayMongo\Payment\Model\Method\BpiOnline;
use PayMongo\Payment\Model\Method\Dob;
use PayMongo\Payment\Model\Method\Gcash;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use PHPUnit\Framework\TestCase;

/**
 * Covers which methods All-in-One Checkout offers and shows logos for — in particular
 * that the retired Direct Online Banking method stays out on a store that saved it as
 * enabled before it was retired (PYM-34).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class EnabledMethodTypesTest extends TestCase
{
    /**
     * @return void
     */
    public function testRetiredDirectOnlineBankingIsLeftOutEvenWhenItsFlagIsSet(): void
    {
        $enabledMethodTypes = $this->createEnabledMethodTypes(
            [Gcash::class, Dob::class, BpiOnline::class],
            [
                'payment/paymongo_gcash/active' => '1',
                'payment/paymongo_gcash/sort_order' => '3',
                'payment/paymongo_dob/active' => '1',
                'payment/paymongo_dob/sort_order' => '1',
                'payment/paymongo_bpi_online/active' => '1',
                'payment/paymongo_bpi_online/sort_order' => '9',
            ]
        );

        // 'dob' is still offered, but for BPI Online and in BPI's place in the order.
        $this->assertSame(['gcash', 'dob'], $enabledMethodTypes->getEnabledTypes());
        $this->assertSame(
            ['gcash', 'dob'],
            array_column($enabledMethodTypes->getIconEntries()['icons'], 'type')
        );
    }

    /**
     * @return void
     */
    public function testRetiredDirectOnlineBankingAloneOffersNothing(): void
    {
        $enabledMethodTypes = $this->createEnabledMethodTypes(
            [Dob::class],
            ['payment/paymongo_dob/active' => '1', 'payment/paymongo_dob/sort_order' => '11']
        );

        $this->assertSame([], $enabledMethodTypes->getEnabledTypes());
        $this->assertSame(['icons' => [], 'overflow' => 0], $enabledMethodTypes->getIconEntries());
    }

    /**
     * Real method classes, so isActive() and the PayMongo types are the classes' own;
     * only the store configuration they read is faked.
     *
     * @param array<int, class-string<AbstractPaymongoMethod>> $classes
     * @param array<string, string> $config Config path => value
     * @return EnabledMethodTypes
     */
    private function createEnabledMethodTypes(array $classes, array $config): EnabledMethodTypes
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $config[$path] ?? null
        );

        $methods = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $method = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('_scopeConfig')->setValue($method, $scopeConfig);
            $methods[$method->getCode()] = $method;
        }

        $paymentHelper = $this->createStub(PaymentHelper::class);
        $paymentHelper->method('getPaymentMethods')->willReturn(array_fill_keys(array_keys($methods), []));
        $paymentHelper->method('getMethodInstance')->willReturnCallback(
            static fn (string $code) => $methods[$code]
        );

        return new EnabledMethodTypes($paymentHelper);
    }
}
