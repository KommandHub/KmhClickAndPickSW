<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Util\Database\TableHelper;

/**
 * Adds `default_sales_channel_id` to installs whose pickup location table was
 * created before the column was folded into Migration1759696668 (that migration
 * had already run there, so they never got it while the DAL field is Required).
 *
 * Existing locations are backfilled with one of their assigned sales channels so
 * they stay editable in the Administration. Fresh installs already have the
 * column; the guard makes this a no-op for them.
 */
class Migration1760600000AddDefaultSalesChannelColumn extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760600000;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $table = PickupLocationDefinition::ENTITY_NAME;

        if (!TableHelper::columnExists($connection, $table, 'default_sales_channel_id')) {
            $connection->executeStatement(<<<SQL
                ALTER TABLE `{$table}`
                    ADD COLUMN `default_sales_channel_id` BINARY(16) NULL AFTER `active`,
                    ADD CONSTRAINT `fk.{$table}.default_sales_channel_id`
                        FOREIGN KEY (`default_sales_channel_id`)
                        REFERENCES `sales_channel` (`id`)
                        ON DELETE SET NULL ON UPDATE CASCADE
                SQL);
        }

        $connection->executeStatement(<<<SQL
            UPDATE `{$table}` location
            SET location.`default_sales_channel_id` = (
                SELECT mapping.`sales_channel_id`
                FROM `{$table}_sales_channel` mapping
                WHERE mapping.`pickup_location_id` = location.`id`
                ORDER BY mapping.`sales_channel_id`
                LIMIT 1
            )
            WHERE location.`default_sales_channel_id` IS NULL
            SQL);
    }
}
