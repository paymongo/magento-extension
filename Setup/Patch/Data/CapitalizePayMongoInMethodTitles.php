<?php

declare(strict_types=1);

namespace PayMongo\Payment\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use Psr\Log\LoggerInterface;

/**
 * One-time brand-casing fix: rewrites "Paymongo" to "PayMongo" in every saved PayMongo
 * payment method title.
 *
 * etc/config.xml only supplies the DEFAULT title. The moment anyone saves the PayMongo
 * config section in admin, Magento writes a core_config_data row for every field in that
 * section — including the titles — and from then on the saved row wins over the shipped
 * default. So correcting config.xml alone fixes fresh installs and nothing else: any store
 * that has ever opened and saved this section keeps showing the old spelling at checkout.
 * This patch retrofits those saved rows.
 *
 * Substring replacement, not overwrite. Writing the new shipped default over each row
 * would silently destroy any wording a merchant chose for themselves — "Pay with GCash
 * (via Paymongo)" would come back as plain "GCash (via PayMongo)". Replacing only the
 * brand token inside whatever is stored fixes the casing and leaves the rest of their
 * label untouched.
 *
 * The replacement is case-sensitive and therefore idempotent: "PayMongo" does not contain
 * "Paymongo", so a value already corrected cannot be corrected twice, and a title that
 * never mentioned the brand ("GCash") is left exactly as it is. Deliberate variants such
 * as an all-caps "PAYMONGO" are likewise left alone rather than being normalised against
 * the merchant's intent.
 *
 * Every scope is swept, not just default. A multi-store merchant can hold website- and
 * store-scoped overrides on the same path, and each is a separate row that would otherwise
 * keep the old spelling on that storefront alone. Rows are matched by path pattern and
 * updated by config_id for that reason — a read-one/write-one by path would collapse
 * distinct scopes onto each other.
 *
 * Finding nothing is a normal outcome, not a failure: on a fresh install no title rows
 * exist yet (nothing in Setup/ writes them), so the sweep matches zero rows and the patch
 * is a no-op. Those installs get the correct spelling from etc/config.xml directly.
 *
 * Historical orders are deliberately out of scope. Magento snapshots the method title onto
 * sales_order_payment.additional_information at placement time, and that snapshot is what
 * order emails and invoice PDFs render. Rewriting it would falsify the record of what the
 * customer actually saw, so past orders keep the spelling they were placed under.
 *
 * No cache handling here, matching the other patches in this directory: bin/magento
 * setup:upgrade flushes configuration cache once the patch list has run.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CapitalizePayMongoInMethodTitles implements DataPatchInterface
{
    /**
     * This patch's class name before the module was renamed from DevTeam_Paymongo.
     */
    private const LEGACY_PATCH_NAME = 'DevTeam\\Paymongo\\Setup\\Patch\\Data\\CapitalizePayMongoInMethodTitles';

    /**
     * Matches the all-in-one method's payment/paymongo/title as well as every individual
     * method's payment/paymongo_<code>/title. The underscore in those codes is a
     * single-character LIKE wildcard rather than a literal, which is harmless here — it
     * still matches the literal underscore, and the payment/paymongo prefix keeps the
     * pattern from reaching any other vendor's configuration.
     */
    private const TITLE_PATH_PATTERN = 'payment/paymongo%/title';

    private const OLD_BRAND = 'Paymongo';
    private const NEW_BRAND = 'PayMongo';

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
                    ->where('path LIKE ?', self::TITLE_PATH_PATTERN)
                    ->where('value LIKE ?', '%' . self::OLD_BRAND . '%')
            );

            if (!$rows) {
                // Expected on a fresh install, and on any store that never saved the
                // PayMongo config section — both read the corrected etc/config.xml default.
                return;
            }

            $updated = $this->rewrite($connection, $table, $rows);

            $this->logger->info(sprintf(
                'PayMongo_Payment: corrected PayMongo brand casing in %d saved payment method '
                . 'title(s) across %d scope(s).',
                $updated,
                count(array_unique(array_map(
                    static fn (array $row): string => $row['scope'] . '|' . $row['scope_id'],
                    $rows
                )))
            ));
        } finally {
            // Must run even if an update throws, otherwise the connection is left with
            // foreign key checks disabled and an altered sql_mode for the rest of the request.
            $this->moduleDataSetup->endSetup();
        }
    }

    /**
     * Rewrite the brand token in each matched row, addressing rows by config_id so that
     * website- and store-scoped overrides on the same path stay distinct.
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param array<int, array<string, mixed>> $rows
     * @return int Number of rows actually changed
     */
    private function rewrite(AdapterInterface $connection, string $table, array $rows): int
    {
        $updated = 0;

        foreach ($rows as $row) {
            $current = (string) ($row['value'] ?? '');
            $corrected = str_replace(self::OLD_BRAND, self::NEW_BRAND, $current);

            // The LIKE above is case-insensitive under MySQL's default collation, so a row
            // holding e.g. "PAYMONGO" can match without containing the exact token this
            // patch replaces. Skipping unchanged values keeps those variants untouched.
            if ($corrected === $current) {
                continue;
            }

            $connection->update(
                $table,
                ['value' => $corrected],
                ['config_id = ?' => (int) $row['config_id']]
            );
            $updated++;
        }

        return $updated;
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
