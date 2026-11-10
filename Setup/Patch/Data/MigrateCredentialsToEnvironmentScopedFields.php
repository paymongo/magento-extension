<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use Psr\Log\LoggerInterface;

/**
 * One-time migration: splits the single shared Secret Key / Public Key / Webhook Secret
 * fields into separate Sandbox and Production credential sets, and sets the new
 * Environment selector to match whichever mode the existing secret key indicates.
 *
 * The secret key's prefix (sk_test_ vs sk_live_) reliably tells us which environment it
 * belongs to, so the secret and public key rows migrate automatically into the matching
 * slot. The webhook secret has no such prefix, so it is migrated into the slot for that
 * same environment ONLY. The opposite slot is deliberately left empty: an empty webhook
 * secret makes the webhook controller reject every event outright, which is the safe
 * outcome. Copying the value into both slots would instead leave a test-mode signing
 * secret validating live webhooks once the merchant flips to Production.
 *
 * Scopes are handled independently. A website or store view that overrode only *some* of
 * the credentials inherits its environment from its parent scope rather than being forced
 * to Sandbox, and only a scope that carries its own identifiable secret key gets an
 * explicit Environment row written — otherwise a partial override would shadow the parent's
 * Production setting and silently take that storefront offline.
 *
 * Every value is copied forward as-is (still encrypted) into the new config paths; the
 * original payment/paymongo/secret_key, public_key, and webhook_secret rows are never
 * modified or deleted, so the pre-migration values remain recoverable if anything here
 * ever needs to be double-checked. Destinations that already hold a value are left alone,
 * so re-running this patch can never overwrite credentials the merchant has since set.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class MigrateCredentialsToEnvironmentScopedFields implements DataPatchInterface
{
    /**
     * This patch's class name before the module was renamed from DevTeam_Paymongo.
     */
    private const LEGACY_PATCH_NAME = 'DevTeam\\Paymongo\\Setup\\Patch\\Data\\MigrateCredentialsToEnvironmentScopedFields';

    private const OLD_PATHS = [
        'secret_key' => 'payment/paymongo/secret_key',
        'public_key' => 'payment/paymongo/public_key',
        'webhook_secret' => 'payment/paymongo/webhook_secret',
    ];

    private const PATH_ENVIRONMENT = 'payment/paymongo/environment';

    private const ENV_SANDBOX = 'sandbox';
    private const ENV_PRODUCTION = 'production';

    private const SCOPE_DEFAULT = 'default';
    private const SCOPE_WEBSITES = 'websites';
    private const SCOPE_STORES = 'stores';

    /** @var ModuleDataSetupInterface */
    private ModuleDataSetupInterface $moduleDataSetup;

    /** @var EncryptorInterface */
    private EncryptorInterface $encryptor;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var PatchHistory */
    private PatchHistory $patchHistory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     * @param PatchHistory $patchHistory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        PatchHistory $patchHistory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->encryptor = $encryptor;
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
                    ->where('path IN (?)', array_values(self::OLD_PATHS))
            );

            if (!$rows) {
                // Nothing in core_config_data. Credentials supplied through app/etc/env.php or
                // locked in app/etc/config.php live outside the DB and cannot be migrated here,
                // so say so loudly rather than reporting a silent success.
                $this->logger->warning(
                    'PayMongo_Payment: no PayMongo credentials found in core_config_data — nothing to migrate. '
                    . 'If credentials are set via app/etc/env.php or app/etc/config.php they must be moved to the '
                    . 'new payment/paymongo/{sandbox,production}_* paths by hand.'
                );
                return;
            }

            $byScope = [];
            foreach ($rows as $row) {
                $scopeKey = $row['scope'] . '|' . $row['scope_id'];
                $byScope[$scopeKey][$row['path']] = $row;
            }

            // Environment implied by each scope's OWN secret key. Scopes whose key is missing or
            // undecryptable are deliberately absent here so they inherit instead of guessing.
            $envByScope = [];
            foreach ($byScope as $scopeKey => $scopeRows) {
                $secretRow = $scopeRows[self::OLD_PATHS['secret_key']] ?? null;
                if ($secretRow === null) {
                    continue;
                }
                $decrypted = $this->decrypt($this->value($secretRow));
                if ($decrypted === '') {
                    $this->logger->warning(sprintf(
                        'PayMongo_Payment: secret key at scope %s could not be decrypted; '
                        . 'inheriting environment instead of inferring it.',
                        $scopeKey
                    ));
                    continue;
                }
                $envByScope[$scopeKey] = str_starts_with($decrypted, 'sk_live_')
                    ? self::ENV_PRODUCTION
                    : self::ENV_SANDBOX;
            }

            $storeToWebsite = $this->loadStoreWebsiteMap($connection);

            foreach ($byScope as $scopeKey => $scopeRows) {
                $this->migrateScope($connection, $table, $scopeKey, $scopeRows, $envByScope, $storeToWebsite);
            }
        } finally {
            // Must run even if a scope throws, otherwise the connection is left with foreign key
            // checks disabled and an altered sql_mode for the rest of the request.
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * Migrate one scope's old credential rows into the new environment-scoped paths.
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param string $scopeKey
     * @param array<string, array<string, string|null>> $scopeRows
     * @param array<string, string> $envByScope
     * @param array<string, string> $storeToWebsite
     * @return void
     */
    private function migrateScope(
        AdapterInterface $connection,
        string $table,
        string $scopeKey,
        array $scopeRows,
        array $envByScope,
        array $storeToWebsite
    ): void {
        $secretRow = $scopeRows[self::OLD_PATHS['secret_key']] ?? null;
        $publicRow = $scopeRows[self::OLD_PATHS['public_key']] ?? null;
        $webhookRow = $scopeRows[self::OLD_PATHS['webhook_secret']] ?? null;

        $anyRow = $secretRow ?? $publicRow ?? $webhookRow;
        if ($anyRow === null) {
            return;
        }

        $scope = (string) $anyRow['scope'];
        $scopeId = (string) $anyRow['scope_id'];

        $ownEnvironment = $envByScope[$scopeKey] ?? null;
        $environment = $ownEnvironment
            ?? $this->inheritEnvironment($scope, $scopeId, $envByScope, $storeToWebsite);

        if ($secretRow !== null) {
            $this->writeIfAbsent(
                $connection,
                $table,
                $scope,
                $scopeId,
                "payment/paymongo/{$environment}_secret_key",
                $this->value($secretRow)
            );
        }
        if ($publicRow !== null) {
            $this->writeIfAbsent(
                $connection,
                $table,
                $scope,
                $scopeId,
                "payment/paymongo/{$environment}_public_key",
                $this->value($publicRow)
            );
        }
        if ($webhookRow !== null) {
            // Only the slot for the environment this credential set actually belongs to. The
            // other slot stays empty so the webhook controller fails closed until the merchant
            // pastes the correct endpoint secret from PayMongo's dashboard.
            $this->writeIfAbsent(
                $connection,
                $table,
                $scope,
                $scopeId,
                "payment/paymongo/{$environment}_webhook_secret",
                $this->value($webhookRow)
            );
        }

        // Only a scope with its own identifiable secret key gets an explicit Environment row.
        // Writing one for a partial override would shadow the parent scope's Production setting
        // and point that storefront at an empty Sandbox credential set.
        if ($ownEnvironment !== null) {
            $this->writeIfAbsent($connection, $table, $scope, $scopeId, self::PATH_ENVIRONMENT, $ownEnvironment);
        }

        $this->logger->info(sprintf(
            'PayMongo_Payment: migrated scope %s to %s (secret=%s, public=%s, webhook=%s, environment row=%s).',
            $scopeKey,
            $environment,
            $secretRow !== null ? 'yes' : 'no',
            $publicRow !== null ? 'yes' : 'no',
            $webhookRow !== null ? 'yes' : 'no',
            $ownEnvironment !== null ? 'written' : 'inherited'
        ));
    }

    /**
     * Resolve the environment a scope should use when it has no identifiable secret key of
     * its own, following Magento's own store view -> website -> default fallback order.
     *
     * @param string $scope
     * @param string $scopeId
     * @param array<string, string> $envByScope
     * @param array<string, string> $storeToWebsite
     * @return string
     */
    private function inheritEnvironment(
        string $scope,
        string $scopeId,
        array $envByScope,
        array $storeToWebsite
    ): string {
        if ($scope === self::SCOPE_STORES) {
            $websiteId = $storeToWebsite[$scopeId] ?? null;
            if ($websiteId !== null && isset($envByScope[self::SCOPE_WEBSITES . '|' . $websiteId])) {
                return $envByScope[self::SCOPE_WEBSITES . '|' . $websiteId];
            }
        }

        if ($scope !== self::SCOPE_DEFAULT && isset($envByScope[self::SCOPE_DEFAULT . '|0'])) {
            return $envByScope[self::SCOPE_DEFAULT . '|0'];
        }

        return self::ENV_SANDBOX;
    }

    /**
     * @param AdapterInterface $connection
     * @return array<string, string> store_id => website_id
     */
    private function loadStoreWebsiteMap(AdapterInterface $connection): array
    {
        $map = [];
        $select = $connection->select()->from($this->moduleDataSetup->getTable('store'), ['store_id', 'website_id']);
        foreach ($connection->fetchAll($select) as $row) {
            $map[(string) $row['store_id']] = (string) $row['website_id'];
        }

        return $map;
    }

    /**
     * Insert a config row only when nothing meaningful is stored at that exact
     * scope/scope_id/path yet. Never overwrites: a re-run of this patch (module reinstall,
     * a cleared patch_list row) must not clobber credentials the merchant set after the
     * original migration, nor revert a live store to its old sandbox values.
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param string $scope
     * @param string $scopeId
     * @param string $path
     * @param string $value
     * @return void
     */
    private function writeIfAbsent(
        AdapterInterface $connection,
        string $table,
        string $scope,
        string $scopeId,
        string $path,
        string $value
    ): void {
        if ($value === '') {
            return;
        }

        $existing = $connection->fetchRow(
            $connection->select()
                ->from($table, ['config_id', 'value'])
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
                ->where('path = ?', $path)
        );

        if ($existing) {
            if ((string) ($existing['value'] ?? '') !== '') {
                return;
            }
            $connection->update($table, ['value' => $value], ['config_id = ?' => $existing['config_id']]);
            return;
        }

        $connection->insert($table, [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $path,
            'value' => $value,
        ]);
    }

    /**
     * core_config_data.value is nullable; under strict_types a null reaching a string
     * parameter raises a TypeError, which is an \Error and would abort setup:upgrade.
     *
     * @param array<string, string|null> $row
     * @return string
     */
    private function value(array $row): string
    {
        return (string) ($row['value'] ?? '');
    }

    /**
     * @param string $value
     * @return string
     */
    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return trim((string) $this->encryptor->decrypt(trim($value)));
        } catch (\Exception $e) {
            return '';
        }
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
