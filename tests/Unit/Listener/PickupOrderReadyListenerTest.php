<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Listener;

use Kommandhub\ClickAndPickSW\Entity\Order\Aggregated\OrderDelivery\OrderDeliveryStates;
use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Event\PickupOrderReadyEvent;
use Kommandhub\ClickAndPickSW\Listener\PickupOrderReadyListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(PickupOrderReadyListener::class)]
#[UsesClass(PickupOrderReadyEvent::class)]
class PickupOrderReadyListenerTest extends TestCase
{
    private const ORDER_ID = '0123456789abcdef0123456789abcdef';
    private const LOCATION_ID = 'fedcba9876543210fedcba9876543210';
    private const DELIVERY_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityRepository&MockObject $orderRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private PickupOrderReadyListener $listener;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->listener = new PickupOrderReadyListener($this->orderRepository, $this->eventDispatcher);
    }

    public function testDispatchesForPickupOrderEnteringReady(): void
    {
        $location = new PickupLocationEntity();
        $location->setId(self::LOCATION_ID);
        $order = $this->orderWithPickup($location);

        $this->orderRepository->method('search')->willReturn($this->orderResult($order));

        $this->eventDispatcher
            ->expects(static::once())
            ->method('dispatch')
            ->with(
                static::callback(
                    static fn (object $event): bool => $event instanceof PickupOrderReadyEvent
                        && $event->getPickupLocation() === $location
                        && $event->getOrder() === $order
                ),
                PickupOrderReadyEvent::EVENT_NAME
            )
            ->willReturnArgument(0);

        $this->listener->onOrderDeliveryStateChanged($this->readyEnterEvent());
    }

    public function testDoesNotDispatchForNonPickupOrder(): void
    {
        // Order without the pickup extension → not a pickup order.
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $this->orderRepository->method('search')->willReturn($this->orderResult($order));
        $this->eventDispatcher->expects(static::never())->method('dispatch');

        $this->listener->onOrderDeliveryStateChanged($this->readyEnterEvent());
    }

    public function testDoesNotDispatchWhenPickupLocationWasDeleted(): void
    {
        // Pickup record exists but its location was removed (FK set null).
        $order = $this->orderWithPickup(null);

        $this->orderRepository->method('search')->willReturn($this->orderResult($order));
        $this->eventDispatcher->expects(static::never())->method('dispatch');

        $this->listener->onOrderDeliveryStateChanged($this->readyEnterEvent());
    }

    public function testDoesNotDispatchWhenOrderCannotBeResolved(): void
    {
        $this->orderRepository->method('search')->willReturn($this->orderResult(null));
        $this->eventDispatcher->expects(static::never())->method('dispatch');

        $this->listener->onOrderDeliveryStateChanged($this->readyEnterEvent());
    }

    public function testDoesNotDispatchOnLeaveSide(): void
    {
        $this->orderRepository->expects(static::never())->method('search');
        $this->eventDispatcher->expects(static::never())->method('dispatch');

        $this->listener->onOrderDeliveryStateChanged($this->stateChangeEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
            OrderDeliveryStates::STATE_READY_FOR_PICKUP
        ));
    }

    public function testDoesNotDispatchForUnrelatedState(): void
    {
        $this->orderRepository->expects(static::never())->method('search');
        $this->eventDispatcher->expects(static::never())->method('dispatch');

        $this->listener->onOrderDeliveryStateChanged($this->stateChangeEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            'shipped'
        ));
    }

    private function orderWithPickup(?PickupLocationEntity $location): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $pickup = new OrderPickupLocationEntity();
        $pickup->setId('11111111111111111111111111111111');
        $pickup->setOrderId(self::ORDER_ID);
        $pickup->setPickupLocation($location);
        $order->addExtension('kmhPickupLocation', $pickup);

        return $order;
    }

    private function readyEnterEvent(): StateMachineStateChangeEvent&MockObject
    {
        return $this->stateChangeEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderDeliveryStates::STATE_READY_FOR_PICKUP
        );
    }

    private function stateChangeEvent(string $side, string $stateTechnicalName): StateMachineStateChangeEvent&MockObject
    {
        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn($stateTechnicalName);

        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')->willReturn(self::DELIVERY_ID);

        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')->willReturn($side);
        $event->method('getNextState')->willReturn($state);
        $event->method('getTransition')->willReturn($transition);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        return $event;
    }

    private function orderResult(?OrderEntity $order): EntitySearchResult&MockObject
    {
        $collection = new OrderCollection($order !== null ? [$order] : []);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }
}
