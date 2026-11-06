<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Setup\Patch\Data;

use PayMongo\Payment\Setup\Patch\Data\MovePendingOrdersToPendingPayment;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the one-time move of open PayMongo orders from New to Pending Payment, so Magento's
 * stale-order cron cancels them once abandoned (PYM-33).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MovePendingOrdersToPendingPaymentTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where', 'limit', 'forUpdate'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $this->connection->method('select')->willReturn($select);
    }

    /**
     * Open PayMongo orders in New move to Pending Payment, in the order and the order grid, each
     * with a comment saying why, in one transaction per batch. Only orders still in New when the
     * batch runs are moved: one a webhook confirmed in between (71 here) is left alone.
     *
     * @return void
     */
    public function testMovesOpenPayMongoOrdersToPendingPayment(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchCol')
            ->willReturnOnConsecutiveCalls(['69', '71'], ['69']);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->expects($this->once())->method('fetchOne')->willReturn('pending_payment');

        $updates = [];
        $this->connection->expects($this->exactly(2))->method('update')
            ->willReturnCallback(function (string $table, array $data, array $where) use (&$updates): int {
                $updates[$table] = [$data, $where];
                return 2;
            });
        $this->connection->expects($this->once())->method('insertMultiple')
            ->with('sales_order_status_history', $this->callback(static function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['parent_id'] === 69
                    && $rows[0]['status'] === 'pending_payment'
                    && str_contains($rows[0]['comment'], 'Moved from Pending to Pending Payment');
            }));

        $this->patch()->apply();

        [$orderData, $orderWhere] = $updates['sales_order'];
        $this->assertSame('pending_payment', $orderData['state']);
        $this->assertSame('pending_payment', $orderData['status']);
        $this->assertArrayHasKey('updated_at', $orderData);
        $this->assertSame(['entity_id IN (?)' => ['69']], $orderWhere);
        [$gridData, $gridWhere] = $updates['sales_order_grid'];
        $this->assertSame('pending_payment', $gridData['status']);
        $this->assertArrayHasKey('updated_at', $gridData);
        $this->assertSame(['entity_id IN (?)' => ['69']], $gridWhere);
    }

    /**
     * A store that changed the Pending Payment state's default status keeps its own.
     *
     * @return void
     */
    public function testUsesTheStoresPendingPaymentStatus(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchCol')->willReturn(['69']);
        $this->connection->expects($this->once())->method('fetchOne')->willReturn('awaiting_paymongo');
        $this->connection->expects($this->exactly(2))->method('update')
            ->willReturnCallback(function (string $table, array $data): int {
                $this->assertSame('awaiting_paymongo', $data['status']);
                return 1;
            });
        $this->connection->expects($this->once())->method('insertMultiple');

        $this->patch()->apply();
    }

    /**
     * Finding nothing is normal (a fresh install, or the patch already ran): nothing is written.
     *
     * @return void
     */
    public function testNothingToMoveWritesNothing(): void
    {
        $this->connection->expects($this->once())->method('fetchCol')->willReturn([]);
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('insertMultiple');

        $this->patch()->apply();
    }

    /**
     * @return MovePendingOrdersToPendingPayment
     */
    private function patch(): MovePendingOrdersToPendingPayment
    {
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection);
        $setup->method('getTable')->willReturnArgument(0);

        return new MovePendingOrdersToPendingPayment($setup, $this->createStub(LoggerInterface::class));
    }
}
