<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Util\Database\TableHelper;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Makes the order pickup record versioned (`version_id`, primary key
 * `(id, version_id)`), like Shopware's own order children.
 *
 * Without it, opening an order in the Administration cloned the order into a
 * draft version and re-pointed the *same* pickup row at that draft; discarding
 * or merging the draft then cascade-deleted the row. Records already re-pointed
 * at a draft that still exists are moved back to the live order version here.
 */
class Migration1760600001VersionOrderPickupLocation extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760600001;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $table = OrderPickupLocationDefinition::ENTITY_NAME;
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        if (!TableHelper::columnExists($connection, $table, 'version_id')) {
            $connection->executeStatement("ALTER TABLE `{$table}` ADD COLUMN `version_id` BINARY(16) NULL AFTER `id`");
            $connection->executeStatement("UPDATE `{$table}` SET `version_id` = :live", ['live' => $live]);
            $connection->executeStatement(<<<SQL
                ALTER TABLE `{$table}`
                    MODIFY `version_id` BINARY(16) NOT NULL,
                    DROP PRIMARY KEY,
                    ADD PRIMARY KEY (`id`, `version_id`)
                SQL);
        }

        // Rescue rows stranded on an Administration draft: point them back at the
        // live order, unless the live order already has its own record.
        $connection->executeStatement(<<<SQL
            UPDATE `{$table}` record
            SET record.`order_version_id` = :live
            WHERE record.`version_id` = :live
              AND record.`order_version_id` <> :live
              AND NOT EXISTS (
                  SELECT 1 FROM (SELECT `order_id`, `order_version_id` FROM `{$table}`) live_record
                  WHERE live_record.`order_id` = record.`order_id`
                    AND live_record.`order_version_id` = :live
              )
            SQL, ['live' => $live]);
    }
}
