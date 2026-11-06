<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Setup\Patch\Data;

use PayMongo\Payment\Setup\Patch\Data\PrioritizeQrphCardsGcashSortOrder;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers which saved Sort Orders the PYM-21 checkout order change reads — in particular that
 * the retired Direct Online Banking method's is not one of them (PYM-34).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PrioritizeQrphCardsGcashSortOrderTest extends TestCase
{
    /**
     * A Sort Order saved for Direct Online Banking, customised or not, neither blocks the
     * change nor is rewritten by it: the patch never asks for that path.
     *
     * @return void
     */
    public function testIgnoresDirectOnlineBankingSortOrder(): void
    {
        $paths = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value) use (&$paths, $select): Select {
                $paths = $value;
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $row = ['scope' => 'default', 'scope_id' => 0];
        $connection->expects($this->once())->method('fetchAll')->willReturn([
            $row + ['config_id' => 1, 'path' => 'payment/paymongo_qrph/sort_order', 'value' => '10'],
            $row + ['config_id' => 2, 'path' => 'payment/paymongo_card/sort_order', 'value' => '1'],
        ]);
        $updated = [];
        $connection->expects($this->exactly(2))->method('update')->willReturnCallback(
            function (string $table, array $data, array $where) use (&$updated): int {
                $updated[$where['config_id = ?']] = $data['value'];
                return 1;
            }
        );

        $this->patch($connection)->apply();

        $this->assertNotContains('payment/paymongo_dob/sort_order', $paths);
        $this->assertContains('payment/paymongo_unionbank_online/sort_order', $paths);
        $this->assertSame([1 => '1', 2 => '2'], $updated);
    }

    /**
     * @param AdapterInterface&MockObject $connection
     * @return PrioritizeQrphCardsGcashSortOrder
     */
    private function patch(AdapterInterface $connection): PrioritizeQrphCardsGcashSortOrder
    {
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $patchHistory = $this->createStub(PatchHistory::class);
        $patchHistory->method('isApplied')->willReturn(false);

        return new PrioritizeQrphCardsGcashSortOrder($setup, $this->createStub(LoggerInterface::class), $patchHistory);
    }
}
