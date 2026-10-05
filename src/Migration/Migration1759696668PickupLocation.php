<?php declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSalesChannelMapping\PickupLocationSalesChannelMappingDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1759696668PickupLocation extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1759696668;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $pickupLocationTable = PickupLocationDefinition::ENTITY_NAME;

        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `{$pickupLocationTable}` (
                `id` BINARY(16) NOT NULL,
                `default_sales_channel_id` BINARY(16) NULL,
                `name` VARCHAR(255) NOT NULL,
                `street` VARCHAR(255) NOT NULL,
                `phone_number` VARCHAR(255) NULL,
                `email` VARCHAR(255) NULL,
                `additional_address_line1` VARCHAR(255) NULL,
                `additional_address_line2` VARCHAR(255) NULL,
                `city` VARCHAR(255) NOT NULL,
                `postal_code` VARCHAR(20) NOT NULL,
                `time_format` VARCHAR(20) NOT NULL DEFAULT '24h',
                `latitude` VARCHAR(50) NULL,
                `longitude` VARCHAR(50) NULL,
                `location_code` VARCHAR(100) NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                -- Supports active-only listings (storefront + admin). InnoDB
                -- appends the PK, so this is physically (active, id) at the leaf;
                -- an explicit (active, id) would be redundant.
                INDEX `idx.{$pickupLocationTable}.active` (`active`),
                INDEX `idx.{$pickupLocationTable}.location_code` (`location_code`),
                CONSTRAINT `fk.{$pickupLocationTable}.default_sales_channel_id`
                    FOREIGN KEY (`default_sales_channel_id`)
                    REFERENCES `sales_channel` (`id`)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $mappingTable = PickupLocationSalesChannelMappingDefinition::ENTITY_NAME;

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `{$mappingTable}` (
                `pickup_location_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`pickup_location_id`, `sales_channel_id`),
                CONSTRAINT `fk.{$mappingTable}.pickup_location_id`
                    FOREIGN KEY (`pickup_location_id`)
                    REFERENCES `{$pickupLocationTable}` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.{$mappingTable}.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }
}
