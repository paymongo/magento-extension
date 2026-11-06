<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Carries admin role permissions and the admin startup page over from the DevTeam_Paymongo IDs.
 *
 * Up to 2.0.0 the module was DevTeam_Paymongo, so its ACL resources and admin menu items were
 * named DevTeam_Paymongo::*. The module is now PayMongo_Payment and those IDs are
 * PayMongo_Payment::*. Magento never maps an old ID onto a new one, so without this patch:
 *
 *  - every restricted admin role (and every integration) granted the PayMongo config section,
 *    the PayMongo menu or the reconciliation screen silently loses that access, because
 *    authorization_rule stores grants by resource ID. Roles with Magento_Backend::all are
 *    unaffected either way;
 *  - an admin whose Startup Page was set to a PayMongo menu item quietly lands on the first
 *    available menu instead, because admin/startup/menu_item_id no longer resolves.
 *
 * Each old permission row is renamed in place, keeping its allow/deny value. If the role
 * already has a row under the new ID — for example because the role was re-saved after the
 * upgrade — that row wins and the old one is removed. The resource IDs are matched exactly
 * rather than by LIKE, so the result does not depend on the server's escaping mode. The
 * pre-1.1.1 DevTeam_Paymongo::view resource no longer exists and is deliberately not carried
 * over. On a fresh install there are no old rows and this is a no-op.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MigrateAdminResourceIds implements DataPatchInterface
{
    /**
     * Old ACL resource ID -> new ACL resource ID.
     */
    private const ACL_RESOURCES = [
        'DevTeam_Paymongo::config' => 'PayMongo_Payment::config',
        'DevTeam_Paymongo::paymongo' => 'PayMongo_Payment::paymongo',
        'DevTeam_Paymongo::reconciliation' => 'PayMongo_Payment::reconciliation',
    ];

    /**
     * Old admin menu item ID -> new admin menu item ID.
     */
    private const MENU_ITEMS = [
        'DevTeam_Paymongo::paymongo' => 'PayMongo_Payment::paymongo',
        'DevTeam_Paymongo::reconciliation_menu' => 'PayMongo_Payment::reconciliation_menu',
    ];

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
     * @return void
     */
    public function apply(): void
    {
        $this->moduleDataSetup->startSetup();

        try {
            [$renamed, $removed] = $this->migrateRolePermissions();
            $startupPages = $this->migrateStartupPages();

            $this->logger->info(sprintf(
                'PayMongo_Payment: carried %d admin role permission(s) over from DevTeam_Paymongo'
                . ' (%d superseded row(s) removed) and %d admin startup page setting(s).',
                $renamed,
                $removed,
                $startupPages
            ));
        } finally {
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * @return int[] [rows renamed, superseded rows removed]
     */
    private function migrateRolePermissions(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('authorization_rule');

        $renamed = 0;
        $removed = 0;
        foreach (self::ACL_RESOURCES as $oldId => $newId) {
            $rolesWithNewId = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['role_id'])
                    ->where('resource_id = ?', $newId)
            );

            if ($rolesWithNewId) {
                $removed += $connection->delete($table, [
                    'resource_id = ?' => $oldId,
                    'role_id IN (?)' => $rolesWithNewId,
                ]);
            }

            $renamed += $connection->update($table, ['resource_id' => $newId], ['resource_id = ?' => $oldId]);
        }

        return [$renamed, $removed];
    }

    /**
     * @return int number of startup page settings updated
     */
    private function migrateStartupPages(): int
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $updated = 0;
        foreach (self::MENU_ITEMS as $oldId => $newId) {
            $updated += $connection->update($table, ['value' => $newId], [
                'path = ?' => UrlInterface::XML_PATH_STARTUP_MENU_ITEM,
                'value = ?' => $oldId,
            ]);
        }

        return $updated;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
