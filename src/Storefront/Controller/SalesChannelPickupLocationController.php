<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Storefront\Controller;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupLocationAvailabilityService;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupTimeSlotService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class SalesChannelPickupLocationController extends StorefrontController
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** How far ahead a location must have opening hours to be offered. */
    public const BOOKING_WINDOW_DAYS = 14;

    public function __construct(
        private readonly EntityRepository $kmhPickupLocationRepository,
        private readonly PickupLocationAvailabilityService $availabilityService,
        private readonly PickupTimeSlotService $slotService,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    #[Route(
        path: '/kmh/sales-channel/{salesChannelId}/pickup-locations',
        name: 'frontend.kmh.sales-channel.pickup-locations.index',
        defaults: ['XmlHttpRequest' => 'true'],
        methods: ['GET']
    )]
    public function index(string $salesChannelId, Request $request, SalesChannelContext $context): Response
    {
        $enableSelection = $this->systemConfigService->getBool(
            'KmhClickAndPickSW.config.enablePickupLocationSelection',
            $salesChannelId
        );

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        // The sales-channel assignment is a filter only — the DAL joins the
        // mapping table for it without hydrating the SalesChannel entities, which
        // this endpoint never renders. Adding the `salesChannels` association here
        // would fire an extra batched query and hydrate a SalesChannelCollection
        // per location for nothing.
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        // Relational schedule loaded once (batched IN() per association, not per
        // location); availability is evaluated in PHP so each location's own
        // timezone drives the decision (a single SQL weekday filter cannot, since
        // the local weekday differs per timezone).
        $criteria->addAssociation('openingHoursSchedule');
        $criteria->addAssociation('specialHours');

        // When customer selection is disabled, only load the default location
        if (!$enableSelection) {
            $criteria->addFilter(new EqualsFilter('defaultSalesChannelId', $salesChannelId));
        }

        /** @var list<PickupLocationEntity> $locations */
        $locations = array_values($this->kmhPickupLocationRepository
            ->search($criteria, $context->getContext())
            ->getEntities()
            ->getElements());

        // Offer every location the customer can actually book: open on at least
        // one day of the booking window, in each location's own timezone. The
        // date picker and slot list then narrow it to a concrete time.
        $openLocations = $this->availabilityService->filterOpenWithinDays($locations, self::BOOKING_WINDOW_DAYS);

        return $this->renderStorefront(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-location-select-option.html.twig',
            [
                'locations' => $this->sortLocations(
                    $openLocations,
                    $this->resolveCustomerCoordinates($request, $salesChannelId)
                ),
                'selectionDisabled' => !$enableSelection,
            ]
        );
    }

    #[Route(
        path: '/kmh/sales-channel/{salesChannelId}/location/{locationId}/slots',
        name: 'frontend.kmh.sales-channel.pickup-locations.slots',
        defaults: ['XmlHttpRequest' => 'true'],
        methods: ['GET']
    )]
    public function slots(
        string $locationId,
        string $salesChannelId,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): Response {
        $criteria = new Criteria([$locationId]);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        $criteria->addAssociation('openingHoursSchedule');
        $criteria->addAssociation('specialHours');
        $criteria->setLimit(1);

        $location = $this->kmhPickupLocationRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->getEntities()->first();

        // Build the requested day at midnight in the location's own timezone so
        // it maps to the intended calendar day for any offset. An unknown
        // location or unparseable date yields no slots (empty list rendered).
        $slots = [];
        $dateString = $request->query->get('date');

        if ($location instanceof PickupLocationEntity && \is_string($dateString)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)
        ) {
            $date = $this->buildLocalDate($dateString, $location->getTimezone());

            if ($date !== null) {
                $slots = $this->slotService->getSlots($location, $date);
            }
        }

        return $this->renderStorefront(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-time-select-option.html.twig',
            ['slots' => $slots]
        );
    }

    /**
     * Customer coordinates for this request only — never stored, logged or
     * echoed back. Both must be valid and the feature enabled for the channel.
     *
     * @return array{float, float}|null
     */
    private function resolveCustomerCoordinates(Request $request, string $salesChannelId): ?array
    {
        $latitude = $this->parseCoordinate($request->query->get('lat'), 90.0);
        $longitude = $this->parseCoordinate($request->query->get('lon'), 180.0);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        if (!$this->systemConfigService->getBool('KmhClickAndPickSW.config.enableGeolocationSorting', $salesChannelId)) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Nearest first when an origin is given; locations without usable stored
     * coordinates go last. Name breaks ties and is the order without an origin.
     *
     * @param list<PickupLocationEntity> $locations
     * @param array{float, float}|null $origin
     *
     * @return list<PickupLocationEntity>
     */
    private function sortLocations(array $locations, ?array $origin): array
    {
        $rows = array_map(
            fn (PickupLocationEntity $location): array => [$location, $origin === null ? null : $this->distanceTo($location, $origin)],
            $locations
        );

        usort($rows, static function (array $a, array $b): int {
            $byDistance = match (true) {
                $a[1] === $b[1] => 0,
                $a[1] === null => 1,
                $b[1] === null => -1,
                default => $a[1] <=> $b[1],
            };

            return $byDistance !== 0 ? $byDistance : strnatcasecmp($a[0]->getName(), $b[0]->getName());
        });

        return array_column($rows, 0);
    }

    /**
     * Haversine great-circle distance in km, or null when the location's stored
     * coordinates are blank or not numeric.
     *
     * @param array{float, float} $origin
     */
    private function distanceTo(PickupLocationEntity $location, array $origin): ?float
    {
        $latitude = $this->parseCoordinate($location->getLatitude(), 90.0);
        $longitude = $this->parseCoordinate($location->getLongitude(), 180.0);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $deltaLatitude = deg2rad($latitude - $origin[0]);
        $deltaLongitude = deg2rad($longitude - $origin[1]);
        $a = sin($deltaLatitude / 2) ** 2
            + cos(deg2rad($origin[0])) * cos(deg2rad($latitude)) * sin($deltaLongitude / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function parseCoordinate(mixed $value, float $limit): ?float
    {
        if (!\is_string($value) || !is_numeric(trim($value))) {
            return null;
        }

        $coordinate = (float)trim($value);

        return abs($coordinate) <= $limit ? $coordinate : null;
    }

    private function buildLocalDate(string $date, ?string $timezone): ?\DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($timezone ?? 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }

        try {
            return new \DateTimeImmutable($date . ' 00:00:00', $zone);
        } catch (\Exception) {
            return null;
        }
    }
}
