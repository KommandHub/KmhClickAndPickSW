<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Extension;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationDefinition;
use Kommandhub\ClickAndPickSW\Extension\OrderExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

#[CoversClass(OrderExtension::class)]
class OrderExtensionTest extends TestCase
{
    public function testExtendsTheOrderEntity(): void
    {
        static::assertSame(OrderDefinition::ENTITY_NAME, (new OrderExtension())->getEntityName());
    }

    public function testAddsAutoloadedOneToOnePickupAssociation(): void
    {
        $collection = new FieldCollection();
        (new OrderExtension())->extendFields($collection);

        $field = $collection->first();

        static::assertInstanceOf(OneToOneAssociationField::class, $field);
        static::assertSame('kmhPickupLocation', $field->getPropertyName());
        static::assertSame(OrderPickupLocationDefinition::class, $field->getReferenceClass());
        static::assertSame('order_id', $field->getReferenceField());
        static::assertTrue($field->getAutoload());
        static::assertNotNull($field->getFlag(CascadeDelete::class));
        static::assertNotNull($field->getFlag(ApiAware::class));
    }
}
