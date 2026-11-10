<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use Psr\Log\LoggerInterface;

/**
 * One-time checkout order change (PYM-21): QR Ph, Cards and GCash are listed first, with
 * every other PayMongo method following in its previous relative order.
 *
 * etc/config.xml only supplies the DEFAULT sort_order. The moment anyone saves the
 * PayMongo config section in admin, Magento writes a core_config_data row for every field
 * in that section — including each method's Sort Order — and from then on the saved row
 * wins over the shipped default. So changing config.xml alone reorders fresh installs and
 * nothing else. This patch carries the new order over to stores that saved the section
 * without ever changing the order themselves.
 *
 * A scope is migrated only when EVERY sort_order row saved in it still holds the old
 * shipped default. If even one differs, the merchant has arranged that scope's checkout
 * on purpose, and rewriting the rest around their change would produce an order neither
 * they nor the client asked for — so that scope is left exactly as it is and logged.
 * Deciding per scope rather than per row is what keeps the result coherent: a partial
 * rewrite could put two methods on the same position, or wedge QR Ph between methods the
 * merchant had placed deliberately.
 *
 * Every scope is swept, not just default, and rows are updated by config_id so that
 * website- and store-scoped overrides on the same path stay distinct — the same approach
 * as CapitalizePayMongoInMethodTitles.
 *
 * Finding nothing is a normal outcome: on a fresh install no sort_order rows exist yet,
 * and those installs read the new order from etc/config.xml directly.
 *
 * Re-running is harmless: a scope already holding the new order is skipped silently.
 *
 * Direct Online Banking is left out (PYM-34). Its position never changed (11 before and
 * after), and it is retired with no admin field left, so a Sort Order saved for it is
 * neither something to move nor a sign the merchant arranged the methods they can see.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PrioritizeQrphCardsGcashSortOrder implements DataPatchInterface
{
    /**
     * This patch's class name before the module was renamed from DevTeam_Paymongo.
     */
    private const LEGACY_PATCH_NAME = 'DevTeam\\Paymongo\\Setup\\Patch\\Data\\PrioritizeQrphCardsGcashSortOrder';

    /** Index of the old shipped sort_order in each SORT_ORDERS entry. */
    private const OLD = 0;

    /** Index of the new shipped sort_order in each SORT_ORDERS entry. */
    private const NEW = 1;

    /**
     * Payment method code -> [old shipped sort_order, new shipped sort_order]. The new
     * values must match etc/config.xml.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SORT_ORDERS = [
        'paymongo_qrph' => [10, 1],
        'paymongo_card' => [1, 2],
        'paymongo_gcash' => [2, 3],
        'paymongo_grab_pay' => [3, 4],
        'paymongo_maya' => [4, 5],
        'paymongo_google_pay' => [5, 6],
        'paymongo_shopeepay' => [6, 7],
        'paymongo_billease' => [7, 8],
        'paymongo_bpi_online' => [8, 9],
        'paymongo_unionbank_online' => [9, 10],
    ];

    /** @var ModuleDataSetupInterface */
    private ModuleDataSetupInterface $moduleDataSetup;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var PatchHistory */
    private PatchHistory $patchHistory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param LoggerInterface $logger
     * @param PatchHistory $patchHistory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        LoggerInterface $logger,
        PatchHistory $patchHistory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logger = $logger;
        $this->patchHistory = $patchHistory;
    }

    /**
     * @return void
     */
    public function apply(): void
    {
        // Magento before 2.4.9 only consults getAliases() after a patch has run, so on those
        // versions an install upgrading from DevTeam_Paymongo would apply this patch again.
        if ($this->patchHistory->isApplied(self::LEGACY_PATCH_NAME)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        try {
            $connection = $this->moduleDataSetup->getConnection();
            $table = $this->moduleDataSetup->getTable('core_config_data');

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['config_id', 'scope', 'scope_id', 'path', 'value'])
                    ->where('path IN (?)', array_keys($this->pathMap()))
            );

            if (!$rows) {
                // Expected on a fresh install, and on any store that never saved the
                // PayMongo config section — both read the new etc/config.xml default.
                return;
            }

            $byScope = [];
            foreach ($rows as $row) {
                $byScope[$row['scope'] . '|' . $row['scope_id']][] = $row;
            }

            foreach ($byScope as $scope => $scopeRows) {
                if ($this->holdsOnly($scopeRows, self::NEW)) {
                    // Already in the new order — migrated by an earlier run, or saved after
                    // upgrading. Nothing to do and nothing worth logging.
                    continue;
                }

                if (!$this->holdsOnly($scopeRows, self::OLD)) {
                    $this->logger->info(sprintf(
                        'PayMongo_Payment: left the PayMongo checkout order unchanged in scope %s — '
                        . 'its saved Sort Order values were customised.',
                        $scope
                    ));
                    continue;
                }

                $this->rewrite($connection, $table, $scopeRows);

                $this->logger->info(sprintf(
                    'PayMongo_Payment: moved QR Ph, Cards and GCash to the top of the PayMongo '
                    . 'checkout order in scope %s (%d saved Sort Order value(s) updated).',
                    $scope,
                    count($scopeRows)
                ));
            }
        } finally {
            // Must run even if an update throws, otherwise the connection is left with
            // foreign key checks disabled and an altered sql_mode for the rest of the request.
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * Config path -> [old sort_order, new sort_order].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function pathMap(): array
    {
        $map = [];
        foreach (self::SORT_ORDERS as $code => $orders) {
            $map['payment/' . $code . '/sort_order'] = $orders;
        }

        return $map;
    }

    /**
     * Whether every saved sort_order row in one scope holds the old (self::OLD) or the new
     * (self::NEW) shipped default.
     *
     * Compared as trimmed strings of the integer, so a value saved as "1" matches 1 but a
     * blank or "1.5" does not — anything other than the exact old default counts as the
     * merchant's own choice.
     *
     * @param array<int, array<string, mixed>> $scopeRows
     * @param int $which self::OLD or self::NEW
     * @return bool
     */
    private function holdsOnly(array $scopeRows, int $which): bool
    {
        $map = $this->pathMap();

        foreach ($scopeRows as $row) {
            if (trim((string) $row['value']) !== (string) $map[$row['path']][$which]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write the new shipped sort_order onto each row of one scope, addressing rows by
     * config_id so that website- and store-scoped overrides on the same path stay distinct.
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param array<int, array<string, mixed>> $scopeRows
     * @return void
     */
    private function rewrite(AdapterInterface $connection, string $table, array $scopeRows): void
    {
        $map = $this->pathMap();

        foreach ($scopeRows as $row) {
            $connection->update(
                $table,
                ['value' => (string) $map[$row['path']][self::NEW]],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Lets Magento 2.4.9+ skip this patch on installs that applied it under its old name.
     * Older versions only record the alias after running the patch; apply() covers those.
     *
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [self::LEGACY_PATCH_NAME];
    }
}
