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
    public function index(string $salesChannelId, SalesChannelContext $context): Response
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

        /** @var list<\Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity> $locations */
        $locations = array_values($this->kmhPickupLocationRepository
            ->search($criteria, $context->getContext())
            ->getEntities()
            ->getElements());

        // List everything open *today* (in each location's timezone) so a
        // customer can still choose a location that opens later today — not only
        // one open at this exact minute.
        $openLocations = $this->availabilityService->filterOpenOnDate($locations);

        return $this->renderStorefront(
            '@KmhClickAndPickSW/storefront/component/shipping/custom/pickup-location-select-option.html.twig',
            [
                'locations' => $openLocations,
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
        )->first();

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
