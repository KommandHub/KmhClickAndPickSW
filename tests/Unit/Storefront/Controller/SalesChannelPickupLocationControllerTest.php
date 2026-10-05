<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Storefront\Controller;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupLocationAvailabilityService;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupTimeSlotService;
use Kommandhub\ClickAndPickSW\Storefront\Controller\SalesChannelPickupLocationController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SalesChannelPickupLocationController::class)]
class SalesChannelPickupLocationControllerTest extends TestCase
{
    private EntityRepository&MockObject $repository;

    private PickupLocationAvailabilityService&MockObject $availabilityService;

    private PickupTimeSlotService&MockObject $slotService;

    private SystemConfigService&MockObject $systemConfigService;

    private TestSalesChannelPickupLocationController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->availabilityService = $this->createMock(PickupLocationAvailabilityService::class);
        $this->slotService = $this->createMock(PickupTimeSlotService::class);
        $this->systemConfigService = $this->createMock(SystemConfigService::class);

        // Default: customer selection is enabled (default config value)
        $this->systemConfigService
            ->method('getBool')
            ->willReturn(true);

        $this->controller = new TestSalesChannelPickupLocationController(
            $this->repository,
            $this->availabilityService,
            $this->slotService,
            $this->systemConfigService
        );
    }

    public function testIndexFiltersByRelationalScheduleAndAvailability(): void
    {
        $open = new PickupLocationEntity();
        $open->setId('11111111111111111111111111111111');
        $closed = new PickupLocationEntity();
        $closed->setId('22222222222222222222222222222222');

        $collection = new PickupLocationCollection([$open, $closed]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($collection);

        $context = $this->salesChannelContext();

        $this->repository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(function (Criteria $criteria): bool {
                    $filters = $criteria->getFilters();
                    $associations = $criteria->getAssociations();

                    // No JSON ContainsFilter; relational active + sales channel
                    // filters and the schedule associations loaded.
                    $fields = array_map(
                        static fn ($filter): ?string => $filter instanceof EqualsFilter ? $filter->getField() : null,
                        $filters
                    );

                    return \in_array('active', $fields, true)
                        && \in_array('salesChannels.id', $fields, true)
                        // salesChannels is a filter only — never hydrated (avoids
                        // an extra batched query + SalesChannel hydration per row).
                        && !array_key_exists('salesChannels', $associations)
                        && array_key_exists('openingHoursSchedule', $associations)
                        && array_key_exists('specialHours', $associations);
                }),
                $context->getContext()
            )
            ->willReturn($searchResult);

        // Listing filters to locations bookable within the booking window.
        $this->availabilityService
            ->expects(static::once())
            ->method('filterOpenWithinDays')
            ->with([$open, $closed], SalesChannelPickupLocationController::BOOKING_WINDOW_DAYS)
            ->willReturn([$open]);

        $response = $this->controller->index('sales-channel-id', new Request(), $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-location-select-option.html.twig',
            $this->controller->lastTemplate
        );
        static::assertSame([$open], $this->controller->lastParameters['locations']);
        static::assertFalse($this->controller->lastParameters['selectionDisabled']);
    }

    public function testIndexFiltersToDefaultLocationWhenSelectionIsDisabled(): void
    {
        // Create a fresh mock that returns false for selection disabled
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->expects(static::once())
            ->method('getBool')
            ->with('KmhClickAndPickSW.config.enablePickupLocationSelection', 'sales-channel-id')
            ->willReturn(false);

        // Create a new controller instance with the disabled config
        $controller = new TestSalesChannelPickupLocationController(
            $this->repository,
            $this->availabilityService,
            $this->slotService,
            $systemConfigService
        );

        $defaultLocation = new PickupLocationEntity();
        $defaultLocation->setId('11111111111111111111111111111111');

        $collection = new PickupLocationCollection([$defaultLocation]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($collection);

        $context = $this->salesChannelContext();

        $this->repository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(function (Criteria $criteria): bool {
                    $filters = $criteria->getFilters();
                    $associations = $criteria->getAssociations();

                    // Should have the default sales channel filter
                    $fields = array_map(
                        static fn ($filter): ?string => $filter instanceof EqualsFilter ? $filter->getField() : null,
                        $filters
                    );

                    return \in_array('active', $fields, true)
                        && \in_array('salesChannels.id', $fields, true)
                        && \in_array('defaultSalesChannelId', $fields, true)
                        && !array_key_exists('salesChannels', $associations)
                        && array_key_exists('openingHoursSchedule', $associations)
                        && array_key_exists('specialHours', $associations);
                }),
                $context->getContext()
            )
            ->willReturn($searchResult);

        $this->availabilityService
            ->expects(static::once())
            ->method('filterOpenWithinDays')
            ->with([$defaultLocation], SalesChannelPickupLocationController::BOOKING_WINDOW_DAYS)
            ->willReturn([$defaultLocation]);

        $response = $controller->index('sales-channel-id', new Request(), $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-location-select-option.html.twig',
            $controller->lastTemplate
        );
        static::assertSame([$defaultLocation], $controller->lastParameters['locations']);
        static::assertTrue($controller->lastParameters['selectionDisabled']);
    }

    public function testIndexOrdersByNameWithoutCoordinates(): void
    {
        $bravo = $this->location('Bravo', '6.9', '3.38');
        $alpha = $this->location('alpha', '6.53', '3.39');
        $charlie = $this->location('Charlie', null, null);

        static::assertSame([$alpha, $bravo, $charlie], $this->indexLocations([$bravo, $charlie, $alpha], []));
        static::assertSame(['locations', 'selectionDisabled'], array_keys($this->controller->lastParameters));
    }

    public function testIndexOrdersNearestFirstWithValidCoordinates(): void
    {
        // Distances from (6.52, 3.38): ~1.6 km, ~11 km, ~42 km — name order differs.
        $near = $this->location('Zulu', '6.53', '3.39');
        $middle = $this->location('Alpha', '6.62', '3.38');
        $far = $this->location('Mike', '6.9', '3.38');

        $ordered = $this->indexLocations([$far, $middle, $near], ['lat' => '6.52', 'lon' => '3.38']);

        static::assertSame([$near, $middle, $far], $ordered);
        // Option entities only — no distance values are handed to the template.
        static::assertSame(['locations', 'selectionDisabled'], array_keys($this->controller->lastParameters));

        foreach ($this->controller->lastParameters['locations'] as $location) {
            static::assertInstanceOf(PickupLocationEntity::class, $location);
        }
    }

    public function testIndexListsLocationsWithoutUsableCoordinatesLast(): void
    {
        $near = $this->location('Zulu', '6.53', '3.39');
        $blank = $this->location('Bravo', '', '3.38');
        $comma = $this->location('Alpha', '6,52', '3,38');
        $outOfRange = $this->location('Charlie', '95', '3.38');
        $far = $this->location('Mike', '6.9', '3.38');

        $ordered = $this->indexLocations([$blank, $far, $comma, $outOfRange, $near], ['lat' => '6.52', 'lon' => '3.38']);

        static::assertSame([$near, $far, $comma, $blank, $outOfRange], $ordered);
    }

    public function testIndexOrdersEqualDistanceByName(): void
    {
        $bravo = $this->location('Bravo', '6.53', '3.39');
        $alpha = $this->location('Alpha', '6.53', '3.39');

        static::assertSame([$alpha, $bravo], $this->indexLocations([$bravo, $alpha], ['lat' => '6.52', 'lon' => '3.38']));
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('ignoredCoordinatesProvider')]
    public function testIndexIgnoresInvalidCoordinates(array $query): void
    {
        $near = $this->location('Zulu', '6.53', '3.39');
        $far = $this->location('Alpha', '6.9', '3.38');

        static::assertSame([$far, $near], $this->indexLocations([$near, $far], $query));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function ignoredCoordinatesProvider(): iterable
    {
        yield 'latitude out of range' => [['lat' => '95', 'lon' => '3.38']];
        yield 'longitude out of range' => [['lat' => '6.52', 'lon' => '-180.5']];
        yield 'missing longitude' => [['lat' => '6.52']];
        yield 'missing latitude' => [['lon' => '3.38']];
        yield 'non-numeric' => [['lat' => 'north', 'lon' => '3.38']];
        yield 'empty' => [['lat' => '', 'lon' => '']];
    }

    public function testIndexIgnoresCoordinatesWhenGeolocationSortingIsDisabled(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getBool')->willReturnMap([
            ['KmhClickAndPickSW.config.enablePickupLocationSelection', 'sales-channel-id', true],
            ['KmhClickAndPickSW.config.enableGeolocationSorting', 'sales-channel-id', false],
        ]);
        $this->controller = new TestSalesChannelPickupLocationController(
            $this->repository,
            $this->availabilityService,
            $this->slotService,
            $systemConfigService
        );

        $near = $this->location('Zulu', '6.53', '3.39');
        $far = $this->location('Alpha', '6.9', '3.38');

        static::assertSame([$far, $near], $this->indexLocations([$near, $far], ['lat' => '6.52', 'lon' => '3.38']));
    }

    public function testSlotsRendersSlotsForValidDate(): void
    {
        $location = new PickupLocationEntity();
        $location->setId('11111111111111111111111111111111');
        $location->setTimezone('Africa/Lagos');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new PickupLocationCollection([$location]));
        $context = $this->salesChannelContext();

        $this->repository->method('search')->willReturn($searchResult);

        $slot = new \DateTimeImmutable('2024-06-03 09:00:00', new \DateTimeZone('Africa/Lagos'));
        $this->slotService
            ->expects(static::once())
            ->method('getSlots')
            ->with(
                $location,
                static::callback(
                    static fn (\DateTimeImmutable $date): bool => $date->format('Y-m-d') === '2024-06-03'
                        && $date->getTimezone()->getName() === 'Africa/Lagos'
                )
            )
            ->willReturn([$slot]);

        $response = $this->controller->slots(
            'location-id',
            'sales-channel-id',
            new \Symfony\Component\HttpFoundation\Request(['date' => '2024-06-03']),
            $context
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-time-select-option.html.twig',
            $this->controller->lastTemplate
        );
        static::assertSame([$slot], $this->controller->lastParameters['slots']);
    }

    public function testSlotsRendersEmptyForInvalidDate(): void
    {
        $context = $this->salesChannelContext();
        $this->repository->method('search')->willReturn($this->createMock(EntitySearchResult::class));
        $this->slotService->expects(static::never())->method('getSlots');

        $response = $this->controller->slots(
            'location-id',
            'sales-channel-id',
            new \Symfony\Component\HttpFoundation\Request(['date' => 'not-a-date']),
            $context
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame([], $this->controller->lastParameters['slots']);
    }

    public function testSlotsFallsBackToUtcWhenLocationTimezoneIsInvalid(): void
    {
        $location = new PickupLocationEntity();
        $location->setId('11111111111111111111111111111111');
        $location->setTimezone('Not/AZone');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new PickupLocationCollection([$location]));

        $this->repository->method('search')->willReturn($searchResult);
        $this->slotService
            ->expects(static::once())
            ->method('getSlots')
            ->with(
                $location,
                static::callback(
                    static fn (\DateTimeImmutable $date): bool => $date->getTimezone()->getName() === 'UTC'
                        && $date->format('Y-m-d') === '2024-06-03'
                )
            )
            ->willReturn([]);

        $response = $this->controller->slots(
            'location-id',
            'sales-channel-id',
            new \Symfony\Component\HttpFoundation\Request(['date' => '2024-06-03']),
            $this->salesChannelContext()
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame([], $this->controller->lastParameters['slots']);
    }

    public function testSlotsRendersEmptyWhenDateIsWellFormedButInvalid(): void
    {
        $location = new PickupLocationEntity();
        $location->setId('11111111111111111111111111111111');
        $location->setTimezone('UTC');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new PickupLocationCollection([$location]));

        $this->repository->method('search')->willReturn($searchResult);
        $this->slotService->expects(static::never())->method('getSlots');

        // Matches the YYYY-MM-DD shape but is not a real calendar date.
        $response = $this->controller->slots(
            'location-id',
            'sales-channel-id',
            new \Symfony\Component\HttpFoundation\Request(['date' => '9999-99-99']),
            $this->salesChannelContext()
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame([], $this->controller->lastParameters['slots']);
    }

    /**
     * @param list<PickupLocationEntity> $locations
     * @param array<string, string> $query
     *
     * @return list<PickupLocationEntity>
     */
    private function indexLocations(array $locations, array $query): array
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new PickupLocationCollection($locations));
        $this->repository->method('search')->willReturn($searchResult);
        $this->availabilityService->method('filterOpenWithinDays')->willReturnArgument(0);

        $this->controller->index('sales-channel-id', new Request($query), $this->salesChannelContext());

        /** @var list<PickupLocationEntity> $ordered */
        $ordered = $this->controller->lastParameters['locations'];

        return $ordered;
    }

    private function location(string $name, ?string $latitude, ?string $longitude): PickupLocationEntity
    {
        $location = new PickupLocationEntity();
        $location->setId(md5($name));
        $location->setName($name);
        $location->setLatitude($latitude);
        $location->setLongitude($longitude);

        return $location;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}

class TestSalesChannelPickupLocationController extends SalesChannelPickupLocationController
{
    public string $lastTemplate = '';

    /**
     * @var array<string, mixed>
     */
    public array $lastParameters = [];

    protected function renderStorefront(string $view, array $parameters = []): Response
    {
        $this->lastTemplate = $view;
        $this->lastParameters = $parameters;

        return new Response('rendered');
    }
}
