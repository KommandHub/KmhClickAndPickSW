<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One opening interval for one weekday of a pickup location. Multiple rows per
 * weekday express multiple intervals (e.g. 09:00-13:00 and 14:00-18:00). Times
 * are local wall-clock ("HH:MM") in the location's timezone, never UTC.
 */
#[AutoconfigureTag('shopware.entity.definition', ['entity' => PickupLocationOpeningHourDefinition::ENTITY_NAME])]
class PickupLocationOpeningHourDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'kmh_pickup_location_opening_hour';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return PickupLocationOpeningHourCollection::class;
    }

    public function getEntityClass(): string
    {
        return PickupLocationOpeningHourEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new FkField('pickup_location_id', 'pickupLocationId', PickupLocationDefinition::class))
                ->addFlags(new Required(), new ApiAware()),
            // ISO-8601 weekday: 1 = Monday … 7 = Sunday.
            (new IntField('day_of_week', 'dayOfWeek'))->addFlags(new Required(), new ApiAware()),
            (new StringField('open_time', 'openTime'))->addFlags(new Required(), new ApiAware()),
            (new StringField('close_time', 'closeTime'))->addFlags(new Required(), new ApiAware()),

            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),

            new ManyToOneAssociationField('pickupLocation', 'pickup_location_id', PickupLocationDefinition::class, 'id'),
        ]);
    }
}
