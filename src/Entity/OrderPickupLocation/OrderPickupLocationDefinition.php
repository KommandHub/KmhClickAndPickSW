<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The single source of truth for pickup data on an order: which location, the
 * chosen local pickup time and the customer's instructions. One row per order
 * (OneToOne). Kept as historical order data; if the pickup location is later
 * deleted, `pickup_location_id` is set NULL rather than removing this row.
 */
#[AutoconfigureTag('shopware.entity.definition', ['entity' => OrderPickupLocationDefinition::ENTITY_NAME])]
class OrderPickupLocationDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'kmh_order_pickup_location';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return OrderPickupLocationCollection::class;
    }

    public function getEntityClass(): string
    {
        return OrderPickupLocationEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),

            (new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new ReferenceVersionField(OrderDefinition::class))->addFlags(new Required()),

            // Nullable + ON DELETE SET NULL: deleting a location keeps the order's
            // historical pickup record.
            (new FkField('pickup_location_id', 'pickupLocationId', PickupLocationDefinition::class))->addFlags(new ApiAware()),

            // Local wall-clock instant the customer chose to collect the order.
            (new DateTimeField('pickup_time', 'pickupTime'))->addFlags(new ApiAware()),
            (new LongTextField('comment', 'comment'))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),

            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id'),
            // Autoloaded so the pickup record's parent order carries the resolved
            // location wherever it is read (confirmation page, admin, Flow mail).
            new ManyToOneAssociationField('pickupLocation', 'pickup_location_id', PickupLocationDefinition::class, 'id', true),
        ]);
    }
}
