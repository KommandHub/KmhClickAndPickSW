<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Checkout\PickupSelection;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Writes the order pickup-location record — the single source of truth for an
 * order's pickup data. Replaces the previous order custom field.
 */
readonly class OrderPickupLocationWriter
{
    public function __construct(
        private EntityRepository $kmhOrderPickupLocationRepository,
    ) {
    }

    /**
     * Persists the record and returns the in-memory entity (location association
     * resolved) so callers — e.g. the flow trigger — can expose it without a
     * re-read.
     */
    public function persist(string $orderId, PickupSelection $selection, Context $context): OrderPickupLocationEntity
    {
        $id = Uuid::randomHex();

        $this->kmhOrderPickupLocationRepository->create([
            [
                'id' => $id,
                'orderId' => $orderId,
                'pickupLocationId' => $selection->pickupLocation->getId(),
                'pickupTime' => $selection->pickupTime,
                'comment' => $selection->comment,
            ],
        ], $context);

        $entity = new OrderPickupLocationEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setOrderId($orderId);
        $entity->setPickupLocationId($selection->pickupLocation->getId());
        $entity->setPickupTime($selection->pickupTime);
        $entity->setComment($selection->comment);
        $entity->setPickupLocation($selection->pickupLocation);

        return $entity;
    }
}
