<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\PickupLocation;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupSelection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Resolves the customer's selected pickup location for the active sales-channel
 * context and validates that it still belongs to the current sales channel.
 *
 * The selection is read from {@see PickupContextStorage} (the persisted context
 * payload) rather than a request-local context extension. That extension is only
 * attached on context *resolution* and reflects the payload as it was at the
 * start of the request; during the very request that persists a new selection —
 * or on a cloned/restored context that never passed the resolver listener — it is
 * stale or absent. Reading the persisted store makes resolution deterministic on
 * every cart-calculation pass, so a valid selection can never intermittently
 * disappear between recalculations.
 */
readonly class PickupLocationSelectionResolver
{
    public function __construct(
        private EntityRepository $kmhPickupLocationRepository,
        private PickupContextStorage $pickupContextStorage,
    ) {
    }

    public function isPickupShippingMethod(SalesChannelContext $context): bool
    {
        return $context->getShippingMethod()->getId() === KmhClickAndPickSW::SHIPPING_METHOD_ID;
    }

    public function resolve(SalesChannelContext $context): ?PickupLocationEntity
    {
        return $this->loadLocation($this->pickupContextStorage->load($context)->pickupLocationId, $context);
    }

    /**
     * The full pickup selection (location + chosen local time + instructions) for
     * the active context, or null when there is no valid pickup location.
     */
    public function resolveSelection(SalesChannelContext $context): ?PickupSelection
    {
        $stored = $this->pickupContextStorage->load($context);
        $pickupLocation = $this->loadLocation($stored->pickupLocationId, $context);

        if ($pickupLocation === null) {
            return null;
        }

        return new PickupSelection(
            $pickupLocation,
            $this->parsePickupTime($stored->pickupTime),
            $stored->comment,
        );
    }

    private function loadLocation(?string $pickupLocationId, SalesChannelContext $context): ?PickupLocationEntity
    {
        if ($pickupLocationId === null) {
            return null;
        }

        // Load the schedule so pickup-time validation can run against it.
        $criteria = $this->createPickupLocationCriteria($pickupLocationId, $context->getSalesChannelId());
        $criteria->addAssociation('openingHoursSchedule');
        $criteria->addAssociation('specialHours');

        $pickupLocation = $this->kmhPickupLocationRepository
            ->search($criteria, $context->getContext())
            ->first();

        return $pickupLocation instanceof PickupLocationEntity ? $pickupLocation : null;
    }

    private function parsePickupTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function createPickupLocationCriteria(string $pickupLocationId, string $salesChannelId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('id', $pickupLocationId),
            new EqualsFilter('active', true),
            new EqualsFilter('salesChannels.id', $salesChannelId),
        ]));
        $criteria->setLimit(1);

        return $criteria;
    }
}
