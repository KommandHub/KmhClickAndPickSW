<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSalesChannelMapping;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * Entity definition for the many-to-many mapping between pickup locations and sales channels.
 * Defines the database schema and relationships for PickupLocationSalesChannelMapping.
 */
class PickupLocationSalesChannelMappingDefinition extends MappingEntityDefinition
{
    public const ENTITY_NAME = 'kmh_pickup_location_sales_channel';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return PickupLocationSalesChannelMappingCollection::class;
    }

    public function getEntityClass(): string
    {
        return PickupLocationSalesChannelMapping::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('pickup_location_id', 'pickupLocationId', PickupLocationDefinition::class))
                ->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))
                ->addFlags(new PrimaryKey(), new Required(), new ApiAware()),

            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),

            new ManyToOneAssociationField(
                'pickupLocation',
                'pickup_location_id',
                PickupLocationDefinition::class,
                'id',
                false,
            ),
            new ManyToOneAssociationField(
                'salesChannel',
                'sales_channel_id',
                SalesChannelDefinition::class,
                'id',
                false,
            ),
        ]);
    }
}
