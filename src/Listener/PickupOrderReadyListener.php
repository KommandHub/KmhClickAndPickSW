<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Listener;

use Kommandhub\ClickAndPickSW\Entity\Order\Aggregated\OrderDelivery\OrderDeliveryStates;
use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Event\PickupOrderReadyEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Fires the {@see PickupOrderReadyEvent} flow trigger when a pickup order's
 * delivery enters the "ready" state. The pickup location comes from the order's
 * OneToOne pickup record (`order.kmhPickupLocation`), the single source
 * of truth — no custom field.
 *
 * The delivery state-change event fires twice (leave + enter) under one name, so
 * it is filtered to the enter side of the "ready" state.
 */
readonly class PickupOrderReadyListener
{
    public function __construct(
        private EntityRepository $orderDeliveryRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsEventListener(event: 'state_machine.order_delivery.state_changed')]
    public function onOrderDeliveryStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if ($event->getNextState()->getTechnicalName() !== OrderDeliveryStates::STATE_READY_FOR_PICKUP) {
            return;
        }

        $context = $event->getContext();

        $order = $this->loadOrder($event->getTransition()->getEntityId(), $context);

        if ($order === null) {
            return;
        }

        $pickupRecord = $this->resolvePickupRecord($order);

        if ($pickupRecord === null || $pickupRecord->getPickupLocation() === null) {
            // Not a pickup order, or the pickup location was deleted — nothing to
            // notify (the location's own address is the mail recipient).
            return;
        }

        $this->eventDispatcher->dispatch(
            new PickupOrderReadyEvent($order, $pickupRecord->getPickupLocation(), $context, $pickupRecord),
            PickupOrderReadyEvent::EVENT_NAME
        );
    }

    private function loadOrder(string $orderDeliveryId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderDeliveryId]);
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('order.kmhPickupLocation.pickupLocation');

        $delivery = $this->orderDeliveryRepository->search($criteria, $context)->getEntities()->first();

        return $delivery instanceof OrderDeliveryEntity ? $delivery->getOrder() : null;
    }

    private function resolvePickupRecord(OrderEntity $order): ?OrderPickupLocationEntity
    {
        $orderPickup = $order->getExtension('kmhPickupLocation');

        return $orderPickup instanceof OrderPickupLocationEntity ? $orderPickup : null;
    }
}
