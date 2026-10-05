<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Flow\Storer;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Aware\PickupLocationAware;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Event\FlowEventAware;

/**
 * Persists and restores the pickup location for Flow Builder. Stores only the id
 * (so delayed flows stay small) and lazily reloads the entity on demand.
 *
 * Auto-tagged `flow.storer` via Shopware's autoconfiguration of FlowStorer
 * subclasses — no explicit service definition needed.
 */
class PickupLocationFlowStorer extends FlowStorer
{
    public function __construct(
        private readonly EntityRepository $kmhPickupLocationRepository
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function store(FlowEventAware $event, array $stored): array
    {
        if (!$event instanceof PickupLocationAware || isset($stored[PickupLocationAware::PICKUP_LOCATION_ID])) {
            return $stored;
        }

        $stored[PickupLocationAware::PICKUP_LOCATION_ID] = $event->getPickupLocationId();

        return $stored;
    }

    public function restore(StorableFlow $storable): void
    {
        if (!$storable->hasStore(PickupLocationAware::PICKUP_LOCATION_ID)) {
            return;
        }

        $storable->setData(
            PickupLocationAware::PICKUP_LOCATION_ID,
            $storable->getStore(PickupLocationAware::PICKUP_LOCATION_ID)
        );

        $storable->lazy(PickupLocationAware::PICKUP_LOCATION, $this->lazyLoad(...));
    }

    private function lazyLoad(StorableFlow $storableFlow): ?PickupLocationEntity
    {
        $id = $storableFlow->getStore(PickupLocationAware::PICKUP_LOCATION_ID);

        if (!\is_string($id)) {
            return null;
        }

        $entity = $this->kmhPickupLocationRepository
            ->search(new Criteria([$id]), $storableFlow->getContext())
            ->first();

        return $entity instanceof PickupLocationEntity ? $entity : null;
    }
}
