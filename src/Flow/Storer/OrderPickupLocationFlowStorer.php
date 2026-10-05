<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Flow\Storer;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Aware\OrderPickupLocationAware;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Event\FlowEventAware;

/**
 * Persists and restores the order pickup record for Flow Builder. Stores only
 * the id (so delayed flows stay small) and lazily reloads the entity — with its
 * pickup location — on demand.
 *
 * Auto-tagged `flow.storer` via Shopware's autoconfiguration of FlowStorer
 * subclasses — no explicit service definition needed.
 */
class OrderPickupLocationFlowStorer extends FlowStorer
{
    public function __construct(
        private readonly EntityRepository $kmhOrderPickupLocationRepository
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function store(FlowEventAware $event, array $stored): array
    {
        if (!$event instanceof OrderPickupLocationAware || isset($stored[OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID])) {
            return $stored;
        }

        $stored[OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID] = $event->getOrderPickupLocationId();

        return $stored;
    }

    public function restore(StorableFlow $storable): void
    {
        if (!$storable->hasStore(OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID)) {
            return;
        }

        $storable->setData(
            OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID,
            $storable->getStore(OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID)
        );

        $storable->lazy(OrderPickupLocationAware::ORDER_PICKUP_LOCATION, $this->lazyLoad(...));
    }

    private function lazyLoad(StorableFlow $storableFlow): ?OrderPickupLocationEntity
    {
        $id = $storableFlow->getStore(OrderPickupLocationAware::ORDER_PICKUP_LOCATION_ID);

        if (!\is_string($id)) {
            return null;
        }

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('pickupLocation');

        $entity = $this->kmhOrderPickupLocationRepository
            ->search($criteria, $storableFlow->getContext())
            ->first();

        return $entity instanceof OrderPickupLocationEntity ? $entity : null;
    }
}
