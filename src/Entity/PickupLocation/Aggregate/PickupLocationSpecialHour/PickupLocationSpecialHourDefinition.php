<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A special-date override for one pickup location: a holiday/closure (closed =
 * true) or exceptional opening hours for a specific date. Multiple rows per date
 * express multiple exceptional intervals. Overrides the regular weekly schedule
 * for that date. Times are local wall-clock ("HH:MM").
 */
#[AutoconfigureTag('shopware.entity.definition', ['entity' => PickupLocationSpecialHourDefinition::ENTITY_NAME])]
class PickupLocationSpecialHourDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'kmh_pickup_location_special_hour';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return PickupLocationSpecialHourCollection::class;
    }

    public function getEntityClass(): string
    {
        return PickupLocationSpecialHourEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new FkField('pickup_location_id', 'pickupLocationId', PickupLocationDefinition::class))
                ->addFlags(new Required(), new ApiAware()),
            (new DateField('date', 'date'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('closed', 'closed'))->addFlags(new Required(), new ApiAware()),
            (new StringField('open_time', 'openTime'))->addFlags(new ApiAware()),
            (new StringField('close_time', 'closeTime'))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),

            new ManyToOneAssociationField('pickupLocation', 'pickup_location_id', PickupLocationDefinition::class, 'id'),
        ]);
    }
}
