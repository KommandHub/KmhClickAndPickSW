<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\PickupLocation;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSalesChannelMapping\PickupLocationSalesChannelMappingDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour\PickupLocationSpecialHourDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('shopware.entity.definition', ['entity' => PickupLocationDefinition::ENTITY_NAME])]
class PickupLocationDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'kmh_pickup_location';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return PickupLocationCollection::class;
    }

    public function getEntityClass(): string
    {
        return PickupLocationEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new StringField('name', 'name'))->addFlags(new Required(), new ApiAware()),
            (new StringField('street', 'street'))->addFlags(new Required(), new ApiAware()),
            (new StringField('phone_number', 'phoneNumber'))->addFlags(new ApiAware()),
            (new StringField('email', 'email'))->addFlags(new Required(), new ApiAware()),
            (new StringField('additional_address_line1', 'additionalAddressLine1')),
            (new StringField('additional_address_line2', 'additionalAddressLine2')),
            (new StringField('city', 'city'))->addFlags(new Required(), new ApiAware()),
            (new StringField('postal_code', 'postalCode'))->addFlags(new Required(), new ApiAware()),
            (new StringField('time_format', 'timeFormat'))->addFlags(new ApiAware()),
            // IANA timezone (e.g. "Europe/Berlin"). Availability is evaluated in
            // this zone; opening/closing times are local wall-clock, never UTC.
            (new StringField('timezone', 'timezone'))->addFlags(new ApiAware()),
            (new StringField('latitude', 'latitude'))->addFlags(new ApiAware()),
            (new StringField('longitude', 'longitude'))->addFlags(new ApiAware()),
            (new StringField('location_code', 'locationCode'))->addFlags(new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware()),
            (new FkField('default_sales_channel_id', 'defaultSalesChannelId', SalesChannelDefinition::class))->addFlags(new Required(), new ApiAware()),

            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),

            new ManyToManyAssociationField(
                'salesChannels',
                SalesChannelDefinition::class,
                PickupLocationSalesChannelMappingDefinition::ENTITY_NAME,
                'pickup_location_id',
                'sales_channel_id',
            ),

            (new ManyToOneAssociationField(
                'defaultSalesChannel',
                'default_sales_channel_id',
                SalesChannelDefinition::class,
                'id',
                false
            ))->addFlags(new ApiAware()),

            (new OneToManyAssociationField(
                'openingHoursSchedule',
                PickupLocationOpeningHourDefinition::class,
                'pickup_location_id',
                'id'
            ))->addFlags(new CascadeDelete(), new ApiAware()),

            (new OneToManyAssociationField(
                'specialHours',
                PickupLocationSpecialHourDefinition::class,
                'pickup_location_id',
                'id'
            ))->addFlags(new CascadeDelete(), new ApiAware()),
        ]);
    }
}
