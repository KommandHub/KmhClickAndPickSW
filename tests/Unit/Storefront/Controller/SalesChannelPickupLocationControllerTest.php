<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Storefront\Controller;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupLocationAvailabilityService;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupTimeSlotService;
use Kommandhub\ClickAndPickSW\Storefront\Controller\SalesChannelPickupLocationController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SalesChannelPickupLocationController::class)]
class SalesChannelPickupLocationControllerTest extends TestCase
{
    private EntityRepository&MockObject $repository;

    private PickupLocationAvailabilityService&MockObject $availabilityService;

    private PickupTimeSlotService&MockObject $slotService;

    private TestSalesChannelPickupLocationController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->availabilityService = $this->createMock(PickupLocationAvailabilityService::class);
        $this->slotService = $this->createMock(PickupTimeSlotService::class);
        $this->controller = new TestSalesChannelPickupLocationController(
            $this->repository,
            $this->availabilityService,
            $this->slotService
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

        // Listing filters to locations open *today* (selectable), not open-now.
        $this->availabilityService
            ->expects(static::once())
            ->method('filterOpenOnDate')
            ->with([$open, $closed])
            ->willReturn([$open]);

        $response = $this->controller->index('sales-channel-id', $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-location-select-option.html.twig',
            $this->controller->lastTemplate
        );
        static::assertSame([$open], $this->controller->lastParameters['locations']);
    }

    public function testSlotsRendersSlotsForValidDate(): void
    {
        $location = new PickupLocationEntity();
        $location->setId('11111111111111111111111111111111');
        $location->setTimezone('Africa/Lagos');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($location);
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
        $searchResult->method('first')->willReturn($location);

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
        $searchResult->method('first')->willReturn($location);

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
