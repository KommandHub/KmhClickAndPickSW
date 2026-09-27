<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Extension;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;

/**
 * Adds the OneToOne `kmhPickupLocation` association to the order, linking
 * it to its {@see OrderPickupLocationDefinition} record. Autoloaded, so every
 * order read (finish page, account, admin API, Flow mail) carries the pickup
 * record without a per-read criteria subscriber. Deleting the order cascades to
 * the pickup record; deleting a pickup *location* only nulls the FK (see the
 * definition), so the order keeps its historical pickup data.
 */
class OrderExtension extends EntityExtension
{
    public function getEntityName(): string
    {
        return OrderDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'kmhPickupLocation',
                'id',
                'order_id',
                OrderPickupLocationDefinition::class,
                true
            ))->addFlags(new CascadeDelete(), new ApiAware())
        );
    }
}
