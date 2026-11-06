<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Model\Method;

use PayMongo\Payment\Model\Method\Dob;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the retired Direct Online Banking method (PYM-34): never active, yet an order
 * placed with it can still be paid with either bank when its PayMongo page is reopened.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class DobTest extends TestCase
{
    /**
     * @return void
     */
    public function testIsNeverActiveEvenWhenItsFlagIsSet(): void
    {
        $dob = $this->createDob(['payment/paymongo_dob/active' => true]);

        $this->assertFalse($dob->isActive());
        $this->assertFalse($dob->isActive(1));
    }

    /**
     * @return void
     */
    public function testOffersUnionBankOnlyWhileUnionBankOnlineIsEnabled(): void
    {
        $this->assertSame(
            ['dob', 'dob_ubp'],
            $this->createDob(['payment/paymongo_unionbank_online/active' => true])->getCheckoutSessionTypes()
        );
        $this->assertSame(['dob'], $this->createDob([])->getCheckoutSessionTypes());
    }

    /**
     * @param array<string, bool> $flags Config path => flag
     * @return Dob
     */
    private function createDob(array $flags): Dob
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => $flags[$path] ?? false
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => isset($flags[$path]) ? (string) (int) $flags[$path] : null
        );

        $reflection = new \ReflectionClass(Dob::class);
        $dob = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_scopeConfig')->setValue($dob, $scopeConfig);

        return $dob;
    }
}
