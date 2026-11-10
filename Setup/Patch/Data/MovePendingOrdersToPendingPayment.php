<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * One-time move of open PayMongo orders from New to Pending Payment (PYM-33).
 *
 * Before 2.0.1 only Card and All-in-One had a payment_action, so Magento skipped every other
 * PayMongo method's initialize(), and GCash, Maya, QR Ph and the other e-wallet and bank
 * orders waited in New (status pending). Magento's stale-order cron (sales_clean_orders) only
 * cancels orders whose status is pending_payment, so those were never cleaned up; until 2.0.1
 * a declined payment's webhook cancelled them instead, and since PYM-32 it no longer does.
 * Orders placed from 2.0.1 start in Pending Payment. This moves the ones still open at the
 * upgrade the same way, so the cron cancels them too once they are abandoned.
 *
 * Each moved order gets a comment saying why. Its updated_at (in the order and the order grid)
 * moves to the upgrade time, from which the cron counts the Pending Payment Order Lifetime:
 * an order placed just before the upgrade still gets the full lifetime for its customer to pay.
 *
 * The status is the Pending Payment state's default status, so a store that changed it keeps
 * its own. Each batch is one transaction and only touches orders still in New when it runs,
 * so the order, the order grid and the comment always agree, and an order a webhook confirmed
 * meanwhile is left alone. Finding nothing is a normal outcome, and re-running is harmless:
 * moved orders are no longer in New.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MovePendingOrdersToPendingPayment implements DataPatchInterface
{
    /**
     * Orders updated per query.
     */
    private const BATCH_SIZE = 500;

    /** @var ModuleDataSetupInterface */
    private ModuleDataSetupInterface $moduleDataSetup;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param LoggerInterface $logger
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup, LoggerInterface $logger)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logger = $logger;
    }

    /**
     * Move open PayMongo orders from New to Pending Payment.
     *
     * @return void
     */
    public function apply(): void
    {
        $this->moduleDataSetup->startSetup();

        try {
            $connection = $this->moduleDataSetup->getConnection();
            $orderTable = $this->moduleDataSetup->getTable('sales_order');

            $orderIds = $connection->fetchCol($this->openOrdersSelect($orderTable));
            if (!$orderIds) {
                return;
            }

            $status = $this->pendingPaymentStatus();
            $comment = 'Moved from Pending to Pending Payment by the PayMongo 2.0.1 upgrade, so Magento cancels '
                . 'this order automatically if it is never paid, like every other unpaid PayMongo order.';
            $now = gmdate('Y-m-d H:i:s');
            $moved = 0;

            foreach (array_chunk($orderIds, self::BATCH_SIZE) as $batch) {
                $connection->beginTransaction();
                try {
                    // Read again inside the transaction: only orders still in New are moved.
                    $batch = $connection->fetchCol(
                        $this->openOrdersSelect($orderTable)->where('o.entity_id IN (?)', $batch)->forUpdate(true)
                    );
                    if ($batch) {
                        $connection->update(
                            $orderTable,
                            ['state' => Order::STATE_PENDING_PAYMENT, 'status' => $status, 'updated_at' => $now],
                            ['entity_id IN (?)' => $batch]
                        );
                        $connection->update(
                            $this->moduleDataSetup->getTable('sales_order_grid'),
                            ['status' => $status, 'updated_at' => $now],
                            ['entity_id IN (?)' => $batch]
                        );
                        $connection->insertMultiple(
                            $this->moduleDataSetup->getTable('sales_order_status_history'),
                            array_map(static fn ($orderId): array => [
                                'parent_id'            => (int) $orderId,
                                'is_customer_notified' => 0,
                                'is_visible_on_front'  => 0,
                                'comment'              => $comment,
                                'status'               => $status,
                                'created_at'           => $now,
                                'entity_name'          => 'order',
                            ], $batch)
                        );
                        $moved += count($batch);
                    }
                    $connection->commit();
                } catch (\Throwable $e) {
                    $connection->rollBack();
                    throw $e;
                }
            }

            $this->logger->info('PayMongo: moved open orders from New to Pending Payment', [
                'orders' => $moved,
            ]);
        } finally {
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * Select the IDs of open PayMongo orders still in New.
     *
     * @param string $orderTable
     * @return \Magento\Framework\DB\Select
     */
    private function openOrdersSelect(string $orderTable): \Magento\Framework\DB\Select
    {
        $connection = $this->moduleDataSetup->getConnection();

        return $connection->select()
            ->from(['o' => $orderTable], ['entity_id'])
            ->join(['p' => $this->moduleDataSetup->getTable('sales_order_payment')], 'p.parent_id = o.entity_id', [])
            ->where('o.state = ?', Order::STATE_NEW)
            ->where("p.method = 'paymongo' OR p.method LIKE 'paymongo\\_%'");
    }

    /**
     * The Pending Payment state's default status: pending_payment unless the store changed it.
     *
     * @return string
     */
    private function pendingPaymentStatus(): string
    {
        $connection = $this->moduleDataSetup->getConnection();
        $status = $connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('sales_order_status_state'), ['status'])
                ->where('state = ?', Order::STATE_PENDING_PAYMENT)
                ->where('is_default = ?', 1)
                ->limit(1)
        );

        return $status ? (string) $status : Order::STATE_PENDING_PAYMENT;
    }

    /**
     * Patches this one depends on: none.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Earlier names of this patch: none.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
