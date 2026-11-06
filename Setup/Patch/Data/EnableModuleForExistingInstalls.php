<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use Psr\Log\LoggerInterface;

/**
 * Carries the pre-1.1.1 master switch forward onto its new config path.
 *
 * Up to 1.1.0.0 the module's master switch lived at payment/paymongo/active. In 1.1.1 that
 * path was repurposed for the all-in-one method's own active flag and the master switch
 * moved to payment/paymongo/enabled, which defaults to 0 in etc/config.xml.
 *
 * Without this patch an existing merchant who simply runs setup:upgrade has no
 * payment/paymongo/enabled row, falls through to that 0 default, and every PayMongo option
 * disappears from checkout — AbstractPaymongoMethod::isAvailable() and
 * PaymongoPayment::isAvailable() both hard-return false on it. Nothing surfaces the reason,
 * and docs/UPGRADE.md promises no mandatory configuration step.
 *
 * This patch therefore writes enabled = 1 at exactly those scopes that had the old switch
 * turned on, and nowhere else. It never overwrites an existing value, so a merchant who has
 * already set the new field — or who deliberately turned the module off — is left alone.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class EnableModuleForExistingInstalls implements DataPatchInterface
{
    /**
     * This patch's class name before the module was renamed from DevTeam_Paymongo.
     */
    private const LEGACY_PATCH_NAME = 'DevTeam\\Paymongo\\Setup\\Patch\\Data\\EnableModuleForExistingInstalls';

    private const OLD_MASTER_SWITCH = 'payment/paymongo/active';
    private const NEW_MASTER_SWITCH = 'payment/paymongo/enabled';

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

            // Read the old switch before anything in 1.1.1 has a chance to rewrite it. This runs
            // during setup:upgrade, ahead of any admin save, so these rows still hold the
            // pre-upgrade master-switch values.
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['scope', 'scope_id', 'value'])
                    ->where('path = ?', self::OLD_MASTER_SWITCH)
            );

            $migrated = 0;
            foreach ($rows as $row) {
                if ((string) ($row['value'] ?? '') !== '1') {
                    continue;
                }
                if ($this->writeIfAbsent($connection, $table, (string) $row['scope'], (string) $row['scope_id'])) {
                    $migrated++;
                }
            }

            $this->logger->info(sprintf(
                'PayMongo_Payment: carried the master switch forward to %s at %d scope(s).',
                self::NEW_MASTER_SWITCH,
                $migrated
            ));
        } finally {
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * @param AdapterInterface $connection
     * @param string $table
     * @param string $scope
     * @param string $scopeId
     * @return bool whether a value was written
     */
    private function writeIfAbsent(AdapterInterface $connection, string $table, string $scope, string $scopeId): bool
    {
        $existing = $connection->fetchRow(
            $connection->select()
                ->from($table, ['config_id', 'value'])
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
                ->where('path = ?', self::NEW_MASTER_SWITCH)
        );

        if ($existing) {
            if ((string) ($existing['value'] ?? '') !== '') {
                return false;
            }
            $connection->update($table, ['value' => '1'], ['config_id = ?' => $existing['config_id']]);
            return true;
        }

        $connection->insert($table, [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => self::NEW_MASTER_SWITCH,
            'value' => '1',
        ]);

        return true;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Lets Magento 2.4.9+ skip this patch on installs that applied it under its old name.
     * Older versions only record the alias after running the patch; apply() covers those.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [self::LEGACY_PATCH_NAME];
    }
}
