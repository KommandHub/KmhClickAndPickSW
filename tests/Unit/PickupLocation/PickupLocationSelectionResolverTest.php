<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\PickupLocation;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupSelection;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\StoredPickupSelection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationSelectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(PickupLocationSelectionResolver::class)]
#[UsesClass(StoredPickupSelection::class)]
#[UsesClass(PickupSelection::class)]
class PickupLocationSelectionResolverTest extends TestCase
{
    private const SALES_CHANNEL_ID = '11111111111111111111111111111111';
    private const LOCATION_ID = 'fedcba9876543210fedcba9876543210';

    private EntityRepository&MockObject $pickupLocationRepository;

    private PickupContextStorage&MockObject $pickupContextStorage;

    private PickupLocationSelectionResolver $resolver;

    protected function setUp(): void
    {
        $this->pickupLocationRepository = $this->createMock(EntityRepository::class);
        $this->pickupContextStorage = $this->createMock(PickupContextStorage::class);
        $this->resolver = new PickupLocationSelectionResolver(
            $this->pickupLocationRepository,
            $this->pickupContextStorage
        );
    }

    public function testDetectsPickupShippingMethodByConfiguredId(): void
    {
        static::assertTrue($this->resolver->isPickupShippingMethod(
            $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID))
        ));
        static::assertFalse($this->resolver->isPickupShippingMethod(
            $this->salesChannelContext($this->shippingMethod('different-shipping-id'))
        ));
    }

    public function testResolvesValidPickupLocationFromStoredSelection(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->with($context)
            ->willReturn(new StoredPickupSelection(self::LOCATION_ID));

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);

        $this->pickupLocationRepository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(function (Criteria $criteria): bool {
                    $filter = $criteria->getFilters()[0] ?? null;

                    if (!$filter instanceof MultiFilter || $criteria->getLimit() !== 1) {
                        return false;
                    }

                    $queries = $filter->getQueries();

                    return $filter->getOperator() === MultiFilter::CONNECTION_AND
                        && $queries[0] instanceof EqualsFilter
                        && $queries[0]->getField() === 'id'
                        && $queries[0]->getValue() === self::LOCATION_ID
                        && $queries[1] instanceof EqualsFilter
                        && $queries[1]->getField() === 'active'
                        && $queries[1]->getValue() === true
                        && $queries[2] instanceof EqualsFilter
                        && $queries[2]->getField() === 'salesChannels.id'
                        && $queries[2]->getValue() === self::SALES_CHANNEL_ID;
                }),
                $context->getContext()
            )
            ->willReturn($this->firstResult($pickupLocation));

        static::assertSame($pickupLocation, $this->resolver->resolve($context));
    }

    public function testResolveReturnsNullWhenNothingStored(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(new StoredPickupSelection());

        $this->pickupLocationRepository->expects(static::never())->method('search');

        static::assertNull($this->resolver->resolve($context));
    }

    public function testResolveReturnsNullWhenStoredLocationNoLongerResolves(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(new StoredPickupSelection(self::LOCATION_ID));

        $this->pickupLocationRepository
            ->expects(static::once())
            ->method('search')
            ->willReturn($this->firstResult(new \stdClass()));

        static::assertNull($this->resolver->resolve($context));
    }

    public function testResolveSelectionReturnsLocationTimeAndComment(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(
            new StoredPickupSelection(self::LOCATION_ID, '2024-06-03T10:00:00+01:00', 'Ring the bell')
        );

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);
        $this->pickupLocationRepository->method('search')->willReturn($this->firstResult($pickupLocation));

        $selection = $this->resolver->resolveSelection($context);

        static::assertNotNull($selection);
        static::assertSame($pickupLocation, $selection->pickupLocation);
        static::assertInstanceOf(\DateTimeImmutable::class, $selection->pickupTime);
        static::assertSame('2024-06-03T10:00:00+01:00', $selection->pickupTime->format('Y-m-d\TH:i:sP'));
        static::assertSame('Ring the bell', $selection->comment);
    }

    public function testResolveSelectionWithoutChosenTimeReturnsNullPickupTime(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(new StoredPickupSelection(self::LOCATION_ID));

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);
        $this->pickupLocationRepository->method('search')->willReturn($this->firstResult($pickupLocation));

        $selection = $this->resolver->resolveSelection($context);

        static::assertNotNull($selection);
        static::assertNull($selection->pickupTime);
        static::assertNull($selection->comment);
    }

    public function testResolveSelectionIgnoresUnparseablePickupTime(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(
            new StoredPickupSelection(self::LOCATION_ID, 'not-a-date', null)
        );

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);
        $this->pickupLocationRepository->method('search')->willReturn($this->firstResult($pickupLocation));

        $selection = $this->resolver->resolveSelection($context);

        static::assertNotNull($selection);
        static::assertNull($selection->pickupTime);
        static::assertNull($selection->comment);
    }

    public function testResolveSelectionReturnsNullWhenNoLocation(): void
    {
        $context = $this->salesChannelContext($this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID));
        $this->pickupContextStorage->method('load')->willReturn(new StoredPickupSelection());

        static::assertNull($this->resolver->resolveSelection($context));
    }

    private function salesChannelContext(ShippingMethodEntity $shippingMethod): SalesChannelContext&MockObject
    {
        $context = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getContext', 'getShippingMethod', 'getSalesChannelId'])
            ->getMock();
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getShippingMethod')->willReturn($shippingMethod);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $context;
    }

    private function shippingMethod(string $id): ShippingMethodEntity
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($id);

        return $shippingMethod;
    }

    private function firstResult(?object $first): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($first);

        return $result;
    }
}
